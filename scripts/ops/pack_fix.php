<?php
// Read-only: magazine category tree, counts, and product names (for the subcategory cleanup).
require __DIR__ . '/../../vendor/autoload.php';
$app = require __DIR__ . '/../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
use Illuminate\Support\Facades\DB;

$cats = DB::table('categories')->where('business_id', 1)->where('category_type', 'product')->whereNull('deleted_at')->get(['id', 'name', 'parent_id']);
$name = []; foreach ($cats as $c) $name[(int) $c->id] = trim($c->name);
$magTops = $cats->filter(function ($c) { return preg_match('/magazine|playboy|book/i', $c->name); });
echo "Matching categories:\n";
foreach ($magTops as $c) echo "  #{$c->id} " . ($c->parent_id ? ($name[(int) $c->parent_id] ?? '?') . ' > ' : '') . $c->name . "\n";

$tops = $cats->filter(function ($c) { return (int) $c->parent_id === 0 && preg_match('/magazine/i', $c->name); });
foreach ($tops as $t) {
    echo "\n=== #{$t->id} {$t->name} ===\n";
    $groups = DB::table('products')->where('business_id', 1)->where('category_id', $t->id)
        ->select('sub_category_id', DB::raw('count(*) n'))->groupBy('sub_category_id')->orderByDesc('n')->get();
    foreach ($groups as $g) {
        $label = $g->sub_category_id ? ($name[(int) $g->sub_category_id] ?? "#{$g->sub_category_id}") : '(no sub)';
        echo "-- $label: {$g->n}\n";
        $names = DB::table('products')->where('business_id', 1)->where('category_id', $t->id)
            ->where(function ($q) use ($g) { $g->sub_category_id ? $q->where('sub_category_id', $g->sub_category_id) : $q->whereNull('sub_category_id'); })
            ->select('name', DB::raw('count(*) n'))->groupBy('name')->orderByDesc('n')->limit(400)->get();
        foreach ($names as $r) echo "     {$r->n}x {$r->name}\n";
    }
    foreach ($cats as $c) if ((int) $c->parent_id === (int) $t->id && !$groups->contains('sub_category_id', $c->id)) echo "-- {$c->name} (#{$c->id}): 0 items\n";
}
// magazine-ish items filed elsewhere
echo "\n=== magazine-looking items outside Magazines ===\n";
$out = DB::table('products')->where('business_id', 1)
    ->where(function ($q) { foreach (['%magazine%', '%playboy%', '%rolling stone%', '%mad #%', '%vogue%', '%penthouse%', '%national geographic%', '%life magazine%', '%time magazine%', '%spin magazine%'] as $l) $q->orWhere('name', 'like', $l); })
    ->get(['id', 'name', 'category_id', 'sub_category_id']);
foreach ($out as $p) { $cat = $name[(int) $p->category_id] ?? '(none)'; if (preg_match('/magazine/i', $cat)) continue; echo "  #{$p->id} {$p->name} :: $cat" . ($p->sub_category_id ? ' > ' . ($name[(int) $p->sub_category_id] ?? '?') : '') . "\n"; }
