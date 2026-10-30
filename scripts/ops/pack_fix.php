<?php
// Split Blu-ray out of "DVD/Blu Ray" (Sarah 10/30): rename #112 to "DVD", create top-level
// "Blu-ray" with the same genre subs, move products whose name says Blu-ray / 4K / BD.
// Mode from mag_mode.txt: dry | apply | undo. Log: storage/app/category_moves/bluray-2026-10-30.json
require __DIR__ . '/../../vendor/autoload.php';
$app = require __DIR__ . '/../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
use Illuminate\Support\Facades\DB;

$mode = trim(@file_get_contents(__DIR__ . '/mag_mode.txt')) ?: 'dry';
$biz = 1; $DVD = 112;
$logPath = storage_path('app/category_moves/bluray-2026-10-30.json');

if ($mode === 'undo') {
    $log = json_decode(@file_get_contents($logPath), true) ?: []; $n = 0;
    DB::transaction(function () use ($log, $biz, $DVD, &$n) {
        foreach ($log['moves'] ?? [] as $e) $n += DB::table('products')->where('business_id', $biz)->where('id', $e['id'])->where('category_id', $e['new_cat'])
            ->update(['category_id' => $e['old_cat'], 'sub_category_id' => $e['old_sub'], 'updated_at' => now()]);
        if (!empty($log['old_name'])) DB::table('categories')->where('id', $DVD)->update(['name' => $log['old_name']]);
    });
    echo "UNDO restored=$n (Blu-ray category left in place)\n"; exit;
}

$cats = DB::table('categories')->where('business_id', $biz)->whereNull('deleted_at')->get(['id', 'name', 'parent_id']);
$dvd = $cats->firstWhere('id', $DVD);
$dvdSubs = $cats->filter(function ($c) use ($DVD) { return (int) $c->parent_id === $DVD; });
$blu = $cats->first(function ($c) { return (int) $c->parent_id === 0 && preg_match('/^blu[\s-]?ray$/i', trim($c->name)); });
echo "DVD cat: #{$dvd->id} '{$dvd->name}', subs=" . $dvdSubs->pluck('name')->implode(', ') . "\n";
echo "Blu-ray cat: " . ($blu ? "#{$blu->id} exists" : 'will create') . "\n";

$re = '/blu[\s-]?ray|\bbluray\b|\bblu\b|\bbd\b|\b4k\b|ultra hd|\buhd\b/i';
$rows = DB::table('products')->where('business_id', $biz)->where('category_id', $DVD)->get(['id', 'name', 'sub_category_id']);
$plan = [];
$subName = []; foreach ($dvdSubs as $s) $subName[(int) $s->id] = trim($s->name);
foreach ($rows as $p) if (preg_match($re, $p->name)) {
    $plan[] = ['id' => (int) $p->id, 'name' => $p->name, 'old_cat' => $DVD, 'old_sub' => $p->sub_category_id ? (int) $p->sub_category_id : null, 'sub' => $subName[(int) $p->sub_category_id] ?? null];
    echo "  [MOVE] #{$p->id} {$p->name} :: " . ($subName[(int) $p->sub_category_id] ?? '(no sub)') . "\n";
}
echo "PLAN rename '{$dvd->name}' -> 'DVD'; move " . count($plan) . " of " . count($rows) . " mode=$mode\n";

if ($mode !== 'apply') { echo "DRY RUN: nothing changed\n"; exit; }
if (file_exists($logPath)) { echo "ABORT: log exists\n"; exit(1); }
@mkdir(dirname($logPath), 0775, true);
$log = ['old_name' => $dvd->name, 'moves' => [], 'created' => []];
DB::transaction(function () use (&$log, $biz, $DVD, $blu, $dvdSubs, $plan) {
    DB::table('categories')->where('id', $DVD)->update(['name' => 'DVD', 'updated_at' => now()]);
    $bid = $blu ? (int) $blu->id : (int) App\Category::create(['business_id' => $biz, 'name' => 'Blu-ray', 'category_type' => 'product', 'parent_id' => 0, 'created_by' => 1])->id;
    if (!$blu) $log['created'][] = $bid;
    $bsub = [];
    foreach (DB::table('categories')->where('parent_id', $bid)->whereNull('deleted_at')->get(['id', 'name']) as $s) $bsub[trim($s->name)] = (int) $s->id;
    foreach ($dvdSubs as $s) if (!isset($bsub[trim($s->name)])) {
        $bsub[trim($s->name)] = (int) App\Category::create(['business_id' => $biz, 'name' => trim($s->name), 'category_type' => 'product', 'parent_id' => $bid, 'created_by' => 1])->id;
        $log['created'][] = $bsub[trim($s->name)];
    }
    echo "  Blu-ray #$bid with " . count($bsub) . " subs\n";
    foreach ($plan as $e) {
        $ns = $e['sub'] ? $bsub[$e['sub']] : null;
        DB::table('products')->where('business_id', $biz)->where('id', $e['id'])->update(['category_id' => $bid, 'sub_category_id' => $ns, 'updated_at' => now()]);
        $log['moves'][] = ['id' => $e['id'], 'old_cat' => $e['old_cat'], 'old_sub' => $e['old_sub'], 'new_cat' => $bid, 'new_sub' => $ns];
    }
});
file_put_contents($logPath, json_encode($log, JSON_PRETTY_PRINT));
$bid = $log['moves'][0]['new_cat'] ?? null;
echo "APPLIED moved=" . count($log['moves']) . " created=" . count($log['created']) . "\n";
echo "  now '" . DB::table('categories')->where('id', $DVD)->value('name') . "': " . DB::table('products')->where('category_id', $DVD)->count() . "\n";
if ($bid) echo "  now '" . DB::table('categories')->where('id', $bid)->value('name') . "': " . DB::table('products')->where('category_id', $bid)->count() . "\n";
