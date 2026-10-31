<?php
// Comic book product cost floor (Sarah 10/30): variations under $0.75 in Books > Comic Books /
// Marvel Comics / DC Comics go to $0.75. Mode from mag_mode.txt: dry | apply | undo.
// Log: storage/app/category_moves/comic-cost-floor-2026-10-30.json
require __DIR__ . '/../../vendor/autoload.php';
$app = require __DIR__ . '/../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
use Illuminate\Support\Facades\DB;

$mode = trim(@file_get_contents(__DIR__ . '/mag_mode.txt')) ?: 'dry';
$FLOOR = 0.75; $SUBS = [134, 517, 518];
$logPath = storage_path('app/category_moves/comic-cost-floor-2026-10-30.json');

if ($mode === 'undo') {
    $log = json_decode(@file_get_contents($logPath), true) ?: []; $n = 0;
    DB::transaction(function () use ($log, $FLOOR, &$n) {
        foreach ($log as $e) $n += DB::table('variations')->where('id', $e['id'])->where('default_purchase_price', $FLOOR)
            ->update(['default_purchase_price' => $e['dpp'], 'dpp_inc_tax' => $e['dpp_inc'], 'updated_at' => now()]);
    });
    echo "UNDO restored=$n\n"; exit;
}

$q = DB::table('variations')->join('products', 'products.id', '=', 'variations.product_id')
    ->where('products.business_id', 1)->whereIn('products.sub_category_id', $SUBS)->whereNull('variations.deleted_at')
    ->where(function ($w) use ($FLOOR) { $w->whereNull('variations.default_purchase_price')->orWhere('variations.default_purchase_price', '<', $FLOOR); });
$rows = (clone $q)->get(['variations.id', 'variations.default_purchase_price', 'variations.dpp_inc_tax', 'products.name', 'products.sub_category_id']);
echo "PLAN raise " . count($rows) . " comic variations to \$$FLOOR mode=$mode\n";
foreach ($rows->take(8) as $r) echo "  #{$r->id} {$r->name} {$r->default_purchase_price} -> $FLOOR\n";

if ($mode !== 'apply') { echo "DRY RUN: nothing changed\n"; exit; }
if (file_exists($logPath)) { echo "ABORT: log exists\n"; exit(1); }
@mkdir(dirname($logPath), 0775, true);
file_put_contents($logPath, json_encode($rows->map(function ($r) { return ['id' => (int) $r->id, 'dpp' => $r->default_purchase_price, 'dpp_inc' => $r->dpp_inc_tax]; })->values(), JSON_PRETTY_PRINT));
$n = 0;
DB::transaction(function () use ($rows, $FLOOR, &$n) {
    foreach ($rows->pluck('id')->chunk(500) as $ch) $n += DB::table('variations')->whereIn('id', $ch->all())
        ->update(['default_purchase_price' => $FLOOR, 'dpp_inc_tax' => $FLOOR, 'updated_at' => now()]);
});
echo "APPLIED updated=$n\n";
$left = DB::table('variations')->join('products', 'products.id', '=', 'variations.product_id')->whereIn('products.sub_category_id', $SUBS)
    ->whereNull('variations.deleted_at')->where('variations.default_purchase_price', '<', $FLOOR)->count();
echo "now under \$$FLOOR: $left; min=" . DB::table('variations')->join('products', 'products.id', '=', 'variations.product_id')->whereIn('products.sub_category_id', $SUBS)->whereNull('variations.deleted_at')->min('variations.default_purchase_price') . "\n";
