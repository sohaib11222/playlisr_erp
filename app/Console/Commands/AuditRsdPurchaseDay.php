<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * READ-ONLY — Sarah asked: on the day a given anchor title (e.g. Gracie
 * Abrams / Live From Radio City Music Hall) was recorded as a purchase,
 * were other RSD-grid titles (resources/data/rsd-grid-titles.json — the
 * 846-row AMS RSD 2026 / AMS RSD Black Friday 2024 / Alliance RSD 2025
 * grids) also purchased that day, and if so are any of them still NOT
 * zeroed out?
 *
 * 1. Finds the anchor product by a name search (LIKE, case-insensitive).
 * 2. Looks up every purchase_lines row for it, gets the transaction_date(s).
 * 3. For each such date, pulls every purchase_lines row from ANY
 *    transaction dated that day, joins to products.
 * 4. Matches those purchased products against the RSD grid by exact
 *    UPC/sku, then by fuzzy artist+title token overlap (score >= 0.6).
 * 5. For each match, reports current total qty_available across
 *    locations and whether it's already zeroed.
 *
 * No writes.
 *
 * Usage:
 *   php artisan nivessa:audit-rsd-purchase-day "Abrams Radio City"
 */
class AuditRsdPurchaseDay extends Command
{
    protected $signature = 'nivessa:audit-rsd-purchase-day {terms : Search phrase to find the anchor product (space-separated terms, AND-matched against product name)}';

    protected $description = 'Read-only: find other RSD-grid titles purchased the same day as an anchor title, and whether they are still not zeroed.';

    private function normalize(string $s): string
    {
        $s = strtoupper($s);
        $s = preg_replace('/\(RSD[^)]*\)/', ' ', $s);
        $s = preg_replace('/RECORD STORE DAY/', ' ', $s);
        $s = preg_replace('/[^A-Z0-9 ]/', ' ', $s);
        $s = preg_replace('/\b(THE|A|AN)\b/', ' ', $s);
        $s = preg_replace('/\s+/', ' ', $s);
        return trim($s);
    }

    private function tokens(string $s): array
    {
        $n = $this->normalize($s);
        return $n === '' ? [] : array_unique(explode(' ', $n));
    }

    private function jaccard(array $a, array $b): float
    {
        if (!count($a) || !count($b)) return 0.0;
        $inter = count(array_intersect($a, $b));
        $union = count($a) + count($b) - $inter;
        return $union ? $inter / $union : 0.0;
    }

    public function handle()
    {
        $terms = array_filter(preg_split('/\s+/', trim($this->argument('terms'))));
        $q = DB::table('products')->select('id', 'name', 'sku');
        foreach ($terms as $t) {
            $q->where('name', 'like', '%' . $t . '%');
        }
        $anchors = $q->limit(20)->get();

        if ($anchors->isEmpty()) {
            $this->error('No products matched: ' . implode(' ', $terms));
            return 1;
        }

        $this->info('Anchor product(s):');
        foreach ($anchors as $a) {
            $this->line("  #{$a->id}  sku={$a->sku}  \"{$a->name}\"");
        }
        $this->line('');

        $anchorIds = $anchors->pluck('id')->all();
        $dates = DB::table('purchase_lines as pl')
            ->join('transactions as t', 't.id', '=', 'pl.transaction_id')
            ->whereIn('pl.product_id', $anchorIds)
            ->selectRaw('DATE(t.transaction_date) as d, t.transaction_date, t.id as tx_id, pl.product_id, pl.quantity')
            ->distinct()
            ->get();

        if ($dates->isEmpty()) {
            $this->error('No purchase_lines found for the anchor product(s) — never recorded as a purchase.');
            return 1;
        }

        $this->info('Purchase record(s) for the anchor:');
        foreach ($dates as $d) {
            $this->line("  tx#{$d->tx_id}  product #{$d->product_id}  qty {$d->quantity}  date={$d->transaction_date}");
        }
        $this->line('');

        $uniqueDays = $dates->pluck('d')->unique();

        // Load RSD grid
        $grid = json_decode(file_get_contents(resource_path('data/rsd-grid-titles.json')), true);
        $gridBySku = [];
        $gridTokens = [];
        foreach ($grid as $row) {
            if (!isset($gridBySku[$row['upc']])) $gridBySku[$row['upc']] = $row;
            $gridTokens[] = ['row' => $row, 'tok' => $this->tokens($row['artist'] . ' ' . $row['title'])];
        }

        foreach ($uniqueDays as $day) {
            $this->line(str_repeat('=', 70));
            $this->info("Purchases on {$day} — checking against RSD grid…");

            $dayLines = DB::table('purchase_lines as pl')
                ->join('transactions as t', 't.id', '=', 'pl.transaction_id')
                ->join('products as p', 'p.id', '=', 'pl.product_id')
                ->whereRaw('DATE(t.transaction_date) = ?', [$day])
                ->select('p.id as product_id', 'p.sku', 'p.name', 'pl.quantity', 't.id as tx_id')
                ->get()
                ->unique('product_id');

            $this->line("  {$dayLines->count()} distinct product(s) purchased that day.\n");

            foreach ($dayLines as $pl) {
                $gridMatch = null;
                $matchType = null;
                if (isset($gridBySku[$pl->sku])) {
                    $gridMatch = $gridBySku[$pl->sku];
                    $matchType = 'EXACT';
                } else {
                    $pTok = $this->tokens($pl->name);
                    $best = null;
                    foreach ($gridTokens as $g) {
                        $score = $this->jaccard($pTok, $g['tok']);
                        if ($score >= 0.6 && (!$best || $score > $best['score'])) {
                            $best = ['score' => $score, 'row' => $g['row']];
                        }
                    }
                    if ($best) {
                        $gridMatch = $best['row'];
                        $matchType = 'FUZZY(' . round($best['score'], 2) . ')';
                    }
                }

                if (!$gridMatch) continue;

                $totalQty = DB::table('variation_location_details as vld')
                    ->join('variations as v', 'v.id', '=', 'vld.variation_id')
                    ->where('v.product_id', $pl->product_id)
                    ->sum('vld.qty_available');

                $flag = $totalQty > 0 ? '❌ STILL SHOWING STOCK' : '✅ zeroed';
                $this->line("  [{$matchType}] product #{$pl->product_id}  sku={$pl->sku}  qty_available={$totalQty}  {$flag}");
                $this->line("      erp:   \"{$pl->name}\"");
                $this->line("      grid:  {$gridMatch['artist']} / {$gridMatch['title']}  [{$gridMatch['source']}]");
            }
        }

        $this->line("\n(Read-only audit — no changes made.)");
        return 0;
    }
}
