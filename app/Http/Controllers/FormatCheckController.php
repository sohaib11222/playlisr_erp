<?php

namespace App\Http\Controllers;

use App\Http\Controllers\GenreFixController as G;
use Illuminate\Http\Request;

/**
 * Format Check (Sarah, 2026-10-23): CDs filed as vinyl and vinyl filed as CDs.
 * The website's CD / Vinyl label comes only from the ERP category, so a wrong
 * category means customers buy the wrong format (Ben Folds Five CD sold as an
 * LP, Madonna CD tagged USED LP on the orders page).
 *
 * Two ways we know the real format:
 *   discogs_listing - Discogs inventory imports keep the listing's own format
 *                     in the description ("Format: CD, Comp, SRC"). That is the
 *                     copy we have, so these are fixed with one click.
 *   release         - the genre check (genres:discogs-check) reads every
 *                     Discogs-linked record's release and saves its format.
 *                     When the release's barcode is our SKU it's the same
 *                     record, so it goes in the one-click list too; otherwise
 *                     the link may be a different pressing and it waits for a
 *                     person (Needs a look).
 *
 * A fix moves the record to the same condition in the right format (Vinyl -
 * Used to CD - Used) and keeps its genre. Every apply is one 'format-check'
 * snapshot, undoable at /admin/admin-action-history.
 */
class FormatCheckController extends Controller
{
    const FAMILY_NAMES = ['vinyl' => 'Vinyl', 'cd' => 'CD', 'cassette' => 'Cassette'];

    private function guard()
    {
        if (!auth()->user()->can('product.update')) abort(403, 'Unauthorized action.');
    }

    /** vinyl / cd / cassette from an ERP category name, null for anything else. */
    public static function familyOfCategory($name)
    {
        $n = mb_strtolower((string) $name);
        if (strpos($n, 'cassette') !== false) return 'cassette';
        if (preg_match('/\bcds?\b/', $n)) return 'cd';
        if (strpos($n, 'vinyl') !== false || preg_match('/\b(7|10|12)"|45 rpm|\blps?\b/', $n)) return 'vinyl';
        return null;
    }

    /**
     * vinyl / cd / cassette from a Discogs format string ("CD, Comp, SRC",
     * "2xVinyl, LP, Album", "Cass, Album"). Null when it's none of them or
     * more than one (an LP + CD box set).
     */
    public static function familyOfDiscogs($format)
    {
        $f = mb_strtolower((string) $format);
        $hit = [];
        if (preg_match('/vinyl|\blps?\b|\b(7|10|12)"|\bflexi/', $f)) $hit['vinyl'] = 1;
        if (preg_match('/\b(\d+x)?(cd|cdr|sacd|hdcd|cd-?rom|minidisc)\b/', $f)) $hit['cd'] = 1;
        if (preg_match('/\b(\d+x)?(cass|cassette)\b/', $f)) $hit['cassette'] = 1;
        return count($hit) === 1 ? array_key_first($hit) : null;
    }

    /** Same family from a Discogs release's formats[] (names like Vinyl, CD, Cassette). */
    public static function familyOfRelease(array $formats)
    {
        return self::familyOfDiscogs(implode(', ', array_map(fn($f) => (string) ($f['name'] ?? ''), $formats)));
    }

    /** Top-level music categories with their family and condition, plus where each family+condition lives. */
    private function taxonomy($biz)
    {
        $all = \DB::table('categories')->where('business_id', $biz)->whereNull('deleted_at')->select('id', 'name', 'parent_id')->get();
        $cats = []; // id => [name, family, cond]
        $target = []; // family|cond => id
        foreach ($all->where('parent_id', 0) as $c) {
            $fam = self::familyOfCategory($c->name);
            if (!$fam) continue;
            $n = mb_strtolower($c->name);
            $cond = strpos($n, 'sealed') !== false ? 'sealed' : 'used';
            $cats[(int) $c->id] = ['name' => $c->name, 'family' => $fam, 'cond' => $cond];
            // Only the plain "{Format} - Used/Sealed" categories are targets (not 7", Clearance Vinyl).
            if (preg_match('/^(vinyl|cds?|cassettes?) - (used|sealed)$/i', trim($c->name))) $target[$fam . '|' . $cond] = (int) $c->id;
        }
        $subs = [];
        $sizes = \DB::table('products')->where('business_id', $biz)->where('sub_category_id', '>', 0)
            ->select('sub_category_id', \DB::raw('count(*) n'))->groupBy('sub_category_id')->pluck('n', 'sub_category_id')->all();
        $byCanon = [];
        foreach ($all->where('parent_id', '>', 0) as $c) {
            $subs[(int) $c->id] = ['name' => $c->name, 'parent' => (int) $c->parent_id];
            if (!isset($cats[(int) $c->parent_id])) continue;
            $k = G::canon($c->name);
            $cur = $byCanon[(int) $c->parent_id][$k] ?? null;
            if ($cur === null || ($sizes[$c->id] ?? 0) > ($sizes[$cur] ?? 0)) $byCanon[(int) $c->parent_id][$k] = (int) $c->id;
        }
        return [$cats, $target, $subs, $byCanon];
    }

    /** Proposed fixes: ['fix' => one-click rows, 'look' => needs a person]. */
    private function compute($biz)
    {
        list($cats, $target, $subs, $byCanon) = $this->taxonomy($biz);
        $fix = []; $look = [];

        $propose = function ($p, $real, $why) use ($cats, $target, $subs, $byCanon) {
            $cur = $cats[(int) $p->category_id] ?? null;
            if (!$cur || $cur['family'] === $real) return null;
            $new = $target[$real . '|' . $cur['cond']] ?? null;
            if (!$new) return null;
            $genre = isset($subs[(int) $p->sub_category_id]) ? $subs[(int) $p->sub_category_id]['name'] : '';
            $newSub = $genre !== '' ? G::subFor($byCanon, $new, G::canon($genre)) : null;
            return [
                'id' => (int) $p->id, 'name' => $p->name, 'artist' => (string) $p->artist,
                'old_cat' => (int) $p->category_id, 'old_name' => $cur['name'] . ($genre !== '' ? ' > ' . $genre : ''),
                'new_cat' => $new, 'new_sub' => $newSub,
                'new_name' => $cats[$new]['name'] . ($newSub ? ' > ' . $subs[$newSub]['name'] : ''),
                'real' => self::FAMILY_NAMES[$real], 'why' => $why,
            ];
        };

        $base = fn() => \DB::table('products as p')
            ->where('p.business_id', $biz)->where('p.is_inactive', 0)->whereIn('p.category_id', array_keys($cats))
            ->select('p.id', 'p.name', 'p.artist', 'p.sku', 'p.category_id', 'p.sub_category_id', 'p.product_description');

        // 1) Discogs listings: the description's Format line is the copy we have.
        $seen = [];
        foreach ($base()->where('p.added_via', 'discogs_inventory_import')->cursor() as $p) {
            if (!preg_match('/^Format:\s*(.+)$/mi', (string) $p->product_description, $m)) continue;
            $real = self::familyOfDiscogs($m[1]);
            if (!$real) continue;
            $seen[(int) $p->id] = true;
            if ($r = $propose($p, $real, 'Discogs listing says ' . trim($m[1]))) $fix[] = $r;
        }

        // 2) Release formats saved by the genre check.
        $fmt = \App\Console\Commands\GenreDiscogsCheck::state()['fmt'] ?? [];
        if ($fmt) {
            foreach (array_chunk(array_keys($fmt), 2000) as $ids) {
                foreach ($base()->whereIn('p.id', $ids)->get() as $p) {
                    if (isset($seen[(int) $p->id])) continue;
                    $f = (string) $fmt[$p->id];
                    $real = rtrim($f, '!');
                    if (!isset(self::FAMILY_NAMES[$real])) continue;
                    $sure = substr($f, -1) === '!';
                    $r = $propose($p, $real, $sure ? 'Discogs release with this barcode is a ' . self::FAMILY_NAMES[$real] : 'Linked Discogs release is a ' . self::FAMILY_NAMES[$real] . ' (could be a different pressing)');
                    if (!$r) continue;
                    if ($sure) $fix[] = $r; else $look[] = $r;
                }
            }
        }

        // In-stock first: those are the ones customers can buy right now.
        $stock = [];
        foreach (array_chunk(array_merge(array_column($fix, 'id'), array_column($look, 'id')), 2000) as $ids) {
            $stock += \DB::table('variation_location_details')->whereIn('product_id', $ids)->groupBy('product_id')
                ->pluck(\DB::raw('SUM(qty_available)'), 'product_id')->all();
        }
        $withStock = fn($rows) => array_map(fn($r) => ['stock' => (float) ($stock[$r['id']] ?? 0)] + $r, $rows);
        $fix = $withStock($fix);
        $look = $withStock($look);
        $byStock = fn($a, $b) => $b['stock'] <=> $a['stock'] ?: strcmp($a['name'], $b['name']);
        usort($fix, $byStock);
        usort($look, $byStock);
        return ['fix' => $fix, 'look' => $look];
    }

    public function index(Request $request)
    {
        $this->guard();
        $biz = (int) $request->session()->get('user.business_id');
        $data = $this->compute($biz);
        $s = \App\Console\Commands\GenreDiscogsCheck::state();
        $data['checked'] = count($s['fmt'] ?? []);
        $data['linked'] = \DB::table('products')->where('business_id', $biz)->where('is_inactive', 0)->where('discogs_release_id', '>', 0)->count();
        return view('product.format_check', $data);
    }

    /** Move records to their real format. $rows = [id => [new_cat, new_sub]]. */
    private function write($biz, array $rows, $label)
    {
        $done = [];
        \DB::beginTransaction();
        try {
            foreach (array_chunk(array_keys($rows), 1000) as $ids) {
                foreach (\DB::table('products')->where('business_id', $biz)->whereIn('id', $ids)->select('id', 'category_id', 'sub_category_id')->get() as $p) {
                    list($cat, $sub, $from) = $rows[$p->id];
                    // Skip anything someone moved since the page was loaded.
                    if ((int) $p->category_id !== (int) $from || (int) $p->category_id === (int) $cat) continue;
                    \DB::table('products')->where('id', $p->id)->update(['category_id' => $cat, 'sub_category_id' => $sub ?: null]);
                    $done[] = ['id' => (int) $p->id, 'category_id' => (int) $p->category_id, 'sub_category_id' => $p->sub_category_id ? (int) $p->sub_category_id : null,
                        'new_category_id' => (int) $cat, 'new_sub_category_id' => $sub ? (int) $sub : null];
                }
            }
            if ($done) {
                $ts = now()->format('Y-m-d_His');
                \Storage::disk('local')->put("admin-snapshots/format-check-{$ts}.json", json_encode([
                    'timestamp' => $ts, 'action' => 'format-check', 'user_id' => auth()->id(), 'business_id' => $biz,
                    'source_name' => count($done) . ' record(s) moved to their real format: ' . $label, 'target_name' => 'products.category_id', 'rows' => $done,
                ], JSON_PRETTY_PRINT));
            }
            \DB::commit();
        } catch (\Throwable $e) {
            \DB::rollBack();
            \Log::error('Format check failed: ' . $e->getMessage());
            return null;
        }
        // Website label follows the ERP category; push after the response.
        $ids = array_column($done, 'id');
        if ($ids) {
            app()->terminating(function () use ($ids) {
                foreach (array_chunk($ids, 300) as $chunk) {
                    try { (new \App\Services\NivessaStockNotifier())->pushProductChanged($chunk); } catch (\Throwable $e) { \Log::warning('Format check website push failed: ' . $e->getMessage()); }
                }
            });
        }
        return count($done);
    }

    private function back($msg, $ok = true)
    {
        return redirect('/products/format-check')->with('status', ['success' => $ok ? 1 : 0, 'msg' => $msg]);
    }

    /** list=fix: every one-click row. list=look: only the ticked ids. */
    public function apply(Request $request)
    {
        $this->guard();
        $biz = (int) $request->session()->get('user.business_id');
        $list = $request->input('list') === 'look' ? 'look' : 'fix';
        $only = array_flip(array_map('intval', (array) $request->input('ids', [])));
        $rows = [];
        foreach ($this->compute($biz)[$list] as $r) {
            if ($list === 'look' && !isset($only[$r['id']])) continue;
            $rows[$r['id']] = [$r['new_cat'], $r['new_sub'], $r['old_cat']];
        }
        if (!$rows) return $this->back('Nothing to move.', false);
        $n = $this->write($biz, $rows, $list === 'look' ? 'checked by hand' : 'Fix all');
        return $n === null ? $this->back('Something went wrong, nothing changed.', false)
            : $this->back("Moved {$n} records to their real format. The website updates in a few minutes. Undo any time in Admin Action History.");
    }
}
