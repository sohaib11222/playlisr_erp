<?php
require 'vendor/autoload.php';
$app = require 'bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
use Illuminate\Support\Facades\DB;
function q($label,$sql,$b=[]){ echo "\n### $label\n"; try{ $rows=DB::select($sql,$b);
  foreach($rows as $r) echo implode(' | ', array_map(function($k,$v){return "$k=".(is_numeric($v)?round($v,2):$v);}, array_keys((array)$r),(array)$r)),"\n";
  printf("(%d rows)\n",count($rows)); } catch(\Throwable $e){ echo "ERR ".substr($e->getMessage(),0,300)."\n"; } }
q("new wave subcats","SELECT c.id cid,c.name cat,sc.id sid,sc.name sub FROM categories sc JOIN categories c ON c.id=sc.parent_id WHERE (sc.name LIKE '%new wave%' OR sc.name LIKE '%newwave%' OR sc.name LIKE '%post-punk%' OR sc.name LIKE '%post punk%') AND sc.deleted_at IS NULL");
$sub = DB::table('categories')->where('parent_id',55)->whereNull('deleted_at')->where('name','like','%new wave%')->pluck('id')->all();
echo "sealed vinyl new wave sub ids: ".implode(',',$sub)."\n";
$ids = implode(',', $sub ?: [0]);
$u = "(l.quantity-COALESCE(l.quantity_returned,0))";
$wn = "(t.is_whatnot=1 OR t.channel='whatnot')";
$base = "FROM transaction_sell_lines l JOIN transactions t ON t.id=l.transaction_id JOIN products p ON p.id=l.product_id
  WHERE t.type='sell' AND t.status='final' AND t.location_id=1 AND l.parent_sell_line_id IS NULL
  AND ((t.transaction_date>='2025-01-01' AND t.transaction_date<'2025-11-01') OR (t.transaction_date>='2026-01-01'))";
q("PICO sealed new wave vinyl monthly","SELECT DATE_FORMAT(t.transaction_date,'%Y-%m') m, SUM($u) units, SUM(CASE WHEN $wn THEN $u ELSE 0 END) whatnot, ROUND(SUM($u*l.unit_price_inc_tax)) rev, ROUND(SUM(CASE WHEN $wn THEN $u*l.unit_price_inc_tax ELSE 0 END)) whatnot_rev $base AND p.category_id=55 AND p.sub_category_id IN ($ids) GROUP BY m ORDER BY m");
q("top sealed new wave titles at Pico, each year (Jan-Oct)","SELECT YEAR(t.transaction_date) y, LEFT(p.name,60) name, SUM($u) units $base AND t.transaction_date<'2026-11-01' AND p.category_id=55 AND p.sub_category_id IN ($ids) GROUP BY y, p.id HAVING units>=3 ORDER BY y, units DESC");
q("sealed new wave in stock at Pico now","SELECT SUM(vld.qty_available) units, COUNT(DISTINCT vld.product_id) titles FROM variation_location_details vld JOIN products p ON p.id=vld.product_id WHERE vld.location_id=1 AND vld.qty_available>0 AND p.category_id=55 AND p.sub_category_id IN ($ids)");
