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
  JOIN categories c ON c.id=p.category_id LEFT JOIN categories sc ON sc.id=p.sub_category_id
  WHERE t.type='sell' AND t.status='final' AND t.location_id=1 AND l.parent_sell_line_id IS NULL
  AND ((t.transaction_date>='2025-01-01' AND t.transaction_date<'2025-11-01') OR (t.transaction_date>='2026-01-01' AND t.transaction_date<'2026-11-01'))";
$y = "YEAR(t.transaction_date)";
q("sealed categories","SELECT id,name FROM categories WHERE name LIKE '%Sealed%' AND deleted_at IS NULL AND parent_id=0");
q("PICO all sealed by format, per year","SELECT c.name cat, $y y, SUM($u) units, SUM(CASE WHEN $wn THEN $u ELSE 0 END) wn_units, ROUND(SUM($u*l.unit_price_inc_tax)) rev, ROUND(SUM(CASE WHEN $wn THEN $u*l.unit_price_inc_tax ELSE 0 END)) wn_rev $base AND c.name LIKE '%Sealed%' GROUP BY cat, y ORDER BY cat, y");
q("PICO sealed vinyl monthly","SELECT DATE_FORMAT(t.transaction_date,'%Y-%m') m, SUM($u) units, SUM(CASE WHEN $wn THEN $u ELSE 0 END) wn_units, ROUND(SUM($u*l.unit_price_inc_tax)) rev, ROUND(SUM(CASE WHEN $wn THEN $u*l.unit_price_inc_tax ELSE 0 END)) wn_rev $base AND p.category_id=55 GROUP BY m ORDER BY m");
q("PICO sealed vinyl by genre, 2025 vs 2026 (in-store units / whatnot units / rev)","SELECT COALESCE(sc.name,'(no genre)') g,
  SUM(CASE WHEN $y=2025 THEN $u ELSE 0 END) u25, SUM(CASE WHEN $y=2026 AND NOT $wn THEN $u ELSE 0 END) u26_store, SUM(CASE WHEN $y=2026 AND $wn THEN $u ELSE 0 END) u26_wn,
  ROUND(SUM(CASE WHEN $y=2025 THEN $u*l.unit_price_inc_tax ELSE 0 END)) r25, ROUND(SUM(CASE WHEN $y=2026 AND NOT $wn THEN $u*l.unit_price_inc_tax ELSE 0 END)) r26_store, ROUND(SUM(CASE WHEN $y=2026 AND $wn THEN $u*l.unit_price_inc_tax ELSE 0 END)) r26_wn
  $base AND p.category_id=55 GROUP BY g ORDER BY u25+u26_store+u26_wn DESC");
