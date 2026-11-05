<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * 2026-11-05: a Dec 6–7, 2025 import (user "Roldeo") ran ~12 times over the
 * same Pico sealed punk list, creating one product record + 1 unit of opening
 * stock per album per run. The inventory page shows each album 12–24 times and
 * Pico's sealed punk count is inflated by ~500 phantom units.
 *
 * For each SKU that has more than one product from that import holding stock
 * at the location, this keeps the FIRST-created record (lowest id, stock left
 * as is) and sets qty_available to 0 on the others. A SKU group is skipped
 * entirely if any of its products has ever been sold, so nothing a cashier
 * actually rang up is touched. Products are not deleted.
 *
 * Dry-run by default; --commit writes an undo snapshot (admin-action-history,
 * action zero-import-duplicate-stock) and pushes changed products to the site.
 *
 *   php artisan stock:zero-import-dupes
 *   php artisan stock:zero-import-dupes --commit
 */
class ZeroImportDuplicateStock extends Command
{
    protected $signature = 'stock:zero-import-dupes
                            {--location=pico : Business location name}
                            {--category=Vinyl - Sealed : Top-level category name}
                            {--sub=punk : Sub-category name contains}
                            {--from=2025-12-06 : Import created_at from (inclusive)}
                            {--to=2025-12-07 : Import created_at to (inclusive day)}
                            {--user=Roldeo : first_name of the user who ran the import}
                            {--business=1 : business_id}
                            {--commit : Actually zero the duplicates (default: dry-run)}';

    protected $description = 'Zero stock on duplicate product records created by a repeated import, keeping one per SKU. Dry-run by default.';

    public function handle()
    {
        $businessId = (int) $this->option('business');
        $commit = (bool) $this->option('commit');

        $locations = DB::table('business_locations')->where('business_id', $businessId)
            ->whereRaw('LOWER(name) = ?', [strtolower(trim($this->option('location')))])->pluck('id');
        $users = DB::table('users')->where('business_id', $businessId)->where('first_name', trim($this->option('user')))->pluck('id');
        $categoryId = DB::table('categories')->where('business_id', $businessId)->where('name', $this->option('category'))->value('id');
        if ($locations->count() !== 1 || $users->isEmpty() || !$categoryId) {
            $this->error('Need exactly one location (found ' . $locations->count() . '), a matching user (found ' . $users->count() . ') and the category (found ' . ($categoryId ? 1 : 0) . ').');
            return 1;
        }
        $locationId = (int) $locations->first();
        $from = $this->option('from') . ' 00:00:00';
        $to = $this->option('to') . ' 23:59:59';

        $rows = DB::table('products as p')
            ->join('categories as sc', 'sc.id', '=', 'p.sub_category_id')
            ->join('variation_location_details as vld', function ($j) use ($locationId) {
                $j->on('vld.product_id', '=', 'p.id')->where('vld.location_id', '=', $locationId);
            })
            ->where('p.business_id', $businessId)
            ->where('p.category_id', $categoryId)
            ->where('sc.name', 'like', '%' . $this->option('sub') . '%')
            ->whereIn('p.created_by', $users)
            ->whereBetween('p.created_at', [$from, $to])
            ->where('vld.qty_available', '>', 0)
            ->whereNotNull('p.sku')->where('p.sku', '<>', '')
            ->select('p.id as product_id', 'p.name', DB::raw('TRIM(p.sku) as sku'), 'vld.id as vld_id', 'vld.qty_available')
            ->orderBy('p.id')
            ->get();

        $groups = [];
        foreach ($rows as $r) {
            $groups[$r->sku][] = $r;
        }

        $this->line('Mode:     ' . ($commit ? 'COMMIT (zeroing duplicates)' : 'DRY RUN (no changes)'));
        $this->line("Scope:    location #{$locationId}, {$this->option('category')} / *{$this->option('sub')}*, created {$this->option('from')}..{$this->option('to')} by {$this->option('user')}");
        $this->line(str_repeat('-', 72));

        $toZero = [];
        $skippedSold = [];
        $kept = [];
        foreach ($groups as $sku => $g) {
            if (count($g) < 2) {
                continue;
            }
            $ids = array_map(function ($r) { return $r->product_id; }, $g);
            $everSold = DB::table('transaction_sell_lines')->whereIn('product_id', $ids)->exists();
            if ($everSold) {
                $skippedSold[] = [$sku, $g[0]->name, count($g)];
                continue;
            }
            $keep = array_shift($g); // lowest id = first import run
            $kept[] = [$sku, $keep, count($g), array_sum(array_map(function ($r) { return (float) $r->qty_available; }, $g))];
            foreach ($g as $r) {
                $toZero[] = $r;
            }
        }

        if ($commit && !empty($toZero)) {
            $snapshotRows = [];
            DB::beginTransaction();
            try {
                foreach ($toZero as $r) {
                    $snapshotRows[] = ['id' => $r->vld_id, 'qty_available' => (float) $r->qty_available];
                    DB::table('variation_location_details')->where('id', $r->vld_id)
                        ->update(['qty_available' => 0, 'updated_at' => now()]);
                }
                DB::commit();
            } catch (\Throwable $e) {
                DB::rollBack();
                $this->error('Failed: ' . $e->getMessage());
                return 1;
            }
            $snapshotKey = 'zero-import-duplicate-stock-' . now()->format('Y-m-d_His');
            Storage::disk('local')->put("admin-snapshots/{$snapshotKey}.json", json_encode([
                'timestamp'   => now()->toDateTimeString(),
                'action'      => 'zero-import-duplicate-stock',
                'business_id' => $businessId,
                'location_id' => $locationId,
                'rows'        => $snapshotRows,
            ], JSON_PRETTY_PRINT));
            $this->line("Snapshot: {$snapshotKey} — undo at /admin/admin-action-history.");

            try {
                $notifier = new \App\Services\NivessaStockNotifier();
                $pids = array_values(array_unique(array_map(function ($r) { return (int) $r->product_id; }, $toZero)));
                foreach (array_chunk($pids, 100) as $chunk) {
                    $notifier->push($chunk);
                }
                $this->line('Pushed ' . count($pids) . ' product(s) to the website.');
            } catch (\Throwable $pushEx) {
                $this->error('Website push failed: ' . $pushEx->getMessage());
            }
        }

        $units = array_sum(array_map(function ($r) { return (float) $r->qty_available; }, $toZero));
        $this->line('Albums with duplicates:      ' . count($kept));
        $this->line(($commit ? 'Records zeroed:              ' : 'Records that would be zeroed: ') . count($toZero) . " ({$units} units)");
        $this->line('Skipped (a copy has sold):   ' . count($skippedSold));
        $this->line(str_repeat('-', 72));
        $this->line('KEEP (first record, stock unchanged) | duplicates zeroed:');
        foreach ($kept as [$sku, $keep, $n, $u]) {
            $this->line(sprintf('  #%-6d keeps %s | zero %d records (%s units) | %s [%s]',
                $keep->product_id, rtrim(rtrim(number_format((float) $keep->qty_available, 2, '.', ''), '0'), '.'), $n, $u, mb_strimwidth($keep->name, 0, 50, '…'), $sku));
        }
        if ($skippedSold) {
            $this->line('SKIPPED (has sales, left alone):');
            foreach ($skippedSold as [$sku, $name, $n]) {
                $this->line("  {$name} [{$sku}] — {$n} records");
            }
        }
        $this->info($commit ? 'COMMIT complete.' : 'DRY RUN complete — nothing was written. Re-run with commit ticked to apply.');
        return 0;
    }
}
