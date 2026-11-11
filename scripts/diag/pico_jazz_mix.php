<?php
require 'vendor/autoload.php';
$app = require 'bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
use Illuminate\Support\Facades\DB;
function q($label,$sql,$b=[]){ echo "\n### $label\n"; try{ $rows=DB::select($sql,$b);
  foreach($rows as $r) echo implode(' | ', array_map(function($k,$v){return "$k=".(is_numeric($v)?round($v,2):$v);}, array_keys((array)$r),(array)$r)),"\n";
  printf("(%d rows)\n",count($rows)); } catch(\Throwable $e){ echo "ERR ".substr($e->getMessage(),0,300)."\n"; } }
$u = "(l.quantity-COALESCE(l.quantity_returned,0))";
$wn = "(t.is_whatnot=1 OR t.channel='whatnot')";
$base = "FROM transaction_sell_lines l JOIN transactions t ON t.id=l.transaction_id JOIN products p ON p.id=l.product_id
  WHERE t.type='sell' AND t.status='final' AND t.location_id=1 AND l.parent_sell_line_id IS NULL
  AND ((t.transaction_date>='2025-01-01' AND t.transaction_date<'2025-11-01') OR (t.transaction_date>='2026-01-01' AND t.transaction_date<'2026-11-01'))";
q("PICO sealed jazz vinyl monthly, both years","SELECT DATE_FORMAT(t.transaction_date,'%Y-%m') m, SUM($u) units, SUM(CASE WHEN $wn THEN $u ELSE 0 END) whatnot, ROUND(SUM($u*l.unit_price_inc_tax)) rev $base AND p.category_id=55 AND p.sub_category_id=129 GROUP BY m ORDER BY m");
q("PICO used jazz vinyl monthly, both years (context)","SELECT DATE_FORMAT(t.transaction_date,'%Y-%m') m, SUM($u) units, ROUND(SUM($u*l.unit_price_inc_tax)) rev $base AND p.category_id=15 AND p.sub_category_id=121 GROUP BY m ORDER BY m");
q("PICO all sealed vinyl monthly units (context)","SELECT DATE_FORMAT(t.transaction_date,'%Y-%m') m, SUM($u) units $base AND p.category_id=55 GROUP BY m ORDER BY m");
q("PICO total sale txns per month (store activity sanity)","SELECT DATE_FORMAT(transaction_date,'%Y-%m') m, COUNT(*) txns, SUM(is_whatnot=1 OR channel='whatnot') whatnot_txns FROM transactions t WHERE type='sell' AND status='final' AND location_id=1 AND ((transaction_date>='2025-01-01' AND transaction_date<'2025-11-01') OR (transaction_date>='2026-01-01' AND transaction_date<'2026-11-01')) GROUP BY m ORDER BY m");
q("sealed jazz vinyl in stock at Pico now","SELECT SUM(vld.qty_available) units, COUNT(DISTINCT vld.product_id) titles FROM variation_location_details vld JOIN products p ON p.id=vld.product_id WHERE vld.location_id=1 AND vld.qty_available>0 AND p.category_id=55 AND p.sub_category_id=129");
