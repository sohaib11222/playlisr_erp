<?php
// READ-ONLY: prints the mass-add category combo labels (Category > Subcategory).
require __DIR__ . '/../../vendor/autoload.php';
$app = require __DIR__ . '/../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$user = App\User::where('username', 'admin')->first() ?: App\User::orderBy('id')->first();
$combos = App\Category::flattenedProductCategoryCombos((int) $user->business_id);
echo "COMBOS_JSON_START\n" . json_encode(array_map(function ($c) { return ['id' => $c['id'], 'label' => $c['label']]; }, $combos)) . "\nCOMBOS_JSON_END\n";
