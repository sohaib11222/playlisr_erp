<?php
// Read-only: DVD / Blu-ray category tree and how Blu-rays can be told apart.
require __DIR__ . '/../../vendor/autoload.php';
$app = require __DIR__ . '/../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

$biz = 1;
$cats = DB::table('categories')->where('business_id', $biz)->whereNull('deleted_at')->get(['id', 'name', 'parent_id']);
$name = []; foreach ($cats as $c) $name[(int) $c->id] = trim($c->name);
$vid = $cats->filter(function ($c) use ($name) { return preg_match('/dvd|blu|video|vhs|laser|movie|film|4k/i', $c->name . ' ' . ($name[(int) $c->parent_id] ?? '')); });
echo "Video-ish categories:\n";
foreach ($vid as $c) {
    $n = DB::table('products')->where('business_id', $biz)->where($c->parent_id ? 'sub_category_id' : 'category_id', $c->id)->count();
    echo "  #{$c->id} " . ($c->parent_id ? ($name[(int) $c->parent_id] ?? '?') . ' > ' : '') . "{$c->name}: $n\n";
}
$cols = Schema::getColumnListing('products');
echo "product cols: " . implode(',', $cols) . "\n";
$dvdTops = $vid->filter(function ($c) { return !$c->parent_id; })->pluck('id')->all();
$rows = DB::table('products')->where('business_id', $biz)->whereIn('category_id', $dvdTops)->get();
echo "products in video tops: " . count($rows) . "\n";
$blu = '/blu[\s-]?ray|\bbluray\b|\bblu\b|\bbd\b|4k|uhd/i';
$hits = $rows->filter(function ($p) use ($blu) { return preg_match($blu, $p->name . ' ' . ($p->product_description ?? '')); });
echo "name/desc mentions blu-ray/4k: " . count($hits) . "\n";
$by = []; foreach ($hits as $p) { $k = ($name[(int) $p->category_id] ?? '?') . ($p->sub_category_id ? ' > ' . ($name[(int) $p->sub_category_id] ?? '?') : ''); $by[$k] = ($by[$k] ?? 0) + 1; }
print_r($by);
foreach ($hits->take(60) as $p) echo "  #{$p->id} {$p->name}\n";
// other possible format signals
foreach (['product_custom_field1', 'product_custom_field2', 'product_custom_field3', 'product_custom_field4', 'format', 'media_type'] as $f) if (in_array($f, $cols)) {
    $vals = DB::table('products')->whereIn('category_id', $dvdTops)->select($f, DB::raw('count(*) n'))->groupBy($f)->orderByDesc('n')->limit(15)->get();
    echo "$f: " . json_encode($vals) . "\n";
}
echo "sample names:\n"; foreach ($rows->shuffle()->take(40) as $p) echo "  #{$p->id} {$p->name} :: " . ($name[(int) $p->category_id] ?? '') . ($p->sub_category_id ? ' > ' . ($name[(int) $p->sub_category_id] ?? '') : '') . "\n";
