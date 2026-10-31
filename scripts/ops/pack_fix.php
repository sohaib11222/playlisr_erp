<?php
// Read-only: where comic book costs live and how many are under $0.75.
require __DIR__ . '/../../vendor/autoload.php';
$app = require __DIR__ . '/../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
use Illuminate\Support\Facades\DB;

$biz = 1;
$cats = DB::table('categories')->where('business_id', $biz)->whereNull('deleted_at')->get(['id', 'name', 'parent_id']);
$name = []; foreach ($cats as $c) $name[(int) $c->id] = trim($c->name);
echo "Comic-ish categories:\n";
$ids = [];
foreach ($cats as $c) if (preg_match('/comic|manga|graphic novel/i', $c->name)) {
    $col = $c->parent_id ? 'sub_category_id' : 'category_id';
    $n = DB::table('products')->where('business_id', $biz)->where($col, $c->id)->count();
    echo "  #{$c->id} " . ($c->parent_id ? ($name[(int) $c->parent_id] ?? '?') . ' > ' : '') . "{$c->name} ($col): $n\n";
    $ids[] = [$col, (int) $c->id];
    if (!$c->parent_id) foreach ($cats as $s) if ((int) $s->parent_id === (int) $c->id) echo "      sub #{$s->id} {$s->name}: " . DB::table('products')->where('sub_category_id', $s->id)->count() . "\n";
}
$pq = function () use ($biz, $ids) {
    return DB::table('products')->where('products.business_id', $biz)->where(function ($q) use ($ids) { foreach ($ids as [$col, $id]) $q->orWhere("products.$col", $id); });
};
$pids = $pq()->pluck('id');
echo "comic products: " . count($pids) . "\n";
$v = DB::table('variations')->whereIn('product_id', $pids)->whereNull('deleted_at');
echo "variations: " . (clone $v)->count() . "\n";
foreach (['null' => fn($q) => $q->whereNull('default_purchase_price'), '0' => fn($q) => $q->where('default_purchase_price', 0),
          '(0,0.75)' => fn($q) => $q->where('default_purchase_price', '>', 0)->where('default_purchase_price', '<', 0.75),
          '>=0.75' => fn($q) => $q->where('default_purchase_price', '>=', 0.75)] as $k => $f)
    echo "  default_purchase_price $k: " . $f(clone $v)->count() . "\n";
echo "  sample low: " . json_encode((clone $v)->where('default_purchase_price', '<', 0.75)->select('default_purchase_price', DB::raw('count(*) n'))->groupBy('default_purchase_price')->orderByDesc('n')->limit(10)->get()) . "\n";
$pl = DB::table('purchase_lines')->whereIn('product_id', $pids);
echo "purchase_lines: " . (clone $pl)->count() . ", under 0.75: " . (clone $pl)->where('purchase_price', '<', 0.75)->count()
   . " (of which still in stock: " . (clone $pl)->where('purchase_price', '<', 0.75)->whereRaw('quantity - quantity_sold - quantity_adjusted - quantity_returned > 0')->count() . ")\n";
echo "  pl price dist: " . json_encode((clone $pl)->where('purchase_price', '<', 0.75)->select('purchase_price', DB::raw('count(*) n'))->groupBy('purchase_price')->orderByDesc('n')->limit(10)->get()) . "\n";
// how do they arrive? added_via + buy offers
echo "added_via: " . json_encode($pq()->select('added_via', DB::raw('count(*) n'))->groupBy('added_via')->get()) . "\n";
// comic-looking items in other categories
$other = DB::table('products')->where('business_id', $biz)->where(function ($q) { $q->where('name', 'like', '%comic%')->orWhere('name', 'like', '% #%'); })
    ->whereNotIn('id', $pids)->select('category_id', DB::raw('count(*) n'))->groupBy('category_id')->orderByDesc('n')->limit(8)->get();
foreach ($other as $o) echo "  outside: " . ($name[(int) $o->category_id] ?? '(none)') . " {$o->n}\n";
