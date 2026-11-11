<?php
require 'vendor/autoload.php';
$app = require 'bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
use Illuminate\Support\Facades\DB;
ini_set('memory_limit','1024M'); set_time_limit(0);
function q($label,$sql,$b=[]){ echo "\n### $label\n"; try{ $rows=DB::select($sql,$b);
  foreach($rows as $r) echo implode(' | ', array_map(function($k,$v){return "$k=".(is_numeric($v)?round($v,2):$v);}, array_keys((array)$r),(array)$r)),"\n";
  printf("(%d rows)\n",count($rows)); } catch(\Throwable $e){ echo "ERR ".substr($e->getMessage(),0,300)."\n"; } }
q("locations","SELECT id,name FROM business_locations");
q("jazz categories","SELECT c.id cid,c.name cat,sc.id sid,sc.name sub FROM categories sc JOIN categories c ON c.id=sc.parent_id WHERE sc.name LIKE '%jazz%' AND sc.deleted_at IS NULL");
// products in jazz subcats, with first-arrival at Pico (loc 1)
DB::statement("CREATE TEMPORARY TABLE jp AS SELECT p.id pid, p.name, c.name cat, (c.name LIKE '%Sealed%' OR c.name LIKE '%New%') sealed, p.added_via av, p.created_at pc, u.first_name who,
  (SELECT MIN(vld.created_at) FROM variation_location_details vld WHERE vld.product_id=p.id AND vld.location_id=1) arr1,
  (SELECT MAX(v.sell_price_inc_tax) FROM variations v WHERE v.product_id=p.id) px
  FROM products p JOIN categories c ON c.id=p.category_id JOIN categories sc ON sc.id=p.sub_category_id LEFT JOIN users u ON u.id=p.created_by
  WHERE sc.name LIKE '%jazz%' AND (c.name LIKE '%vinyl%' OR c.name LIKE '%record%' OR c.name LIKE '%LP%')");
q("jazz vinyl products by category (all time)","SELECT cat, sealed, COUNT(*) n, SUM(arr1 IS NOT NULL) at_pico FROM jp GROUP BY cat, sealed");
q("PICO weekly arrivals of jazz vinyl: sealed vs used, sealed split by how added","SELECT YEARWEEK(arr1,3) wk, MIN(DATE(arr1)) wk_start,
  SUM(sealed=0) used_n, SUM(sealed=1) sealed_n,
  SUM(sealed=1 AND av='buy_from_customer') sealed_bfc, SUM(sealed=1 AND av='mass_add') sealed_mass, SUM(sealed=1 AND av='discogs_inventory_import') sealed_dimp, SUM(sealed=1 AND av IS NULL) sealed_other,
  ROUND(AVG(CASE WHEN sealed=1 AND px<500 THEN px END),1) sealed_avg_px, SUM(sealed=1 AND px<20) sealed_under20
  FROM jp WHERE arr1>='2025-11-01' GROUP BY wk ORDER BY wk");
q("HOLLYWOOD weekly (control): sealed jazz split","SELECT YEARWEEK(a,3) wk, MIN(DATE(a)) ws, SUM(sealed=0) used_n, SUM(sealed=1) sealed_n, SUM(sealed=1 AND av IN('buy_from_customer','mass_add')) sealed_bfc_or_mass
  FROM (SELECT jp.*, (SELECT MIN(vld.created_at) FROM variation_location_details vld WHERE vld.product_id=jp.pid AND vld.location_id=2) a FROM jp) z WHERE a>='2025-11-01' GROUP BY wk ORDER BY wk");
q("PICO sealed-jazz arrivals that look used (bfc/mass_add or <\$20) by creator, monthly","SELECT DATE_FORMAT(arr1,'%Y-%m') m, who, av, COUNT(*) n FROM jp WHERE sealed=1 AND arr1>='2025-11-01' AND (av IN('buy_from_customer','mass_add') OR px<20) GROUP BY m, who, av ORDER BY m, n DESC");
q("earliest 40 used-looking sealed-jazz Pico items (bfc/mass_add) since 2025-11","SELECT DATE(arr1) d, pid, LEFT(name,55) name, cat, av, who, px FROM jp WHERE sealed=1 AND arr1>='2025-11-01' AND av IN('buy_from_customer','mass_add') ORDER BY arr1 LIMIT 40");
q("PICO jazz vinyl sales monthly sealed vs used","SELECT DATE_FORMAT(t.transaction_date,'%Y-%m') m, jp.sealed, SUM(l.quantity-COALESCE(l.quantity_returned,0)) units, ROUND(AVG(l.unit_price_inc_tax),1) avg_px, SUM(l.unit_price_inc_tax<20) under20
  FROM transaction_sell_lines l JOIN transactions t ON t.id=l.transaction_id JOIN jp ON jp.pid=l.product_id
  WHERE t.type='sell' AND t.status='final' AND t.location_id=1 AND t.transaction_date>='2025-11-01' AND l.parent_sell_line_id IS NULL GROUP BY m, jp.sealed ORDER BY m, jp.sealed");
