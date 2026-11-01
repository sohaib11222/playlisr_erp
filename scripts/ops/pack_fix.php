<?php
// Read-only (Sarah 10/31): which genres would sell faster with a small price cut?
// Per genre x category: 6-month sales, stock on hand and its sticker prices, and for items received
// Mar-Jul 2026 the share sold within 90 days by price band relative to the genre's median sold price.
require __DIR__ . '/../../vendor/autoload.php';
$app = require __DIR__ . '/../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
use Illuminate\Support\Facades\DB;
ini_set('memory_limit', '1G');
$biz = 1; $now = now();

// 1) Sold, last 6 months (same joins as the home FSG panel).
$sold = DB::table('transaction_sell_lines as tsl')
    ->join('transactions as sale', 'sale.id', '=', 'tsl.transaction_id')
    ->join('products as p', 'p.id', '=', 'tsl.product_id')
    ->leftJoin('categories as sc', 'sc.id', '=', 'p.sub_category_id')
    ->leftJoin('categories as c', 'c.id', '=', 'p.category_id')
    ->where('sale.business_id', $biz)->where('sale.type', 'sell')->where('sale.status', 'final')->whereNull('sale.import_source')
    ->where('sale.transaction_date', '>=', $now->copy()->subMonths(6))
    ->where('tsl.unit_price_inc_tax', '>', 0)->where('tsl.unit_price_inc_tax', '<', 500)
    ->selectRaw("COALESCE(NULLIF(sc.name,''),'(none)') g, COALESCE(c.name,'') c, tsl.unit_price_inc_tax pr, tsl.quantity q")
    ->get();
$S = [];
foreach ($sold as $r) { $k = "$r->g|$r->c"; $S[$k]['u'] = ($S[$k]['u'] ?? 0) + $r->q; $S[$k]['p'][] = (float) $r->pr; }
$median = function ($a) { if (!$a) return null; sort($a); $n = count($a); return $n % 2 ? $a[intdiv($n, 2)] : ($a[$n / 2 - 1] + $a[$n / 2]) / 2; };

// 2) Stock on hand + sticker price.
$stock = DB::table('variation_location_details as vld')
    ->join('variations as v', 'v.id', '=', 'vld.variation_id')
    ->join('products as p', 'p.id', '=', 'vld.product_id')
    ->leftJoin('categories as sc', 'sc.id', '=', 'p.sub_category_id')
    ->leftJoin('categories as c', 'c.id', '=', 'p.category_id')
    ->where('p.business_id', $biz)->where('vld.qty_available', '>', 0)->where('v.sell_price_inc_tax', '>', 0)->where('v.sell_price_inc_tax', '<', 500)
    ->selectRaw("COALESCE(NULLIF(sc.name,''),'(none)') g, COALESCE(c.name,'') c, v.sell_price_inc_tax pr, vld.qty_available q")
    ->get();
$K = [];
foreach ($stock as $r) { $k = "$r->g|$r->c"; $K[$k][] = [(float) $r->pr, (float) $r->q]; }

// 3) Cohort: purchase lines received Mar 1 - Jul 31 2026; sold within 90 days?
$coh = DB::table('purchase_lines as pl')
    ->join('transactions as pt', 'pt.id', '=', 'pl.transaction_id')
    ->join('variations as v', 'v.id', '=', 'pl.variation_id')
    ->join('products as p', 'p.id', '=', 'pl.product_id')
    ->leftJoin('categories as sc', 'sc.id', '=', 'p.sub_category_id')
    ->leftJoin('categories as c', 'c.id', '=', 'p.category_id')
    ->where('pt.business_id', $biz)->whereBetween('pt.transaction_date', ['2026-03-01', '2026-07-31 23:59:59'])
    ->where('v.sell_price_inc_tax', '>', 0)->where('v.sell_price_inc_tax', '<', 500)
    ->selectRaw("pl.id, COALESCE(NULLIF(sc.name,''),'(none)') g, COALESCE(c.name,'') c, v.sell_price_inc_tax pr, pl.quantity q,
        (SELECT COALESCE(SUM(tslp.quantity),0) FROM transaction_sell_lines_purchase_lines tslp
           JOIN transaction_sell_lines tsl ON tsl.id = tslp.sell_line_id JOIN transactions s ON s.id = tsl.transaction_id
          WHERE tslp.purchase_line_id = pl.id AND s.type='sell' AND s.status='final' AND s.transaction_date <= DATE_ADD(pt.transaction_date, INTERVAL 90 DAY)) sold90")
    ->get();
$C = [];
foreach ($coh as $r) { $k = "$r->g|$r->c"; $C[$k][] = [(float) $r->pr, (float) $r->q, min((float) $r->sold90, (float) $r->q)]; }

$out = [];
foreach ($S as $k => $s) {
    if ($s['u'] < 25 || empty($C[$k]) || empty($K[$k])) continue;
    $med = $median($s['p']);
    $band = function ($pr) use ($med) { return $pr <= $med * 1.0001 ? 'at/below' : ($pr <= $med * 1.5 ? '1-1.5x' : '>1.5x'); };
    $b = [];
    foreach ($C[$k] as [$pr, $q, $s90]) { $x = $band($pr); $b[$x]['q'] = ($b[$x]['q'] ?? 0) + $q; $b[$x]['s'] = ($b[$x]['s'] ?? 0) + $s90; }
    $rate = function ($x) use ($b) { return !empty($b[$x]['q']) && $b[$x]['q'] >= 15 ? $b[$x]['s'] / $b[$x]['q'] : null; };
    $st = []; $stQ = 0; $stP = [];
    foreach ($K[$k] as [$pr, $q]) { $x = $band($pr); $st[$x] = ($st[$x] ?? 0) + $q; $stQ += $q; $stP[] = $pr; }
    $rLow = $rate('at/below'); $rMid = $rate('1-1.5x'); $rHi = $rate('>1.5x');
    $extra = 0;
    if ($rLow !== null && $rMid !== null && $rLow > $rMid) $extra += ($st['1-1.5x'] ?? 0) * ($rLow - $rMid);
    if ($rLow !== null && $rHi !== null && $rLow > $rHi) $extra += ($st['>1.5x'] ?? 0) * ($rLow - $rHi);
    $out[] = ['k' => $k, 'sold6m' => (int) $s['u'], 'med_sold' => round($med, 2), 'stock' => (int) $stQ, 'med_sticker' => round($median($stP), 2),
        'months_supply' => round($stQ / max(1, $s['u'] / 6), 1), 'st_mid' => (int) ($st['1-1.5x'] ?? 0), 'st_hi' => (int) ($st['>1.5x'] ?? 0),
        'r_low' => $rLow, 'r_mid' => $rMid, 'r_hi' => $rHi, 'n_low' => (int) ($b['at/below']['q'] ?? 0), 'n_mid' => (int) ($b['1-1.5x']['q'] ?? 0), 'n_hi' => (int) ($b['>1.5x']['q'] ?? 0),
        'extra90' => round($extra)];
}
usort($out, function ($a, $b) { return $b['extra90'] <=> $a['extra90']; });
$pct = function ($x) { return $x === null ? '  -  ' : str_pad(round($x * 100) . '%', 5, ' ', STR_PAD_LEFT); };
echo "genre|category | sold6m med\$ | stock medSticker months | stock@1-1.5x stock@>1.5x | 90d sell-thru at/below(n) 1-1.5x(n) >1.5x(n) | extra units/90d\n";
foreach (array_slice($out, 0, 40) as $o) {
    printf("%-45s | %5d %6.2f | %6d %6.2f %6.1f | %6d %6d | %s(%d) %s(%d) %s(%d) | %d\n", $o['k'], $o['sold6m'], $o['med_sold'], $o['stock'], $o['med_sticker'], $o['months_supply'],
        $o['st_mid'], $o['st_hi'], $pct($o['r_low']), $o['n_low'], $pct($o['r_mid']), $o['n_mid'], $pct($o['r_hi']), $o['n_hi'], $o['extra90']);
}
