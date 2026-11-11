<?php
require '/www/playlist.nivessa.com/app/vendor/autoload.php';
$app = require '/www/playlist.nivessa.com/app/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$DB = Illuminate\Support\Facades\DB::class;
echo "== categories named rock-ish ==\n";
foreach ($DB::table('categories as c')->leftJoin('categories as p','p.id','=','c.parent_id')->whereRaw("LOWER(TRIM(c.name)) LIKE '%rock%'")->select('c.id','c.name','c.parent_id','p.name as parent','c.deleted_at','c.category_type','c.business_id')->get() as $r) echo json_encode($r),"\n";
echo "== Hollywood loc ==\n";
$loc = $DB::table('business_locations')->where('name','like','%ollywood%')->value('id'); echo $loc,"\n";
echo "== New vinyl in-stock at HW grouped by cat/subcat ==\n";
foreach ($DB::table('products as p')->join('variation_location_details as v',function($j)use($loc){$j->on('v.product_id','=','p.id')->where('v.location_id','=',$loc);})
 ->leftJoin('categories as c','c.id','=','p.category_id')->leftJoin('categories as sc','sc.id','=','p.sub_category_id')
 ->where('p.is_inactive',0)->where('v.qty_available','>',0)->whereRaw("LOWER(c.name) LIKE '%vinyl%'")->whereRaw("LOWER(c.name) LIKE '%sealed%'")
 ->groupBy('p.category_id','c.name','p.sub_category_id','sc.name','sc.parent_id')->selectRaw('p.category_id,c.name cat,p.sub_category_id,sc.name sub,sc.parent_id sub_parent,COUNT(*) n')->orderBy('sub')->get() as $r) echo json_encode($r),"\n";
echo "== products in the small Rock bin ==\n";
foreach ($DB::table('products as p')->join('variation_location_details as v',function($j)use($loc){$j->on('v.product_id','=','p.id')->where('v.location_id','=',$loc);})
 ->leftJoin('categories as c','c.id','=','p.category_id')->leftJoin('categories as sc','sc.id','=','p.sub_category_id')
 ->where('p.is_inactive',0)->where('v.qty_available','>',0)->whereRaw("LOWER(c.name) LIKE '%vinyl%'")->whereRaw("LOWER(c.name) LIKE '%sealed%'")->whereRaw("LOWER(TRIM(sc.name))='rock'")
 ->select('p.id','p.name','p.sku','p.category_id','c.name as cat','p.sub_category_id','sc.parent_id','p.created_at','p.created_by')->orderBy('p.sub_category_id')->limit(5)->get() as $r) echo json_encode($r),"\n";
