<?php
// Follow-up (Sarah 10/30): Michigan State packs -> new Trading Card Packs > College Packs;
// Topps Chrome 2026 Mystery Pack -> MLB Packs (#524).
require __DIR__ . '/../../vendor/autoload.php';
$app = require __DIR__ . '/../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
use Illuminate\Support\Facades\DB;

$col = DB::table('categories')->where('business_id', 1)->where('parent_id', 481)->whereNull('deleted_at')->where('name', 'College Packs')->first();
$colId = $col ? (int) $col->id : (int) App\Category::create(['business_id' => 1, 'name' => 'College Packs', 'category_type' => 'product', 'parent_id' => 481, 'created_by' => 1])->id;
echo "College Packs #$colId" . ($col ? " (existed)" : " (created)") . "\n";

$a = DB::table('products')->where('business_id', 1)->whereIn('id', [141058, 141059, 141060])->where('category_id', 481)->whereNull('sub_category_id')
    ->update(['sub_category_id' => $colId, 'updated_at' => now()]);
$b = DB::table('products')->where('business_id', 1)->where('id', 159367)->where('category_id', 481)->whereNull('sub_category_id')
    ->update(['sub_category_id' => 524, 'updated_at' => now()]);
echo "moved michigan=$a topps=$b\n";

foreach (DB::table('categories')->where('parent_id', 481)->whereNull('deleted_at')->get(['id', 'name']) as $s) {
    echo "  sub #{$s->id} {$s->name}: " . DB::table('products')->where('category_id', 481)->where('sub_category_id', $s->id)->count() . "\n";
}
echo "  top-level only: " . DB::table('products')->where('category_id', 481)->whereNull('sub_category_id')->count() . "\n";
