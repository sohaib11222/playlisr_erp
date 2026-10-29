<?php
// Trading Card Packs subcategories (NBA / NFL / NHL / MLB / Non-Sports Packs) and
// folding the duplicate Trading Cards > Packs into Trading Card Packs.
// MODE=dry   : lists every pack item and where it would go, changes nothing.
// MODE=apply : creates missing subs, moves items, retires Trading Cards > Packs once empty;
//              logs old ids to storage/app/category_moves/pack-subcats-2026-10-29.json.
// MODE=undo  : puts back every product in that log that is still where we moved it.
require __DIR__ . '/../../vendor/autoload.php';
$app = require __DIR__ . '/../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
use Illuminate\Support\Facades\DB;

$mode = getenv('MODE') ?: 'dry';
$logPath = storage_path('app/category_moves/pack-subcats-2026-10-29.json');
$user = App\User::where('username', 'admin')->first() ?: App\User::orderBy('id')->first();
$biz = (int) $user->business_id;

$catsQ = function () use ($biz) {
    return DB::table('categories')->where('business_id', $biz)->where('category_type', 'product')->whereNull('deleted_at')->get(['id', 'name', 'parent_id']);
};
$cats = $catsQ();
$name = [];
foreach ($cats as $c) { $name[(int) $c->id] = trim($c->name); }
$labelOf = function ($cid, $sid) use (&$name) {
    $t = $name[(int) $cid] ?? '';
    if ($t === '') return '(none)';
    return ((int) $sid && isset($name[(int) $sid])) ? $t . ' > ' . $name[(int) $sid] : $t;
};

$top = $cats->first(function ($c) { return (int) $c->parent_id === 0 && strcasecmp(trim($c->name), 'Trading Card Packs') === 0; });
$tc = $cats->first(function ($c) { return (int) $c->parent_id === 0 && strcasecmp(trim($c->name), 'Trading Cards') === 0; });
$dup = $tc ? $cats->first(function ($c) use ($tc) { return (int) $c->parent_id === (int) $tc->id && strcasecmp(trim($c->name), 'Packs') === 0; }) : null;
if (!$top) { echo "ABORT: no Trading Card Packs\n"; exit(1); }
$tid = (int) $top->id;

if ($mode === 'undo') {
    $log = json_decode(@file_get_contents($logPath), true) ?: [];
    $n = 0; $skip = 0;
    DB::transaction(function () use ($log, $biz, &$n, &$skip) {
        foreach ($log['moves'] ?? [] as $e) {
            $c = DB::table('products')->where('business_id', $biz)->where('id', $e['id'])->where('category_id', $e['new_cat'])->where('sub_category_id', $e['new_sub'])
                ->update(['category_id' => $e['old_cat'], 'sub_category_id' => $e['old_sub'], 'updated_at' => now()]);
            $c ? $n++ : $skip++;
        }
        if (!empty($log['retired'])) DB::table('categories')->where('id', $log['retired'])->update(['deleted_at' => null]);
    });
    echo "UNDO restored=$n skipped=$skip (new subcategories left in place)\n";
    exit;
}

// Which sub each pack goes in. Order matters: first match wins.
$rules = [
    'NBA Packs' => '/\bnba\b|basketball|hoops|skybox|fleer ultra basketball/i',
    'NFL Packs' => '/\bnfl\b|football|pro set|score football/i',
    'NHL Packs' => '/\bnhl\b|hockey/i',
    'MLB Packs' => '/\bmlb\b|baseball|topps baseball|donruss|bowman/i',
    'Pokemon Packs' => '/pok[eé]mon/i',
    'One Piece Packs' => '/one piece/i',
];
$sportsWords = '/\bnba\b|\bnfl\b|\bnhl\b|\bmlb\b|basketball|football|hockey|baseball|soccer|\bufc\b|wrestling|\bwwe\b|\bwwf\b|golf|racing|nascar|boxing/i';
$subs = array_merge(array_keys($rules), ['Non-Sports Packs']);

$subId = [];
foreach ($cats as $c) if ((int) $c->parent_id === $tid) $subId[mb_strtolower(trim($c->name))] = (int) $c->id;
echo "Existing subs under Trading Card Packs: " . implode(', ', array_map(function ($k) use ($subId) { return "$k#{$subId[$k]}"; }, array_keys($subId))) . "\n";
foreach ($subs as $s) if (!isset($subId[mb_strtolower($s)])) echo "  will create Trading Card Packs > $s\n";

$fromCats = [$tid]; if ($dup) $fromCats[] = (int) $dup->id;
$rows = DB::table('products')->where('business_id', $biz)
    ->where(function ($q) use ($tid, $dup) {
        $q->where('category_id', $tid);
        if ($dup) $q->orWhere('sub_category_id', (int) $dup->id);
    })->orderBy('name')->get(['id', 'name', 'category_id', 'sub_category_id']);

$plan = []; $counts = [];
foreach ($rows as $p) {
    $want = null;
    foreach ($rules as $sub => $re) if (preg_match($re, $p->name)) { $want = $sub; break; }
    if (!$want && !preg_match($sportsWords, $p->name)) $want = 'Non-Sports Packs';
    $cur = $labelOf($p->category_id, $p->sub_category_id);
    $dest = $want ? "Trading Card Packs > $want" : 'Trading Card Packs (other sport, left top-level)';
    if ($cur === $dest || (!$want && (int) $p->category_id === $tid && !$p->sub_category_id)) { $tag = 'STAY'; }
    else { $tag = 'MOVE'; $plan[] = ['id' => (int) $p->id, 'name' => $p->name, 'old_cat' => $p->category_id ? (int) $p->category_id : null, 'old_sub' => $p->sub_category_id ? (int) $p->sub_category_id : null, 'sub' => $want]; }
    $counts[$dest] = ($counts[$dest] ?? 0) + 1;
    echo "  [$tag] #{$p->id} {$p->name} :: $cur -> $dest\n";
}
echo "PLAN items=" . count($rows) . " to_move=" . count($plan) . " mode=$mode\n";
foreach ($counts as $k => $v) echo "  $k: $v\n";

if ($mode !== 'apply') { echo "DRY RUN: nothing changed\n"; exit; }
if (file_exists($logPath)) { echo "ABORT: log already exists (already applied?)\n"; exit(1); }
@mkdir(dirname($logPath), 0775, true);

$log = ['moves' => [], 'created' => [], 'retired' => null];
DB::transaction(function () use (&$log, $subs, &$subId, $biz, $tid, $plan, $dup, &$name) {
    foreach ($subs as $s) {
        if (isset($subId[mb_strtolower($s)])) continue;
        $c = App\Category::create(['business_id' => $biz, 'name' => $s, 'category_type' => 'product', 'parent_id' => $tid, 'created_by' => 1]);
        $subId[mb_strtolower($s)] = (int) $c->id; $name[(int) $c->id] = $s; $log['created'][] = (int) $c->id;
        echo "  + created Trading Card Packs > $s (#{$c->id})\n";
    }
    foreach ($plan as $e) {
        $ns = $e['sub'] ? $subId[mb_strtolower($e['sub'])] : null;
        $q = DB::table('products')->where('business_id', $biz)->where('id', $e['id']);
        $q->update(['category_id' => $tid, 'sub_category_id' => $ns, 'updated_at' => now()]);
        $log['moves'][] = $e + ['new_cat' => $tid, 'new_sub' => $ns];
    }
    if ($dup && !DB::table('products')->where('business_id', $biz)->where('sub_category_id', (int) $dup->id)->exists()) {
        DB::table('categories')->where('id', (int) $dup->id)->update(['deleted_at' => now()]);
        $log['retired'] = (int) $dup->id;
        echo "  - retired Trading Cards > Packs (#{$dup->id}), now empty\n";
    }
});
file_put_contents($logPath, json_encode($log, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
echo "APPLIED moved=" . count($log['moves']) . " created=" . count($log['created']) . "\n";
foreach ($log['moves'] as $e) {
    $p = DB::table('products')->where('id', $e['id'])->first(['category_id', 'sub_category_id']);
    echo "  now #{$e['id']} {$e['name']} :: " . $labelOf($p->category_id, $p->sub_category_id) . "\n";
}
