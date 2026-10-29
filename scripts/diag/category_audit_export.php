<?php
// READ-ONLY: exports products (id, name, sku, format, added_via, category,
// stock) + product categories + entry-rule category refs for the category audit.
require __DIR__ . '/../../vendor/autoload.php';
$app = require __DIR__ . '/../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
use Illuminate\Support\Facades\DB;
$user = App\User::where('username', 'admin')->first() ?: App\User::orderBy('id')->first();
$biz = (int) $user->business_id;
$cats = DB::table('categories')->where('business_id', $biz)->where('category_type', 'product')->whereNull('deleted_at')
    ->get(['id', 'name', 'parent_id'])->map(function ($c) { return [(int) $c->id, $c->name, (int) $c->parent_id]; })->all();
$stock = DB::table('variation_location_details')->select('product_id', DB::raw('SUM(qty_available) q'))->groupBy('product_id')->pluck('q', 'product_id');
$out = [];
DB::table('products')->where('business_id', $biz)
    ->select('id', 'name', 'sku', 'format', 'added_via', 'category_id', 'sub_category_id', 'is_inactive', 'created_at')
    ->orderBy('id')->chunk(5000, function ($rows) use (&$out, $stock) {
        foreach ($rows as $p) {
            $out[] = [(int) $p->id, (string) $p->name, (string) $p->sku, (string) $p->format, (string) $p->added_via,
                (int) $p->category_id, (int) $p->sub_category_id, (float) ($stock[$p->id] ?? 0), (int) $p->is_inactive, substr((string) $p->created_at, 0, 10)];
        }
    });
$rules = DB::table('product_entry_rules')->where('business_id', $biz)->get()->map(function ($r) {
    $r = (array) $r;
    return ['id' => $r['id'], 'title' => $r['title'] ?? ($r['trigger_value'] ?? ($r['label'] ?? '')), 'category_id' => (int) ($r['category_id'] ?? 0), 'sub_category_id' => (int) ($r['sub_category_id'] ?? 0)];
})->all();
$json = json_encode(['cats' => $cats, 'products' => $out, 'rules' => $rules]);
echo "ROWS products=" . count($out) . " cats=" . count($cats) . " rules=" . count($rules) . "\n";
echo "AUDIT_B64_START\n" . chunk_split(base64_encode(gzencode($json, 9)), 4000) . "AUDIT_B64_END\n";
