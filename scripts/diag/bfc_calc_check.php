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
    ['item_type' => 'rare_mlb', 'title' => 'MLB - rare', 'quantity' => 1, 'discogs_median_price' => 50],
    ['item_type' => 'rare_nba', 'title' => 'NBA - rare', 'quantity' => 1, 'discogs_median_price' => 50],
    ['item_type' => 'rare_nfl', 'title' => 'NFL - rare', 'quantity' => 1, 'discogs_median_price' => 50],
    ['item_type' => 'rare_pokemon', 'title' => 'Pokemon - rare', 'quantity' => 1, 'discogs_median_price' => 50],
    ['item_type' => 'card_pack', 'title' => 'Card Packs', 'quantity' => 10, 'discogs_median_price' => 6],
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
foreach ([9.45, 9.58, 0.30, 0, 123.5] as $x) echo "ROUND $x => " . App\Services\BuyOfferCalculatorService::roundOffer($x) . "\n";
$o = $calc->calculate([['item_type' => 'framed_art', 'quantity' => 3]]);
printf("OFFERS total=%s start=%s/%s second=%s/%s final=%s/%s\n", $o['calculated_cash_total'], $o['starting_offer_cash'], $o['starting_offer_credit'], $o['second_offer_cash'], $o['second_offer_credit'], $o['final_offer_cash'], $o['final_offer_credit']);

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

// Jon 2026-10-28: what makes a buy-form line go through vs get blocked
// (server side, BuyFromCustomerController::validateLineSanity), one line per case.
config(['session.driver' => 'array']);
$vcats = $ds->categoryChoices($biz);
$vinylCat = array_search(current(array_filter($vcats, function ($n) { return preg_match('/^(vinyl|records?)\b/i', $n); })), $vcats);
$castCat = array_search(current(array_filter($vcats, function ($n) { return preg_match('/^cassettes?\b/i', $n); })), $vcats);
$gearCat = array_search(current(array_filter($vcats, function ($n) { return preg_match('/^audio gear.*(record|cassette)/i', $n); })), $vcats);
$sevenCat = array_search(current(array_filter($vcats, function ($n) { return preg_match('/^7\W/', $n) && strpos($n, '›') === false; })), $vcats);
echo "CATS 7in=$sevenCat " . ($vcats[$sevenCat] ?? '-') . "\n";
$otherCat = array_search(current(array_filter($vcats, function ($n) { return preg_match('/^(vhs|dvd|toys|trading cards|books?)\b/i', $n); })), $vcats);
echo "FORMATS " . implode(', ', array_filter($vcats, function ($n) { return strpos($n, '›') === false; })) . "\n";
echo "CATS gear=$gearCat " . ($vcats[$gearCat] ?? '-') . " | vinyl=$vinylCat " . ($vcats[$vinylCat] ?? '-') . " | cassette=$castCat " . ($vcats[$castCat] ?? '-') . " | other=$otherCat " . ($vcats[$otherCat] ?? '-') . "\n";
$san = new ReflectionMethod($ctl, 'validateLineSanity');
$san->setAccessible(true);
$cases = [
    ['GO',    'VHS rare $18, no link', ['item_type' => 'rare_vhs', 'discogs_median_price' => 18]],
    ['GO',    'VHS rare $20, no link (not MORE than $20)', ['item_type' => 'rare_vhs', 'discogs_median_price' => 20]],
    ['BLOCK', 'VHS rare $25, no link', ['item_type' => 'rare_vhs', 'discogs_median_price' => 25]],
    ['GO',    'VHS rare $25, eBay single listing /itm/', ['item_type' => 'rare_vhs', 'discogs_median_price' => 25, 'discogs_link' => 'https://www.ebay.com/itm/123456789012']],
    ['GO',    'VHS rare $25, eBay search WITHOUT sold filter', ['item_type' => 'rare_vhs', 'discogs_median_price' => 25, 'discogs_link' => 'https://www.ebay.com/sch/i.html?_nkw=faces+of+death']],
    ['GO',    'VHS rare $25, eBay sold search', ['item_type' => 'rare_vhs', 'discogs_median_price' => 25, 'discogs_link' => 'https://www.ebay.com/sch/i.html?_nkw=x&LH_Sold=1']],
    ['GO',    'VHS rare $25, eBay link without https://', ['item_type' => 'rare_vhs', 'discogs_median_price' => 25, 'discogs_link' => 'www.ebay.com/itm/123']],
    ['GO',    'VHS rare $25, eBay app share link (ebay.us)', ['item_type' => 'rare_vhs', 'discogs_median_price' => 25, 'discogs_link' => 'https://ebay.us/m/AbC123']],
    ['BLOCK', 'VHS rare $25, Google link (not eBay)', ['item_type' => 'rare_vhs', 'discogs_median_price' => 25, 'discogs_link' => 'https://www.google.com/search?q=ebay+vhs']],
    ['GO',    'Valuable item $50, category Vinyl, no link', ['item_type' => 'ebay_value', 'discogs_median_price' => 50, 'genre' => $vinylCat]],
    ['GO',    'Valuable item $50, category Cassette, no link', ['item_type' => 'ebay_value', 'discogs_median_price' => 50, 'genre' => $castCat]],
    ['GO',    'Valuable item $50, category 7" / 45 RPM, no link', ['item_type' => 'ebay_value', 'discogs_median_price' => 50, 'genre' => $sevenCat]],
    ['BLOCK', 'Valuable item $50, category Audio Gear record/cassette gear, no link', ['item_type' => 'ebay_value', 'discogs_median_price' => 50, 'genre' => $gearCat]],
    ['BLOCK', 'Valuable item $50, category non-music, no link', ['item_type' => 'ebay_value', 'discogs_median_price' => 50, 'genre' => $otherCat]],
    ['BLOCK', 'Valuable item $50, no category, no link', ['item_type' => 'ebay_value', 'discogs_median_price' => 50]],
    ['BLOCK', 'Camera $40, no link', ['item_type' => 'camera', 'discogs_median_price' => 40]],
    ['GO',    'Camera $15, no link', ['item_type' => 'camera', 'discogs_median_price' => 15]],
    ['BLOCK', 'Card packs $25, no link', ['item_type' => 'card_pack', 'discogs_median_price' => 25]],
    ['BLOCK', 'Signed/rare poster $30, no link', ['item_type' => 'poster_value', 'discogs_median_price' => 30]],
    ['GO',    'Signed item $150, no link', ['item_type' => 'signed_item', 'discogs_median_price' => 150]],
    ['BLOCK', 'VHS rare $10 (rare lines are $15+)', ['item_type' => 'rare_vhs', 'discogs_median_price' => 10]],
    ['BLOCK', 'Value $20,000 (typo guard)', ['item_type' => 'rare_vhs', 'discogs_median_price' => 20000, 'discogs_link' => 'https://www.ebay.com/itm/1']],
    ['BLOCK', '1-9 vinyl, qty 25', ['item_type' => 'individual_vinyl', 'quantity' => 25, 'discogs_median_price' => 5, 'condition_grade' => 'VG+']],
    ['BLOCK', '1-9 vinyl with title, no grade', ['item_type' => 'individual_vinyl', 'title' => 'LP', 'discogs_median_price' => 5]],
    ['BLOCK', '1-9 vinyl $25 median, no Discogs link', ['item_type' => 'individual_vinyl', 'discogs_median_price' => 25, 'condition_grade' => 'VG+']],
    ['GO',    '1-9 vinyl $25 median, Discogs ID', ['item_type' => 'individual_vinyl', 'discogs_median_price' => 25, 'condition_grade' => 'VG+', 'discogs_link' => '1234567']],
    ['GO',    '1-9 vinyl $15 median, no link', ['item_type' => 'individual_vinyl', 'discogs_median_price' => 15, 'condition_grade' => 'VG+']],
];
foreach ($cases as $c) {
    list($want, $name, $line) = $c;
    $line += ['quantity' => 1, 'title' => 'Test item'];
    $r = Illuminate\Http\Request::create('/buy-from-customer', 'POST', ['lines' => [$line]]);
    $r->setLaravelSession(app('session')->driver('array'));
    $r->session()->put('user.business_id', $biz);
    $got = 'GO';
    $why = '';
    try {
        $san->invoke($ctl, $r);
    } catch (Illuminate\Validation\ValidationException $e) {
        $got = 'BLOCK';
        $why = implode(' | ', Illuminate\Support\Arr::flatten($e->errors()));
    }
    printf("SERVER %s want=%-5s got=%-5s %s%s\n", $got === $want ? 'PASS' : 'FAIL', $want, $got, $name, $why !== '' ? ' -> ' . $why : '');
}

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
