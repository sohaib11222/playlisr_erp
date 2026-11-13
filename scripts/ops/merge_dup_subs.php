<?php
// Merge duplicate music subcategories into the larger one (Sarah 2026-11-12).
// Mode: dry | apply | undo  (argv[1]). Undo log in storage/app/category_moves/.
require getcwd().'/vendor/autoload.php';
$app = require getcwd().'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
use Illuminate\Support\Facades\DB;
$mode = $argv[1] ?? 'dry';
$log = storage_path('app/category_moves/merge-dup-subs-2026-11-12.json');
// [from sub id, into sub id, parent id]
$merges = [[207, 294, 111], [373, 372, 363]];

// Every table with a sub_category_id column (information_schema names come back uppercase on prod).
$tables = array_map(function ($r) { $a = array_change_key_case((array) $r); return $a['table_name']; },
  DB::select("SELECT TABLE_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND COLUMN_NAME='sub_category_id'"));
echo "tables with sub_category_id: ".implode(', ', $tables)."\n";

if ($mode === 'undo') {
  $u = json_decode(file_get_contents($log), true);
  foreach ($u['rows'] as $t => $byFrom) foreach ($byFrom as $from => $ids)
    foreach (array_chunk($ids, 1000) as $c) DB::table($t)->whereIn('id', $c)->update(['sub_category_id' => (int) $from]);
  foreach ($u['merges'] as $m) DB::table('categories')->where('id', $m[0])->update(['deleted_at' => null]);
  echo "UNDONE\n"; exit;
}

$undo = ['merges' => $merges, 'rows' => []];
foreach ($merges as [$from, $into, $parent]) {
  $f = DB::table('categories')->where('id', $from)->first();
  $i = DB::table('categories')->where('id', $into)->first();
  if (!$f || !$i || $f->parent_id != $parent || $i->parent_id != $parent) { echo "SKIP $from->$into: parent mismatch\n"; continue; }
  echo "\n== {$f->name} (#$from) -> {$i->name} (#$into) ==\n";
  foreach ($tables as $t) {
    $hasId = DB::getSchemaBuilder()->hasColumn($t, 'id');
    $n = DB::table($t)->where('sub_category_id', $from)->count();
    if (!$n) continue;
    echo "  $t: $n rows\n";
    if ($mode !== 'apply') continue;
    if (!$hasId) { DB::table($t)->where('sub_category_id', $from)->update(['sub_category_id' => $into]); echo "  $t (no id col) updated in place\n"; continue; }
    $ids = DB::table($t)->where('sub_category_id', $from)->pluck('id')->all();
    $undo['rows'][$t][$from] = $ids;
    $upd = ['sub_category_id' => $into];
    if ($t === 'products') $upd['updated_at'] = now();
    foreach (array_chunk($ids, 1000) as $c) DB::table($t)->whereIn('id', $c)->update($upd);
  }
  if ($mode === 'apply') { DB::table('categories')->where('id', $from)->update(['deleted_at' => now()]); echo "  soft-deleted #$from\n"; }
}
if ($mode === 'apply') { @mkdir(dirname($log), 0775, true); file_put_contents($log, json_encode($undo)); echo "undo log: $log\n"; }
// verify
foreach ($merges as [$from, $into]) {
  echo "verify #$from left in products: ".DB::table('products')->where('sub_category_id', $from)->count()
     .", #$into now: ".DB::table('products')->where('sub_category_id', $into)->count()
     .", #$from deleted_at: ".(DB::table('categories')->where('id', $from)->value('deleted_at') ?: 'null')."\n";
}
echo "MODE $mode DONE\n";
