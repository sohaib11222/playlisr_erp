<?php
require 'vendor/autoload.php';
$app = require 'bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
use Illuminate\Support\Facades\DB;
function q($label,$sql,$b=[]){ echo "\n### $label\n"; try{ $rows=DB::select($sql,$b);
  foreach($rows as $r) echo implode(' | ', array_map(function($k,$v){return "$k=".(is_numeric($v)?round($v,2):$v);}, array_keys((array)$r),(array)$r)),"\n";
  printf("(%d rows)\n",count($rows)); } catch(\Throwable $e){ echo "ERR ".substr($e->getMessage(),0,300)."\n"; } }
$ch = "CASE WHEN t.is_whatnot=1 OR t.channel='whatnot' THEN 'whatnot' ELSE COALESCE(t.channel,'in_store') END";
$from = "FROM transaction_sell_lines l JOIN transactions t ON t.id=l.transaction_id JOIN products p ON p.id=l.product_id
  WHERE t.type='sell' AND t.status='final' AND t.transaction_date>='2026-01-01' AND l.parent_sell_line_id IS NULL
  AND p.category_id=55 AND p.sub_category_id=129";
q("channels seen","SELECT $ch chan, COUNT(*) n $from GROUP BY chan");
q("PICO sealed jazz vinyl monthly by channel","SELECT DATE_FORMAT(t.transaction_date,'%Y-%m') m, $ch chan, SUM(l.quantity-COALESCE(l.quantity_returned,0)) units, ROUND(SUM((l.quantity-COALESCE(l.quantity_returned,0))*l.unit_price_inc_tax)) rev $from AND t.location_id=1 GROUP BY m, chan ORDER BY m, chan");
q("ALL locations sealed jazz vinyl whatnot monthly (in case Whatnot is logged elsewhere)","SELECT DATE_FORMAT(t.transaction_date,'%Y-%m') m, t.location_id loc, SUM(l.quantity-COALESCE(l.quantity_returned,0)) units $from AND (t.is_whatnot=1 OR t.channel='whatnot') GROUP BY m, loc ORDER BY m, loc");
q("whatnot sell lines with NO product (statement imports?) Pico, monthly","SELECT DATE_FORMAT(t.transaction_date,'%Y-%m') m, COUNT(DISTINCT t.id) txns, SUM(l.id IS NULL) no_lines FROM transactions t LEFT JOIN transaction_sell_lines l ON l.transaction_id=t.id WHERE t.type='sell' AND t.status='final' AND t.location_id=1 AND t.transaction_date>='2026-01-01' AND (t.is_whatnot=1 OR t.channel='whatnot') GROUP BY m ORDER BY m");
