<?php
// Sealed packs/boxes filed under Trading Cards (in stock or sold) -> Trading Card Packs > matching sub.
// Mode from mag_mode.txt: dry | apply | undo. Log: storage/app/category_moves/tc-packs-2026-10-30.json
require __DIR__ . '/../../vendor/autoload.php';
$app = require __DIR__ . '/../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
use Illuminate\Support\Facades\DB;

$mode = trim(@file_get_contents(__DIR__ . '/mag_mode.txt')) ?: 'dry';
$biz = 1; $TC = 248; $PACKS = 481;
$logPath = storage_path('app/category_moves/tc-packs-2026-10-30.json');

if ($mode === 'undo') {
    $log = json_decode(@file_get_contents($logPath), true) ?: []; $n = 0;
    DB::transaction(function () use ($log, $biz, &$n) {
        foreach ($log as $e) $n += DB::table('products')->where('business_id', $biz)->where('id', $e['id'])->where('category_id', $e['new_cat'])->where('sub_category_id', $e['new_sub'])
            ->update(['category_id' => $e['old_cat'], 'sub_category_id' => $e['old_sub'], 'updated_at' => now()]);
    });
    echo "UNDO restored=$n\n"; exit;
}

$cats = DB::table('categories')->where('business_id', $biz)->whereNull('deleted_at')->get(['id', 'name', 'parent_id']);
$name = []; foreach ($cats as $c) $name[(int) $c->id] = trim($c->name);
$sub = []; foreach ($cats as $c) if ((int) $c->parent_id === $PACKS) $sub[trim($c->name)] = (int) $c->id;
echo "Pack subs: " . json_encode($sub) . "\n";

$isPack = '/\bpacks?\b|booster|blister|elite trainer|\betb\b|\bbox\b|bundle|\btin\b|sealed|collection box|premium collection|build (&|and) battle|starter deck|theme deck|hobby|\bblaster\b|\bretail\b|mystery/i';
$notPack = '/\bslab|psa \d|bgs|cgc|graded|single|\bholo\b(?!.*pack)|rookie card|\brc\b|lot of|binder|sleeves?\b|top ?loader|deck box|playmat|card protector/i';
$subFor = function ($n, $curSub) use ($name) {
    $s = $curSub ? ($name[$curSub] ?? '') : '';
    $t = $n . ' ' . $s;
    if (preg_match('/pok[eé]mon/i', $t)) return 'Pokemon Packs';
    if (preg_match('/one piece/i', $t)) return 'One Piece Packs';
    if (preg_match('/\bw?nba\b|basketball|jordan|kobe|hoops/i', $t)) return 'NBA Packs';
    if (preg_match('/\bnfl\b|football/i', $t)) return 'NFL Packs';
    if (preg_match('/\bnhl\b|hockey/i', $t)) return 'NHL Packs';
    if (preg_match('/\bmlb\b|baseball/i', $t)) return 'MLB Packs';
    if (preg_match('/college|ncaa/i', $t)) return 'College Packs';
    if (preg_match('/soccer|\bufc\b|wrestling|\bwwe\b|f1\b|racing|golf/i', $t)) return null; // other sport: top-level packs
    return 'Non-Sports Packs';
};

$rows = DB::table('products')->where('business_id', $biz)->where('category_id', $TC)->orderBy('sub_category_id')->orderBy('name')
    ->get(['id', 'name', 'sub_category_id']);
echo "Trading Cards products: " . count($rows) . "\n";
$plan = []; $counts = []; $notMoved = [];
foreach ($rows as $p) {
    $n = trim($p->name);
    if (!preg_match($isPack, $n) || preg_match($notPack, $n)) { $notMoved[] = $p; continue; }
    $s = $subFor($n, $p->sub_category_id);
    $ns = $s ? $sub[$s] : null;
    $plan[] = ['id' => (int) $p->id, 'name' => $n, 'old_cat' => $TC, 'old_sub' => $p->sub_category_id ? (int) $p->sub_category_id : null, 'new_cat' => $PACKS, 'new_sub' => $ns];
    $k = 'Trading Card Packs' . ($s ? " > $s" : ''); $counts[$k] = ($counts[$k] ?? 0) + 1;
    echo "  [MOVE] #{$p->id} $n :: Trading Cards" . ($p->sub_category_id ? ' > ' . ($name[(int) $p->sub_category_id] ?? '?') : '') . " -> $k\n";
}
echo "PLAN to_move=" . count($plan) . " mode=$mode\n"; foreach ($counts as $k => $v) echo "  $k: $v\n";
echo "Staying in Trading Cards (pokemon / other names that might be packs):\n";
foreach ($notMoved as $p) if (preg_match('/pok[eé]mon|pack|box|sealed|booster|one piece|magic|digimon|dragon ?ball|yu-?gi|lorcana/i', $p->name . ' ' . ($name[(int) $p->sub_category_id] ?? ''))) echo "  #{$p->id} {$p->name} :: " . ($name[(int) $p->sub_category_id] ?? '(no sub)') . "\n";

if ($mode !== 'apply') { echo "DRY RUN: nothing changed\n"; exit; }
if (file_exists($logPath)) { echo "ABORT: log exists\n"; exit(1); }
@mkdir(dirname($logPath), 0775, true);
file_put_contents($logPath, json_encode($plan, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
$done = 0;
DB::transaction(function () use ($plan, $biz, &$done) {
    foreach ($plan as $e) $done += DB::table('products')->where('business_id', $biz)->where('id', $e['id'])->where('category_id', $e['old_cat'])
        ->update(['category_id' => $e['new_cat'], 'sub_category_id' => $e['new_sub'], 'updated_at' => now()]);
});
echo "APPLIED moved=$done\n";
foreach (DB::table('categories')->where('parent_id', $PACKS)->whereNull('deleted_at')->get(['id', 'name']) as $s)
    echo "  now Trading Card Packs > {$s->name}: " . DB::table('products')->where('category_id', $PACKS)->where('sub_category_id', $s->id)->count() . "\n";
echo "  now Trading Card Packs (no sub): " . DB::table('products')->where('category_id', $PACKS)->whereNull('sub_category_id')->count() . "\n";
