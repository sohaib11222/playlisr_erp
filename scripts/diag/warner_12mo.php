<?php
// READ-ONLY: Warner (WMG) sealed sales + purchases by UPC prefix for two 12-month windows. SELECTs only.
require __DIR__ . '/../../vendor/autoload.php';
$app = require __DIR__ . '/../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
use Illuminate\Support\Facades\DB;
ini_set('memory_limit','2048M'); set_time_limit(0);
$prefixes = ['075678','075679','075596','075597','093624','081227','603497','190295','190296','825646','054391','075992','016861','5054197','5054196','5021732','9397601'];
function norm($s){ $d=preg_replace('/\D/','',(string)$s); if(strlen($d)==13 && $d[0]=='0') $d=substr($d,1); return $d; }
$prods = DB::table('products as p')->leftJoin('variations as v','v.product_id','=','p.id')
  ->leftJoin('categories as c','c.id','=','p.category_id')
  ->select('p.id','p.sku','v.sub_sku','c.name as cat')->get();
$wm = []; $catc=[];
foreach ($prods as $r) {
  foreach ([norm($r->sku), norm($r->sub_sku)] as $u) {
    if (strlen($u) < 11) continue;
    foreach ($prefixes as $px) { if (strpos($u,$px)===0) { $wm[$r->id]=[$px,$r->cat]; break 2; } }
  }
}
echo "WARNER_PRODUCTS ".count($wm)."\n";
$ids = array_keys($wm);
foreach ([['2025-11-03','2026-11-02'],['2025-11-12','2026-11-11']] as [$a,$b]) {
  $units=0; $rev=0; $byCat=[]; $byPx=[]; $loc=[];
  foreach (array_chunk($ids, 2000) as $ch) {
    $rows = DB::table('transaction_sell_lines as l')->join('transactions as t','t.id','=','l.transaction_id')
      ->whereIn('l.product_id',$ch)->where('t.type','sell')->where('t.status','final')
      ->whereDate('t.transaction_date','>=',$a)->whereDate('t.transaction_date','<=',$b)
      ->select('l.product_id','t.location_id',DB::raw('SUM(l.quantity-COALESCE(l.quantity_returned,0)) q'),DB::raw('SUM((l.quantity-COALESCE(l.quantity_returned,0))*l.unit_price) r'),DB::raw('SUM((l.quantity-COALESCE(l.quantity_returned,0))*l.unit_price_inc_tax) ri'))
      ->groupBy('l.product_id','t.location_id')->get();
    foreach ($rows as $x) { $units+=$x->q; $rev+=$x->r; $c=$wm[$x->product_id][1]??'?'; $byCat[$c]=($byCat[$c]??0)+$x->q; $p=$wm[$x->product_id][0]; $byPx[$p]=($byPx[$p]??0)+$x->q; $loc[$x->location_id]=($loc[$x->location_id]??0)+$x->q; $rev_inc=($rev_inc??0)+$x->ri; }
  }
  $pu=0; $pc=0;
  foreach (array_chunk($ids, 2000) as $ch) {
    $p = DB::table('purchase_lines as pl')->join('transactions as t','t.id','=','pl.transaction_id')
      ->whereIn('pl.product_id',$ch)->where('t.type','purchase')
      ->whereDate('t.transaction_date','>=',$a)->whereDate('t.transaction_date','<=',$b)
      ->select(DB::raw('SUM(pl.quantity) q'),DB::raw('SUM(pl.quantity*pl.purchase_price) c'))->first();
    $pu+=$p->q; $pc+=$p->c;
  }
  printf("WINDOW %s..%s units=%d sales_ex_tax=%.0f sales_inc_tax=%.0f purch_units=%d purch_cost=%.0f\n",$a,$b,$units,$rev,$rev_inc??0,$pu,$pc);
  arsort($byCat); echo " by_cat: "; foreach (array_slice($byCat,0,8,true) as $k=>$v) echo "$k=$v; "; echo "\n";
  arsort($byPx); echo " by_prefix: "; foreach ($byPx as $k=>$v) echo "$k=$v; "; echo "\n";
  echo " by_loc: "; foreach ($loc as $k=>$v) echo "$k=$v; "; echo "\n";
  unset($rev_inc);
}
