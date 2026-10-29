<?php
// Follow-up: Topps Chrome Mystery Pack back to top-level Trading Card Packs; inspect/retire Trading Cards > Packs (#450).
require __DIR__ . '/../../vendor/autoload.php';
$app = require __DIR__ . '/../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
use Illuminate\Support\Facades\DB;

$n = DB::table('products')->where('id', 159367)->where('category_id', 481)->where('sub_category_id', 525)->update(['sub_category_id' => null, 'updated_at' => now()]);
echo "topps moved=$n\n";
$c = DB::table('categories')->where('id', 450)->first();
echo "cat450 " . json_encode($c) . "\n";
$parent = $c ? DB::table('categories')->where('id', $c->parent_id)->first(['id', 'name', 'parent_id', 'deleted_at']) : null;
echo "parent " . json_encode($parent) . "\n";
$cnt = DB::table('products')->where('sub_category_id', 450)->orWhere('category_id', 450)->count();
echo "products_in_450=$cnt\n";
if ($c && !$c->deleted_at && $cnt === 0) { DB::table('categories')->where('id', 450)->update(['deleted_at' => now()]); echo "RETIRED 450\n"; }
foreach (DB::table('categories')->where('parent_id', 481)->whereNull('deleted_at')->get(['id', 'name']) as $s) {
    echo "  sub #{$s->id} {$s->name}: " . DB::table('products')->where('category_id', 481)->where('sub_category_id', $s->id)->count() . "\n";
}
echo "  top-level only: " . DB::table('products')->where('category_id', 481)->whereNull('sub_category_id')->count() . "\n";
