<?php
// READ-ONLY: exercises DiscogsReleaseImportMapper's "existing artist products"
// genre fallback against prod data. Loads the mapper from storage/ (branch copy).
require __DIR__ . '/../../vendor/autoload.php';
$app = require __DIR__ . '/../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
require __DIR__ . '/../../storage/DiscogsReleaseImportMapper_branch.php';
$user = App\User::where('username', 'admin')->first() ?: App\User::orderBy('id')->first();
$bid = (int) $user->business_id;
$lookup = new App\Services\ArtistGenreLookup();
$mapper = new App\Services\DiscogsReleaseImportMapper($lookup);
$cats = App\Category::where('business_id', $bid)->pluck('name', 'id');
$artists = DB::table('products')->where('business_id', $bid)->whereNotNull('sub_category_id')
    ->whereNotNull('artist')->where('artist', '!=', '')
    ->groupBy('artist')->orderByRaw('COUNT(*) DESC')->limit(400)->pluck('artist')->all();
print_r(DB::select("SHOW COLUMNS FROM products LIKE 'artist'"));
foreach (DB::select("SHOW INDEX FROM products") as $ix) { if (stripos($ix->Column_name, 'artist') !== false) echo "INDEX {$ix->Key_name} {$ix->Column_name}\n"; }
$shown = 0;
foreach (['Various', 'Pokemon', 'Dionne Warwick', 'lou rawls', 'Unknown Artist'] as $x) { array_unshift($artists, $x); }
foreach ($artists as $a) {
    if ($lookup->binForArtist($a) !== null) continue;
    $t = microtime(true);
    foreach ([['vinyl', 'lp'], ['cd']] as $fmt) {
        $r = $mapper->resolveCategoryForArtist($bid, $a, $fmt);
        printf("%-30s %-8s genre=%-14s -> %s > %s (%.0fms)\n", $a, implode('/', $fmt), $r['bin'] ?? '-', $cats[$r['category_id']] ?? '-', $cats[$r['sub_category_id']] ?? '-', (microtime(true) - $t) * 1000);
    }
    if (++$shown >= 20) break;
}
