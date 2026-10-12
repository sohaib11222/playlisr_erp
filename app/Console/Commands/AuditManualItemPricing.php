<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * READ-ONLY — Sarah asked: which manual items (POS lines with no product_id)
 * did employees likely price by hand, by category / sub-category, so we can
 * spot patterns.
 *
 * A manual line counts as "rule-priced" when its name matches an active
 * Manual Item Price Rule label AND its unit price equals that rule's price
 * (that's exactly what picking a rule suggestion in the POS fills in).
 * Everything else is "by hand": either no rule name was used, or a rule
 * name was used but the price was changed.
 *
 * Employee = the sale's created_by (whoever rang it up).
 *
 * No writes.
 *
 * Usage:
 *   php artisan nivessa:audit-manual-item-pricing --days=90
 */
class AuditManualItemPricing extends Command
{
    protected $signature = 'nivessa:audit-manual-item-pricing {--days=90 : Look back this many days}';

    protected $description = 'Read-only: manual POS items priced by hand vs by price rule, by employee and category/sub-category.';

    public function handle()
    {
        $days = max(1, (int) $this->option('days'));
        $since = now()->subDays($days)->startOfDay();

        $rules = DB::table('manual_item_price_rules')->where('is_active', 1)->get(['business_id', 'label', 'price']);
        $ruleMap = [];
        foreach ($rules as $r) {
            $ruleMap[$r->business_id][mb_strtolower(trim($r->label))][] = round((float) $r->price, 2);
        }

        $lines = DB::table('transaction_sell_lines as tsl')
            ->join('transactions as t', 'tsl.transaction_id', '=', 't.id')
            ->leftJoin('users as u', 'u.id', '=', 't.created_by')
            ->leftJoin('business_locations as bl', 'bl.id', '=', 't.location_id')
            ->leftJoin('categories as c', 'c.id', '=', 'tsl.category_id')
            ->leftJoin('categories as sc', 'sc.id', '=', 'tsl.sub_category_id')
            ->where('t.type', 'sell')
            ->where('t.status', 'final')
            ->where('t.transaction_date', '>=', $since)
            ->where(function ($q) {
                $q->whereNull('tsl.product_id')->orWhere('tsl.product_id', 0);
            })
            ->whereNotNull('tsl.product_name')
            ->whereNull('tsl.parent_sell_line_id')
            ->select(
                't.business_id',
                'tsl.product_name',
                'tsl.quantity',
                'tsl.unit_price_before_discount',
                'tsl.unit_price',
                'tsl.unit_price_inc_tax',
                DB::raw("TRIM(CONCAT(COALESCE(u.first_name,''),' ',COALESCE(u.last_name,''))) as employee"),
                DB::raw("COALESCE(bl.name,'?') as location"),
                DB::raw("COALESCE(c.name,'(no category)') as category"),
                DB::raw("COALESCE(sc.name,'(no sub-category)') as sub_category")
            )
            ->get();

        $emp = [];      // employee => stats
        $empCat = [];   // employee|cat|sub => stats
        $cat = [];      // cat|sub => stats + price points
        $loc = [];
        $tot = ['lines' => 0, 'hand' => 0, 'override' => 0, 'units' => 0, 'hand_units' => 0, 'hand_rev' => 0.0];

        foreach ($lines as $l) {
            $qty = (float) $l->quantity;
            $price = round((float) ($l->unit_price_before_discount ?: $l->unit_price), 2);
            $prices = array_unique([$price, round((float) $l->unit_price, 2), round((float) $l->unit_price_inc_tax, 2)]);
            $rulePrices = $ruleMap[$l->business_id][mb_strtolower(trim($l->product_name))] ?? null;
            $ruleName = $rulePrices !== null;
            $rulePriced = $ruleName && count(array_intersect($prices, $rulePrices)) > 0;
            $hand = !$rulePriced;
            $override = $ruleName && !$rulePriced;

            $employee = $l->employee !== '' ? $l->employee : '(unknown)';
            $catKey = $l->category . ' / ' . $l->sub_category;

            $add = function (array &$bucket, string $k) use ($qty, $price, $hand, $override) {
                if (!isset($bucket[$k])) {
                    $bucket[$k] = ['lines' => 0, 'hand' => 0, 'override' => 0, 'units' => 0, 'hand_units' => 0, 'hand_rev' => 0.0, 'pp' => []];
                }
                $bucket[$k]['lines']++;
                $bucket[$k]['units'] += $qty;
                if ($hand) {
                    $bucket[$k]['hand']++;
                    $bucket[$k]['hand_units'] += $qty;
                    $bucket[$k]['hand_rev'] += $qty * $price;
                    $pk = number_format($price, 2);
                    $bucket[$k]['pp'][$pk] = ($bucket[$k]['pp'][$pk] ?? 0) + $qty;
                }
                if ($override) {
                    $bucket[$k]['override']++;
                }
            };
            $add($emp, $employee);
            $add($empCat, $employee . '|' . $catKey);
            $add($cat, $catKey);
            $add($loc, $l->location);
            $tot['lines']++;
            $tot['units'] += $qty;
            if ($hand) {
                $tot['hand']++;
                $tot['hand_units'] += $qty;
                $tot['hand_rev'] += $qty * $price;
            }
            if ($override) {
                $tot['override']++;
            }
        }

        $pct = fn($a, $b) => $b > 0 ? round(100 * $a / $b) . '%' : '-';
        $money = fn($n) => '$' . number_format($n, 2);
        $topPrices = function ($pp, $n = 5) {
            arsort($pp);
            $out = [];
            foreach (array_slice($pp, 0, $n, true) as $p => $q) {
                $out[] = '$' . $p . ' x' . (0 + $q);
            }
            return implode(', ', $out);
        };

        $this->line("MANUAL ITEM PRICING AUDIT — last {$days} days (since {$since->toDateString()})");
        $this->line("Active price rules: " . count($rules));
        $this->line("Manual lines: {$tot['lines']} ({$tot['units']} units). By hand: {$tot['hand']} lines = " . $pct($tot['hand'], $tot['lines'])
            . ", {$tot['hand_units']} units, " . $money($tot['hand_rev']) . ". Of those, rule name used but price changed: {$tot['override']}.");
        $this->line('');

        $this->line('=== BY LOCATION ===');
        uasort($loc, fn($a, $b) => $b['hand'] <=> $a['hand']);
        $this->table(['Location', 'Manual lines', 'By hand', '% by hand', 'Hand units', 'Hand $'],
            array_map(fn($k, $s) => [$k, $s['lines'], $s['hand'], $pct($s['hand'], $s['lines']), $s['hand_units'], $money($s['hand_rev'])], array_keys($loc), $loc));

        $this->line('=== BY EMPLOYEE ===');
        uasort($emp, fn($a, $b) => $b['hand'] <=> $a['hand']);
        $this->table(['Employee', 'Manual lines', 'By hand', '% by hand', 'Rule price changed', 'Hand units', 'Hand $', 'Top hand prices'],
            array_map(fn($k, $s) => [$k, $s['lines'], $s['hand'], $pct($s['hand'], $s['lines']), $s['override'], $s['hand_units'], $money($s['hand_rev']), $topPrices($s['pp'], 4)], array_keys($emp), $emp));

        $this->line('=== BY CATEGORY / SUB-CATEGORY ===');
        uasort($cat, fn($a, $b) => $b['hand'] <=> $a['hand']);
        $this->table(['Category / Sub-category', 'Manual lines', 'By hand', '% by hand', 'Hand units', 'Hand $', 'Most common hand prices'],
            array_map(fn($k, $s) => [$k, $s['lines'], $s['hand'], $pct($s['hand'], $s['lines']), $s['hand_units'], $money($s['hand_rev']), $topPrices($s['pp'])], array_keys($cat), $cat));

        $this->line('=== BY EMPLOYEE x CATEGORY / SUB-CATEGORY (by-hand lines only) ===');
        $rows = array_filter($empCat, fn($s) => $s['hand'] > 0);
        uksort($rows, function ($a, $b) use ($rows) {
            [$ea] = explode('|', $a, 2);
            [$eb] = explode('|', $b, 2);
            return strcmp($ea, $eb) ?: ($rows[$b]['hand'] <=> $rows[$a]['hand']);
        });
        $this->table(['Employee', 'Category / Sub-category', 'Manual lines', 'By hand', '% by hand', 'Hand units', 'Hand $', 'Top hand prices'],
            array_map(function ($k, $s) use ($pct, $money, $topPrices) {
                [$e, $c] = explode('|', $k, 2);
                return [$e, $c, $s['lines'], $s['hand'], $pct($s['hand'], $s['lines']), $s['hand_units'], $money($s['hand_rev']), $topPrices($s['pp'], 4)];
            }, array_keys($rows), $rows));

        return 0;
    }
}
