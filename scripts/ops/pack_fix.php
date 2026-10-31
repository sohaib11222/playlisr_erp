<?php
// Read-only: confirm deployed comic cost default/floor.
require __DIR__ . '/../../vendor/autoload.php';
$app = require __DIR__ . '/../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
use Illuminate\Support\Facades\DB;
$m = App\Http\Controllers\CostPriceRulesController::defaultCostsByCategoryId(1);
foreach (['120', '120_134', '120_517', '120_518', '120_400'] as $k) echo "default[$k]=" . ($m[$k] ?? 'none') . "\n";
echo "floor in massStore: " . (strpos(file_get_contents(base_path('app/Http/Controllers/ProductController.php')), 'COMIC_MIN_COST') !== false ? 'yes' : 'no') . "\n";
echo "comics under 0.75 now: " . DB::table('variations')->join('products', 'products.id', '=', 'variations.product_id')->whereIn('products.sub_category_id', [134, 517, 518])->whereNull('variations.deleted_at')->where('variations.default_purchase_price', '<', 0.75)->count() . "\n";
