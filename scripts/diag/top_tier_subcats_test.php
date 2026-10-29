<?php
// Unit test for nivessa:add-top-tier-subcategories rules — no DB, no vendor.
// Run: php scripts/diag/top_tier_subcats_test.php
namespace Illuminate\Console { class Command {} }
namespace Illuminate\Support\Facades { class DB {} }
namespace App { class Category {} }
namespace {
require __DIR__ . '/../../app/Console/Commands/AddTopTierSubcategories.php';
use App\Console\Commands\AddTopTierSubcategories as C;

// Which target would this item land in? (first matching rule for its parent)
function target($parent, $sub, $text) {
    foreach (C::rules() as $r) {
        if (!in_array($parent, $r['parents'], true)) continue;
        if (C::ruleMatches($r, $sub, $text)) return $r['to'];
    }
    return null;
}

// [parent, current sub, "name artist", expected target or null]
$cases = [
    // Vinyl is off-limits: nothing moves there, no rule names a off-limits parent.
    ['Vinyl - Used', 'Rock', 'A Christmas Gift For You Phil Spector', null],
    ['Vinyl - Sealed', 'Jazz', 'Songs for Swingin\' Lovers Frank Sinatra', null],
    ['CD - Used', 'Rock', 'A Christmas Gift For You Phil Spector', null],
    ['CD - Sealed', 'Pop', 'Merry Christmas Mariah Carey', null],
    ['Cassettes - Used', 'Rock', 'A Christmas Gift For You Phil Spector', 'Christmas / Holiday'],
    ['Cassettes - Used', null, 'Merry Christmas Bing Crosby', 'Christmas / Holiday'],
    ['Cassettes - Used', 'Jazz', 'Lady in Satin Billie Holiday', null],
    ['Cassettes - Used', 'Punk', 'Holiday in Cambodia Dead Kennedys', null],
    ['Cassettes - Used', 'pop', 'Holiday Madonna', null],
    ['Cassettes - Used', 'pop', 'Happy Holidays Various', 'Christmas / Holiday'],
    ['CD - Used', 'Jazz', 'Songs for Swingin\' Lovers Frank Sinatra', null],
    ['CD - Used', 'Jazz', 'Ella and Louis Ella Fitzgerald', null],
    ['CD - Used', 'Jazz', 'Kind of Blue Miles Davis', null],
    ['CD - Used', 'Jazz', 'Getz/Gilberto Stan Getz', null],
    ['CD - Used', 'Bossanova', 'Wave Antonio Carlos Jobim', null],
    ['CD - Used', 'Latin', 'Africa Brasil Jorge Ben', null],
    ['CD - Used', 'Jazz', 'Samurai Samba Yellowjackets', null],
    ['Cassettes - Used', 'Soundtracks', 'Dames At Sea David Christmas', null],
    ['CD - Used', 'Jazz', 'Whipped Cream & Other Delights Herb Alpert & The Tijuana Brass', null],
    ['CD - Used', null, 'Quiet Village Martin Denny', null],
    ['Toys', null, 'Nekomata Okayu Figure', 'Anime Figures'],
    ['Toys', null, 'Haikyuu', 'Anime Figures'],
    ['Toys', 'Funko Pop', 'Naruto #727', null],
    ['Toys', null, 'Asuna #989', null],
    ['Toys', null, 'Hot Wheels Premium - Audi 90 Quattro Hot Wheels', null],
    ['Trading Cards', 'Pokemon Cards', '2025 Pokemon Mega EN Nickit Graded 10', 'Pokemon Slabs'],
    ['Trading Cards', 'Pokemon Cards', 'Ninetales PSA 9', 'Pokemon Slabs'],
    ['Trading Cards', 'Pokemon Cards', 'Charmeleon 8/68', null],
    ['Trading Cards', 'NBA Cards', 'Zion Williamson Prizm PSA 10', 'Graded Slabs'],
    ['Trading Cards', 'Pokemon Slabs', 'Charizard PSA 10', null],
    ['Magazines', 'Pop Culture', 'National Geographic January 1982', 'National Geographic'],
    ['Magazines', 'Pop Culture', 'Rolling Stone Issue 1260 Rolling Stone', 'Rolling Stone'],
    ['Magazines', 'Pop Culture', 'Spin Magazine The Offspring Spin', 'Music Magazines'],
    ['Magazines', 'Pop Culture', 'AP JUNE 2016 WEEZER', 'Music Magazines'],
    ['Magazines', 'Pop Culture', 'Nintendo Power #12', 'Gaming Magazines'],
    ['Magazines', 'Pop Culture', 'Game Informer #223', 'Gaming Magazines'],
    ['Magazines', 'Pop Culture', 'People: The Ryan Reynolds Interview', null],
    ['Magazines', 'Playboy', 'Playboy April 1964', null],
    ['Books', 'Comic Books', 'Web of Spider-Man #22 January Various', 'Marvel Comics'],
    ['Books', 'Comic Books', 'Adventures of Superman #524', 'DC Comics'],
    ['Books', 'Comic Books', 'Batman Outsiders', 'DC Comics'],
    ['Books', 'Comic Books', 'Spawn #1', 'Indie & Image Comics'],
    ['Books', 'Comic Books', 'Batman/Spider-Man crossover', null],
    ['Books', 'Comic Books', 'Seven Sons Part Two', null],
    ['Books', 'Biographies & Memoirs', 'Superman: The Biography', null],
];
$fail = 0;
foreach (array_keys(C::newSubs()) as $p) if (C::isOffLimits($p)) { echo "FAIL off-limits parent in newSubs: $p\n"; $fail++; }
foreach (C::rules() as $r) foreach ($r['parents'] as $p) if (C::isOffLimits($p)) { echo "FAIL off-limits parent in rule {$r['label']}\n"; $fail++; }
foreach ($cases as $c) {
    [$p, $s, $t, $want] = $c;
    $got = target($p, $s, $t);
    $ok = $got === $want;
    if (!$ok) $fail++;
    printf("%s  %-14s %-22s %-55s -> %s%s\n", $ok ? 'ok  ' : 'FAIL', $p, $s ?? '(none)', substr($t, 0, 55), $got ?? '(stay)', $ok ? '' : "  (want " . ($want ?? 'stay') . ")");
}
echo "\n" . (count($cases) - $fail) . '/' . count($cases) . " passed\n";
exit($fail ? 1 : 0);
}
