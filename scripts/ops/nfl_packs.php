<?php
// Move 1990 / 1991 NFL card packs into the Trading Card Packs category.
// MODE=dry   : lists matches + their current category, changes nothing.
// MODE=apply : moves them; logs old ids to storage/app/category_moves/nfl-packs-2026-10-29.json.
// MODE=undo  : puts back every product in that log that is still where we moved it.
require __DIR__ . '/../../vendor/autoload.php';
$app = require __DIR__ . '/../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
use Illuminate\Support\Facades\DB;

$mode = getenv('MODE') ?: 'dry';
$logPath = storage_path('app/category_moves/nfl-packs-2026-10-29.json');
$user = App\User::where('username', 'admin')->first() ?: App\User::orderBy('id')->first();
$biz = (int) $user->business_id;

$cats = DB::table('categories')->where('business_id', $biz)->where('category_type', 'product')->whereNull('deleted_at')->get(['id', 'name', 'parent_id']);
$name = [];
foreach ($cats as $c) { $name[(int) $c->id] = trim($c->name); }
$labelOf = function ($cid, $sid) use ($name) {
    $t = $name[(int) $cid] ?? '';
    if ($t === '') return '(none)';
    return ((int) $sid && isset($name[(int) $sid])) ? $t . ' > ' . $name[(int) $sid] : $t;
};

echo "Card-ish categories:\n";
foreach ($cats as $c) {
    if (preg_match('/card|pack/i', $c->name)) echo "  #{$c->id} " . $labelOf($c->parent_id ?: $c->id, $c->parent_id ? $c->id : null) . "\n";
}

$target = $cats->first(function ($c) { return (int) $c->parent_id === 0 && strcasecmp(trim($c->name), 'Trading Card Packs') === 0; });
if (!$target) { echo "ABORT: no top-level 'Trading Card Packs' category\n"; exit(1); }
$tid = (int) $target->id;

if ($mode === 'undo') {
    $log = json_decode(@file_get_contents($logPath), true) ?: [];
    $n = 0; $skip = 0;
    DB::transaction(function () use ($log, $biz, $tid, &$n, &$skip) {
        foreach ($log as $e) {
            $c = DB::table('products')->where('business_id', $biz)->where('id', $e['id'])->where('category_id', $tid)->whereNull('sub_category_id')
                ->update(['category_id' => $e['old_cat'], 'sub_category_id' => $e['old_sub'], 'updated_at' => now()]);
            $c ? $n++ : $skip++;
        }
    });
    echo "UNDO restored=$n skipped=$skip\n";
    exit;
}

$rows = DB::table('products')->where('business_id', $biz)
    ->where('name', 'like', '%NFL%')
    ->where(function ($q) { $q->where('name', 'like', '%1990%')->orWhere('name', 'like', '%1991%')->orWhere('name', 'like', "%'90%")->orWhere('name', 'like', "%'91%"); })
    ->get(['id', 'name', 'sku', 'category_id', 'sub_category_id']);

$plan = [];
foreach ($rows as $p) {
    $isPack = (int) $p->id >= 141050 && (int) $p->id <= 141057; // the 8 1990/1991 packs (named 'NFL Cards')
    $already = (int) $p->category_id === $tid;
    $tag = $already ? 'ALREADY' : ($isPack ? 'MOVE' : 'SKIP(not a pack?)');
    echo "  [$tag] #{$p->id} {$p->sku} {$p->name} :: " . $labelOf($p->category_id, $p->sub_category_id) . "\n";
    if ($isPack && !$already) $plan[] = ['id' => (int) $p->id, 'name' => $p->name, 'old_cat' => $p->category_id ? (int) $p->category_id : null, 'old_sub' => $p->sub_category_id ? (int) $p->sub_category_id : null];
}
echo "PLAN matched=" . count($rows) . " to_move=" . count($plan) . " target=#$tid mode=$mode\n";

if ($mode !== 'apply') { echo "DRY RUN: nothing changed\n"; exit; }
if (file_exists($logPath)) { echo "ABORT: log already exists (already applied?)\n"; exit(1); }
@mkdir(dirname($logPath), 0775, true);
file_put_contents($logPath, json_encode($plan, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
$done = 0;
DB::transaction(function () use ($plan, $biz, $tid, &$done) {
    foreach ($plan as $e) {
        $done += DB::table('products')->where('business_id', $biz)->where('id', $e['id'])
            ->update(['category_id' => $tid, 'sub_category_id' => null, 'updated_at' => now()]);
    }
});
echo "APPLIED moved=$done of " . count($plan) . "\n";
foreach ($plan as $e) {
    $p = DB::table('products')->where('id', $e['id'])->first(['category_id', 'sub_category_id']);
    echo "  now #{$e['id']} :: " . $labelOf($p->category_id, $p->sub_category_id) . "\n";
}
