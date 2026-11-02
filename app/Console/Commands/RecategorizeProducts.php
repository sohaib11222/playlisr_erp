<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Move products from one category/subcategory to another, filtered by price
 * and in-stock (Sarah 2026-11-02: Used Electronic Vinyl at $22.00 that's
 * still for sale -> Sealed Electronic Vinyl).
 *
 * Safe by design:
 *   - Dry-run default; --commit to write. Dry run lists every move.
 *   - Category names match case-insensitively; if one isn't found it prints
 *     the close candidates and stops.
 *   - --commit writes an undo log (storage/app/recategorize-undo-*.json);
 *     --undo=<file> puts every product back where it was.
 *   - Moves bump updated_at so the website sync picks them up.
 *
 * Usage:
 *   php artisan nivessa:recategorize --from-parent="Vinyl - Used" --from-sub=Electronic \
 *       --to-parent="Vinyl - Sealed" --to-sub=Electronic --price=22.00 --in-stock
 *   ... --commit
 *   php artisan nivessa:recategorize --undo=storage/app/recategorize-undo-20261102-120000.json
 */
class RecategorizeProducts extends Command
{
    protected $signature = 'nivessa:recategorize
                            {--business=1 : business_id to scope to}
                            {--from-parent= : Current parent category name}
                            {--from-sub= : Current subcategory name (blank = any)}
                            {--to-parent= : Target parent category name}
                            {--to-sub= : Target subcategory name (blank = none)}
                            {--price= : Only items whose selling price (inc tax) equals this}
                            {--artist= : Only items whose artist (or name) contains this}
                            {--in-stock : Only items with stock > 0}
                            {--location= : Store name (e.g. Hollywood): only items stocked there}
                            {--commit : Actually write (default: dry-run)}
                            {--undo= : Undo log file to restore from}
                            {--show=500 : Max rows to print}';

    protected $description = 'Move products between categories (dry-run default, undo log on commit).';

    public function handle()
    {
        if ($this->option('undo')) {
            return $this->undo($this->option('undo'));
        }

        $biz = (int) $this->option('business');
        $cats = DB::table('categories')->where('business_id', $biz)->whereNull('deleted_at')
            ->get(['id', 'name', 'parent_id', 'category_type']);

        $fromParent = $this->findCat($cats, $this->option('from-parent'), 0);
        $toParent = $this->findCat($cats, $this->option('to-parent'), 0);
        if (!$fromParent || !$toParent) return 1;
        $fromSub = $this->option('from-sub') ? $this->findCat($cats, $this->option('from-sub'), $fromParent->id) : null;
        $toSub = $this->option('to-sub') ? $this->findCat($cats, $this->option('to-sub'), $toParent->id) : null;
        if (($this->option('from-sub') && !$fromSub) || ($this->option('to-sub') && !$toSub)) return 1;

        $this->info(sprintf('FROM %s (#%d)%s  ->  TO %s (#%d)%s',
            $fromParent->name, $fromParent->id, $fromSub ? " > {$fromSub->name} (#{$fromSub->id})" : '',
            $toParent->name, $toParent->id, $toSub ? " > {$toSub->name} (#{$toSub->id})" : ''));

        $q = DB::table('products as p')
            ->where('p.business_id', $biz)
            ->where('p.category_id', $fromParent->id);
        if ($fromSub) $q->where('p.sub_category_id', $fromSub->id);

        $price = $this->option('price');
        if ($price !== null && $price !== '') {
            $q->whereExists(function ($w) use ($price) {
                $w->select(DB::raw(1))->from('variations as v')
                    ->whereColumn('v.product_id', 'p.id')->whereNull('v.deleted_at')
                    ->whereRaw('ROUND(v.sell_price_inc_tax, 2) = ?', [round((float) $price, 2)]);
            });
        }

        if ($artist = trim((string) $this->option('artist'))) {
            $q->where(function ($w) use ($artist) {
                $w->where('p.artist', 'like', "%$artist%")->orWhere('p.name', 'like', "%$artist%");
            });
            $this->info("ARTIST contains '$artist'");
        }

        // --location scopes the stock check to one store. Category is per
        // product, so items also stocked at another store move there too:
        // the listing flags them.
        $locId = null;
        if ($this->option('location')) {
            $locs = DB::table('business_locations')->where('business_id', $biz)
                ->where('name', 'like', '%' . $this->option('location') . '%')->get(['id', 'name']);
            if ($locs->count() !== 1) {
                $this->error("Location '{$this->option('location')}' matched " . $locs->count() . ': ' . $locs->pluck('name')->implode(', '));
                return 1;
            }
            $locId = (int) $locs[0]->id;
            $this->info("LOCATION {$locs[0]->name} (#$locId)");
        }
        $stockSub = '(select coalesce(sum(vld.qty_available),0) from variation_location_details vld where vld.product_id = p.id'
            . ($locId ? " and vld.location_id = $locId" : '') . ')';
        $otherSub = '(select coalesce(sum(vld.qty_available),0) from variation_location_details vld where vld.product_id = p.id'
            . ($locId ? " and vld.location_id <> $locId" : ' and 0') . ')';
        if ($this->option('in-stock') || $locId) {
            $q->whereRaw("$stockSub > 0");
        }

        $rows = $q->leftJoin('categories as sc', 'sc.id', '=', 'p.sub_category_id')
            ->select('p.id', 'p.name', 'p.sku', 'p.category_id', 'p.sub_category_id', 'sc.name as sub_name',
                DB::raw("$stockSub as stock"), DB::raw("$otherSub as other_stock"),
                DB::raw('(select max(v.sell_price_inc_tax) from variations v where v.product_id = p.id and v.deleted_at is null) as price'))
            ->orderBy('p.name')->get();

        $this->info('MATCHED ' . $rows->count() . ' product(s)' . ($this->option('commit') ? '' : ' (dry run, nothing written)'));
        foreach ($rows->take((int) $this->option('show')) as $r) {
            $this->line(sprintf('  #%d  %s  | %s | sku %s | $%s | stock %s%s', $r->id, $r->name, $r->sub_name ?: '(no sub)', $r->sku, number_format((float) $r->price, 2), (float) $r->stock,
                (float) $r->other_stock > 0 ? ' | ALSO ' . (float) $r->other_stock . ' at other stores' : ''));
        }

        if (!$this->option('commit') || $rows->isEmpty()) return 0;

        $undo = $rows->map(function ($r) {
            return ['id' => $r->id, 'category_id' => $r->category_id, 'sub_category_id' => $r->sub_category_id];
        })->values()->all();
        $file = storage_path('app/recategorize-undo-' . date('Ymd-His') . '.json');
        file_put_contents($file, json_encode($undo, JSON_PRETTY_PRINT));

        $n = 0;
        foreach ($rows->pluck('id')->chunk(500) as $ids) {
            $n += DB::table('products')->whereIn('id', $ids->all())->update([
                'category_id' => $toParent->id,
                'sub_category_id' => $toSub ? $toSub->id : null,
                'updated_at' => now(),
            ]);
        }
        $this->info("MOVED $n product(s). Undo log: $file");
        return 0;
    }

    private function findCat($cats, $name, $parentId)
    {
        $name = mb_strtolower(trim((string) $name));
        $hit = $cats->first(function ($c) use ($name, $parentId) {
            return (int) $c->parent_id === (int) $parentId && mb_strtolower(trim($c->name)) === $name;
        });
        if ($hit) return $hit;

        $this->error("Category not found: '$name' under parent #$parentId. Candidates:");
        $words = array_filter(preg_split('/[^a-z0-9]+/', $name));
        foreach ($cats as $c) {
            if ($parentId && (int) $c->parent_id !== (int) $parentId) continue;
            if (!$parentId && (int) $c->parent_id !== 0) continue;
            $low = mb_strtolower($c->name);
            foreach ($words as $w) {
                if (strlen($w) > 2 && strpos($low, $w) !== false) { $this->line("  #{$c->id}  {$c->name}"); break; }
            }
        }
        return null;
    }

    private function undo($file)
    {
        $path = file_exists($file) ? $file : base_path($file);
        if (!file_exists($path)) { $this->error("Undo file not found: $file"); return 1; }
        $rows = json_decode(file_get_contents($path), true) ?: [];
        if (!$this->option('commit')) {
            $this->info('Would restore ' . count($rows) . ' product(s). Add --commit to write.');
            return 0;
        }
        foreach ($rows as $r) {
            DB::table('products')->where('id', $r['id'])->update([
                'category_id' => $r['category_id'], 'sub_category_id' => $r['sub_category_id'], 'updated_at' => now(),
            ]);
        }
        $this->info('Restored ' . count($rows) . ' product(s).');
        return 0;
    }
}
