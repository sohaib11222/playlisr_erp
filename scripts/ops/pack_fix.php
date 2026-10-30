<?php
// Read-only: confirm deployed code knows the DVD / Blu-ray split.
require __DIR__ . '/../../vendor/autoload.php';
$app = require __DIR__ . '/../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$c = App\Http\Controllers\CostPriceRulesController::defaultCostsByCategoryId(1);
echo "cost DVD(112)=" . ($c[112] ?? 'none') . " Blu-ray(535)=" . ($c[535] ?? 'none') . "\n";
$js = @file_get_contents(base_path('public/js/mass_add_category_match.js'));
echo "deployed matcher has bluray mapping: " . (strpos($js, "bluray: 'bluray'") !== false ? 'yes' : 'no') . "\n";
foreach ([112, 535] as $id) echo "cat $id: " . Illuminate\Support\Facades\DB::table('categories')->where('id', $id)->value('name') . "\n";
