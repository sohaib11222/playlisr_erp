<?php
// READ-ONLY: exercises the buy form's calculator + renders /buy-from-customer
// as an admin (no login event, no DB writes) to check item types and pricing.
require __DIR__ . '/../../vendor/autoload.php';
$app = require __DIR__ . '/../../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Http\Kernel::class);
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$calc = app(App\Services\BuyOfferCalculatorService::class);
$types = $calc->getItemTypesForDropdown(true);
foreach (['signed_item', 'rare_vhs', 'rare_dvd', 'rare_book', 'rare_comic', 'poster_value'] as $k) {
    echo "TYPE $k => " . ($types[$k] ?? 'MISSING') . "\n";
}

$user = App\User::where('username', 'admin')->first() ?: App\User::orderBy('id')->first();
$biz = (int) $user->business_id;
$loc = App\BusinessLocation::where('business_id', $biz)->orderBy('id')->first();
echo "user={$user->id} biz=$biz loc={$loc->id} {$loc->name}\n";

$ctl = app(App\Http\Controllers\BuyFromCustomerController::class);
$lines = [
    ['item_type' => 'signed_item', 'title' => 'Signed LP', 'quantity' => 1, 'discogs_median_price' => 150],
    ['item_type' => 'rare_vhs', 'title' => 'Rare VHS', 'quantity' => 1, 'discogs_median_price' => 40],
    ['item_type' => 'rare_dvd', 'title' => 'Rare DVD', 'quantity' => 1, 'discogs_median_price' => 60],
    ['item_type' => 'rare_book', 'title' => 'Rare Book', 'quantity' => 1, 'discogs_median_price' => 15],
    ['item_type' => 'rare_comic', 'title' => 'Rare Comic', 'quantity' => 2, 'discogs_median_price' => 100],
    ['item_type' => 'framed_art', 'title' => 'Framed Art', 'quantity' => 3],
    ['item_type' => 'rare_framed_art', 'title' => 'Framed Art - rare', 'quantity' => 1, 'discogs_median_price' => 80],
    ['item_type' => 'individual_vinyl', 'title' => 'Old signed draft', 'signed' => '1', 'quantity' => 1, 'discogs_median_price' => 50],
];
$m = new ReflectionMethod($ctl, 'demandHook');
$m->setAccessible(true);
$hook = $m->invoke($ctl, $biz, $loc->id, null, $lines);
$res = $calc->calculate($lines, [], $hook);
foreach ($res['lines'] ?? $res['normalized'] ?? [] as $l) {
    printf("LINE %-17s value=%-6s pay=%-6s cash=%-7s credit=%-7s grade=%s margin=%s reason=%s title=%s\n", $l['item_type'], $l['discogs_median_price'], $l['standard_multiplier'], $l['line_cash_total'], $l['line_credit_total'], $l['condition_grade'] ?? '-', $l['demand_margin'] ?? '-', $l['demand_reason'] ?? '-', $l['title']);
}
echo "KEYS " . implode(',', array_keys($res)) . "\n";

$ds = app(App\Services\BuyDemandService::class);
$vc = new ReflectionMethod($ds, 'valueTypeCategory');
$vc->setAccessible(true);
$cats = $ds->categoryChoices($biz);
foreach (App\Services\BuyDemandService::VALUE_TYPE_CATEGORY as $t => $spec) {
    $pick = $vc->invoke($ds, $biz, $spec);
    echo "CATEGORY $t => $pick " . ($cats[$pick] ?? '(none)') . "\n";
}
foreach ($cats as $ck => $cn) {
    if (preg_match('/art|frame|print|paint|wall|decor/i', $cn)) echo "ARTCAT $ck $cn\n";
}
echo "NUDGE vhs_used: " . App\Http\Controllers\BuyFromCustomerController::flatRateNudge([['item_type' => 'vhs_used', 'quantity' => 5]], true) . "\n";
echo "NUDGE book_comic: " . App\Http\Controllers\BuyFromCustomerController::flatRateNudge([['item_type' => 'book_comic', 'quantity' => 5]], true) . "\n";
echo "NUDGE dvd_player: " . App\Http\Controllers\BuyFromCustomerController::flatRateNudge([['item_type' => 'dvd_player', 'quantity' => 1]], true) . "\n";

// Render the page through the full HTTP stack as that user.
Auth::setUser($user);
$req = Illuminate\Http\Request::create('/buy-from-customer', 'GET');
$resp = $kernel->handle($req);
$html = $resp->getContent();
echo "PAGE status=" . $resp->getStatusCode() . " len=" . strlen($html) . " location=" . $resp->headers->get('Location') . "\n";
foreach (['signed_item', 'rare_vhs', 'VHS - rare', 'Comic - rare', 'bfc-signed', 'select2:select', 'signed_value'] as $needle) {
    echo "HAS '$needle': " . substr_count($html, $needle) . "\n";
}
echo "HTMLB64_START\n" . chunk_split(base64_encode(gzencode($html, 9)), 4000) . "HTMLB64_END\n";
