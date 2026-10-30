<?php
// Category moves from the 2026-10-29 audit (Sarah approved all suggestions).
// MODE=dry   : checks every move, changes nothing.
// MODE=apply : moves only products still in the category the audit saw;
//              logs old/new ids to storage/app/category_moves/<RUN>.json.
// MODE=undo  : puts back every product in that log that is still where we moved it.
require __DIR__ . '/../../vendor/autoload.php';
$app = require __DIR__ . '/../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
use Illuminate\Support\Facades\DB;

$mode = getenv('MODE') ?: 'dry';
$run = getenv('RUN') ?: 'audit-2026-10-29';
$movesFile = __DIR__ . '/' . (getenv('MOVES') ?: 'category_moves_2026_10_29.json');
$logPath = storage_path('app/category_moves/' . $run . '.json');
$user = App\User::where('username', 'admin')->first() ?: App\User::orderBy('id')->first();
$biz = (int) $user->business_id;

$cats = DB::table('categories')->where('business_id', $biz)->where('category_type', 'product')->whereNull('deleted_at')->get(['id', 'name', 'parent_id']);
$name = [];
foreach ($cats as $c) { $name[(int) $c->id] = trim($c->name); }
$labelOf = function ($cid, $sid) use ($name) {
    $t = $name[(int) $cid] ?? '';
    if ($t === '') return '';
    return ((int) $sid && isset($name[(int) $sid])) ? $t . ' > ' . $name[(int) $sid] : $t;
};
$idsOf = function ($label) use ($cats) {
    $p = explode(' > ', $label, 2);
    $top = $cats->first(function ($c) use ($p) { return (int) $c->parent_id === 0 && trim($c->name) === $p[0]; });
    if (!$top) return null;
    if (!isset($p[1])) return [(int) $top->id, null];
    $sub = $cats->first(function ($c) use ($p, $top) { return (int) $c->parent_id === (int) $top->id && trim($c->name) === $p[1]; });
    return $sub ? [(int) $top->id, (int) $sub->id] : null;
};


if ($mode === 'lookup') {
    // Gift Items products whose names look like records/magazines/gear, plus
    // where same-named products are filed, to pick the right category.
    $gift = $cats->first(function ($c) { return (int) $c->parent_id === 0 && stripos($c->name, 'gift item') !== false; });
    $rows = DB::table('products')->where('business_id', $biz)->where('category_id', $gift->id)
        ->where(function ($q) { foreach (['%playboy%', '%rudy ray%', '%atom heart%', '%black veil%', '%pink floyd%', '%pinkpantheress%', '%amplifier%', '%biscuit%'] as $l) $q->orWhere('name', 'like', $l); $q->orWhereIn('name', ['M', 's', 'UNQ']); })
        ->get(['id', 'name', 'sku', 'category_id', 'sub_category_id', 'added_via', 'created_at']);
    foreach ($rows as $r) {
        $qty = DB::table('variation_location_details as v')->join('variations as va', 'va.id', '=', 'v.variation_id')->where('va.product_id', $r->id)->sum('v.qty_available');
        echo "ITEM #{$r->id} | {$r->name} | sku={$r->sku} | now=" . $labelOf($r->category_id, $r->sub_category_id) . " | qty=" . (float) $qty . " | via={$r->added_via} | {$r->created_at}\n";
        $key = trim(preg_replace('/\(.*$/', '', $r->name));
        if (strlen($key) < 4) continue;
        $sib = DB::table('products')->where('business_id', $biz)->where('id', '!=', $r->id)->where('category_id', '!=', $gift->id)->where('name', 'like', '%' . $key . '%')->limit(6)->get(['id', 'name', 'category_id', 'sub_category_id']);
        foreach ($sib as $x) echo "     sib #{$x->id} {$x->name} :: " . $labelOf($x->category_id, $x->sub_category_id) . "\n";
    }
    $amp = $cats->filter(function ($c) use ($name) { return preg_match('/audio|magazine|vinyl|cassette/i', $name[(int) $c->parent_id] ?? $c->name); });
    foreach ($amp as $c) echo "CAT " . $labelOf($c->parent_id ?: $c->id, $c->parent_id ? $c->id : 0) . "\n";
    exit;
}

if ($mode === 'verify') {
    $moves = json_decode(file_get_contents($movesFile), true);
    $ok = 0; $bad = [];
    foreach ($moves as $m) {
        $p = DB::table('products')->where('business_id', $biz)->where('id', $m['id'])->first(['id', 'name', 'category_id', 'sub_category_id', 'updated_at']);
        $now = $p ? $labelOf($p->category_id, $p->sub_category_id) : '(missing)';
        $now === $m['to'] ? $ok++ : $bad[] = "#{$m['id']} now='{$now}' want='{$m['to']}'";
    }
    echo "VERIFY in-new-category=$ok of " . count($moves) . "\n";
    foreach ($bad as $b) echo "  NOT $b\n";
    exit;
}

if ($mode === 'undo') {
    $log = json_decode(@file_get_contents($logPath), true) ?: [];
    $n = 0; $skip = 0;
    DB::transaction(function () use ($log, $biz, &$n, &$skip) {
        foreach ($log as $e) {
            $q = DB::table('products')->where('business_id', $biz)->where('id', $e['id'])->where('category_id', $e['new_cat']);
            $e['new_sub'] === null ? $q->whereNull('sub_category_id') : $q->where('sub_category_id', $e['new_sub']);
            $c = $q->update(['category_id' => $e['old_cat'] ?: null, 'sub_category_id' => $e['old_sub'] ?: null, 'updated_at' => now()]);
            $c ? $n++ : $skip++;
        }
    });
    echo "UNDO restored=$n skipped(changed since)=$skip log=$logPath\n";
    exit;
}

$moves = json_decode(file_get_contents($movesFile), true);
$plan = []; $stats = ['ok' => 0, 'changed_since' => 0, 'missing' => 0, 'bad_target' => 0];
foreach ($moves as $m) {
    $p = DB::table('products')->where('business_id', $biz)->where('id', $m['id'])->first(['id', 'name', 'category_id', 'sub_category_id']);
    if (!$p) { $stats['missing']++; echo "SKIP missing #{$m['id']}\n"; continue; }
    $now = $labelOf($p->category_id, $p->sub_category_id);
    if ($now !== $m['from']) { $stats['changed_since']++; echo "SKIP changed #{$p->id} {$p->name} now='{$now}' audit='{$m['from']}'\n"; continue; }
    $to = $idsOf($m['to']);
    if (!$to) { $stats['bad_target']++; echo "SKIP target not found #{$p->id} '{$m['to']}'\n"; continue; }
    $plan[] = ['id' => (int) $p->id, 'name' => $p->name, 'old_cat' => $p->category_id ? (int) $p->category_id : null, 'old_sub' => $p->sub_category_id ? (int) $p->sub_category_id : null,
        'new_cat' => $to[0], 'new_sub' => $to[1], 'from' => $m['from'], 'to' => $m['to']];
    $stats['ok']++;
}
echo "PLAN " . json_encode($stats) . " mode=$mode\n";
foreach (array_slice($plan, 0, 12) as $e) echo "  #{$e['id']} {$e['name']} :: " . ($e['from'] ?: '(none)') . " -> {$e['to']}\n";

if ($mode !== 'apply') { echo "DRY RUN: nothing changed\n"; exit; }
if (file_exists($logPath)) { echo "ABORT: log $logPath already exists (already applied?)\n"; exit(1); }
@mkdir(dirname($logPath), 0775, true);
file_put_contents($logPath, json_encode($plan, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
$done = 0;
DB::transaction(function () use ($plan, $biz, &$done) {
    foreach ($plan as $e) {
        $q = DB::table('products')->where('business_id', $biz)->where('id', $e['id']);
        $e['old_cat'] === null ? $q->whereNull('category_id') : $q->where('category_id', $e['old_cat']);
        $e['old_sub'] === null ? $q->whereNull('sub_category_id') : $q->where('sub_category_id', $e['old_sub']);
        $done += $q->update(['category_id' => $e['new_cat'], 'sub_category_id' => $e['new_sub'], 'updated_at' => now()]);
    }
});
echo "APPLIED moved=$done of " . count($plan) . " log=$logPath\n";
echo "LOG_B64_START\n" . chunk_split(base64_encode(gzencode(json_encode($plan), 9)), 4000) . "LOG_B64_END\n";
