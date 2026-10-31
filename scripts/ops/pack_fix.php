<?php
// Manga product cost floor (Sarah 10/30): Books > Manga variations under $1.50 go to $1.50.
// Mode from mag_mode.txt: dry | apply | undo. Log: storage/app/category_moves/manga-cost-floor-2026-10-30.json
require __DIR__ . '/../../vendor/autoload.php';
$app = require __DIR__ . '/../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
use Illuminate\Support\Facades\DB;

$mode = trim(@file_get_contents(__DIR__ . '/mag_mode.txt')) ?: 'dry';
$FLOOR = 1.50; $SUBS = [400];
$logPath = storage_path('app/category_moves/manga-cost-floor-2026-10-30.json');

if ($mode === 'undo') {
    $log = json_decode(@file_get_contents($logPath), true) ?: []; $n = 0;
    DB::transaction(function () use ($log, $FLOOR, &$n) {
        foreach ($log as $e) $n += DB::table('variations')->where('id', $e['id'])->where('default_purchase_price', $FLOOR)
            ->update(['default_purchase_price' => $e['dpp'], 'dpp_inc_tax' => $e['dpp_inc'], 'updated_at' => now()]);
    });
    echo "UNDO restored=$n\n"; exit;
}
$base = function () use ($SUBS) {
    return DB::table('variations')->join('products', 'products.id', '=', 'variations.product_id')
        ->where('products.business_id', 1)->whereIn('products.sub_category_id', $SUBS)->whereNull('variations.deleted_at');
};
echo "manga variations: " . $base()->count() . "\n";
$rows = $base()->where(function ($w) use ($FLOOR) { $w->whereNull('variations.default_purchase_price')->orWhere('variations.default_purchase_price', '<', $FLOOR); })
    ->get(['variations.id', 'variations.default_purchase_price', 'variations.dpp_inc_tax', 'products.name']);
echo "PLAN raise " . count($rows) . " to \$$FLOOR mode=$mode\n";
foreach ($rows->take(8) as $r) echo "  #{$r->id} {$r->name} {$r->default_purchase_price} -> $FLOOR\n";
if ($mode !== 'apply') { echo "DRY RUN: nothing changed\n"; exit; }
if (file_exists($logPath)) { echo "ABORT: log exists\n"; exit(1); }
@mkdir(dirname($logPath), 0775, true);
file_put_contents($logPath, json_encode($rows->map(function ($r) { return ['id' => (int) $r->id, 'dpp' => $r->default_purchase_price, 'dpp_inc' => $r->dpp_inc_tax]; })->values(), JSON_PRETTY_PRINT));
$n = DB::table('variations')->whereIn('id', $rows->pluck('id')->all())->update(['default_purchase_price' => $FLOOR, 'dpp_inc_tax' => $FLOOR, 'updated_at' => now()]);
echo "APPLIED updated=$n; now under \$$FLOOR: " . $base()->where('variations.default_purchase_price', '<', $FLOOR)->count() . "; min=" . $base()->min('variations.default_purchase_price') . "\n";
