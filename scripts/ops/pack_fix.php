<?php
// Magazines subcategory cleanup (Sarah 10/30): every product in Magazines (in stock or sold)
// gets a specific subcategory; real books/comics filed under Magazines move to Books.
// MODE=dry | apply | undo. Log: storage/app/category_moves/magazines-2026-10-30.json
require __DIR__ . '/../../vendor/autoload.php';
$app = require __DIR__ . '/../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
use Illuminate\Support\Facades\DB;

$mode = trim(@file_get_contents(__DIR__ . '/mag_mode.txt')) ?: 'dry';
$biz = 1; $MAG = 114; $BOOKS = 120; $COMICS = 134;
$logPath = storage_path('app/category_moves/magazines-2026-10-30.json');

if ($mode === 'undo') {
    $log = json_decode(@file_get_contents($logPath), true) ?: [];
    $n = 0;
    DB::transaction(function () use ($log, $biz, &$n) {
        foreach ($log['moves'] ?? [] as $e) {
            $n += DB::table('products')->where('business_id', $biz)->where('id', $e['id'])->where('category_id', $e['new_cat'])->where('sub_category_id', $e['new_sub'])
                ->update(['category_id' => $e['old_cat'], 'sub_category_id' => $e['old_sub'], 'updated_at' => now()]);
        }
    });
    echo "UNDO restored=$n (new subcategories left in place)\n"; exit;
}

// First match wins.
$rules = [
    'Playboy' => '/playboy|adult vintage magazines/i',
    'Rolling Stone' => '/rolling ?stones?\b/i',
    'Mad & Humor' => '/^mad\b|\bmad magazine|\bmad #|cracked magazine|harvard lampoon|the snark/i',
    'National Geographic' => '/national geographic|nat geo/i',
    'Adult' => '/hustler|penthouse|juggs|\btight\b|leg world|nugget|\boui\b|cheri\b|lingerie|torrid letters|one night stand|asian nude|asian fever|gents \d|tease \w+ \d{4}|rear view|hot & horny|midori|nerve: think about sex|adult crash|stuff for men|\bfhm\b/i',
    'Video Games' => '/nintendo|game ?informer|game players/i',
    'Music' => '/down ?beat|bunbury|uncut magazine|vibe magazine|db mag|billboard|alternative press|^ap |\bspin\b|guitar|bass player|jazziz|record collector|blender|seconds magazine|razorcake|skratch|right on magazine|musicians exchange|wings over america|cabaret voltaire|punk "the original"|dig yr own grave|apmas|the descendants|company a musical comedy/i',
    'Film, TV & Horror' => '/video watchdog|horrorhound|fangoria|creepy|eerie|filmfax|little shoppe|star trek|laser video guide|laserworks|films and filming|photoplay|movieland|screenland|cemetery dance|godzilla|hollywood studio|tv guide|100 greatest tv|movie directors|the a-team|toxic!|variety|vintage hollywood posters|justice league|tattooed teenage/i',
    'Sports' => '/sports illustrated|dodgers|kobe|pel[eé]|thrasher|\bsport\b|trading cards (jan|feb|mar|apr|may|jun|jul|aug|sep|oct|nov|dec)/i',
    'News & Celebrity' => '/\btime\b|\blife\b|people|\bjet\b|ebony|vanity fair|entertainment weekly|newspaper|nixon resigns|world war|wwii|military heritage|michael jackson|michal jackson|goodbye michael|celebrity|elle magazine|kennedy|diana|brad & jen|the day they marched|times of the seventies|this fabulous century|john wayne|holiday –|pride life|parade/i',
];
$books = '/^(the catcher in the rye|the electric kool-aid acid test|love is a do from hell|monkey mind|naked lunch|the american night|desperately seeking warhol|the coddling of the american mind|journals|crystal for healing|the only astrology book|journey through madness|andy rooney|extremely loud|a matter of days|just for the summer|saturday the rabbi|pool of dreams|an alchemy of mind|truly tyler|fifty shades|butcher and blackbird|spoon river|big little lies|pax|the book of joe|wisdom of eleanor roosevelt|little girl lost|my lucky stars|fallen angels|accidentally on purpose|the time of your life|the fifty year decline|survival of the savvy|alicia en el pa|business notes by|adventures in eating|guns n. roses appetite.*tablature|sex pistols never mind.*tablature|art book seven deadly sins|witches: discover)/i';
$comics = '/amazing spider-man|adventures of superman|youngblood force|spawn \w+ \d+ magazine/i';
$bareDate = '/^`?(jan|feb|mar|apr|may|jun|jul|aug|sep|oct|nov|dec)[a-z]*\.? \d{4}$/i';

$cats = DB::table('categories')->where('business_id', $biz)->whereNull('deleted_at')->get(['id', 'name', 'parent_id']);
$name = []; foreach ($cats as $c) $name[(int) $c->id] = trim($c->name);
$subId = []; foreach ($cats as $c) if ((int) $c->parent_id === $MAG) $subId[mb_strtolower(trim($c->name))] = (int) $c->id;
// Existing "Mad Magazine" sub becomes "Mad & Humor" (rename, same id).
if (isset($subId['mad magazine']) && !isset($subId['mad & humor'])) $subId['mad & humor'] = $subId['mad magazine'];

$rows = DB::table('products')->where('business_id', $biz)->where('category_id', $MAG)->orderBy('id')->get(['id', 'name', 'category_id', 'sub_category_id']);
$classify = function ($n) use ($rules, $books, $comics) {
    $n = trim($n);
    if (preg_match($books, $n)) return ['books', null];
    if (preg_match($comics, $n)) return ['comics', null];
    foreach ($rules as $sub => $re) if (preg_match($re, $n)) return ['mag', $sub];
    return ['mag', 'Pop Culture'];
};
$byId = []; foreach ($rows as $p) $byId[(int) $p->id] = $p;
$ids = array_keys($byId);
$plan = []; $counts = []; $dateLog = [];
$isDate = function ($n) use ($bareDate) { $n = trim($n); return preg_match($bareDate, $n) || preg_match('/^(19|20)\d\d$/', $n); };
// Date-only names come in runs entered together: use the named item just before / after the run.
$runCl = [];
for ($i = 0; $i < count($ids); $i++) {
    if (!$isDate($byId[$ids[$i]]->name)) continue;
    $j = $i; while ($j + 1 < count($ids) && $isDate($byId[$ids[$j + 1]]->name)) $j++;
    $before = $i > 0 ? $byId[$ids[$i - 1]] : null; $after = $j + 1 < count($ids) ? $byId[$ids[$j + 1]] : null;
    $cb = $before ? $classify($before->name) : null; $ca = $after ? $classify($after->name) : null;
    $ok = function ($c) { return $c && $c[0] === 'mag' && $c[1] !== 'Pop Culture'; };
    $pick = $ok($cb) ? $cb : ($ok($ca) ? $ca : ['mag', 'Pop Culture']);
    $dateLog[] = "run #{$ids[$i]}-#{$ids[$j]} (" . ($j - $i + 1) . ") before='" . ($before->name ?? '') . "' #" . ($before->id ?? '') . " after='" . ($after->name ?? '') . "' #" . ($after->id ?? '') . " -> {$pick[1]}";
    for ($k = $i; $k <= $j; $k++) $runCl[$ids[$k]] = $pick;
    $i = $j;
}
foreach ($rows as $p) {
    $n = trim($p->name);
    $cl = $runCl[(int) $p->id] ?? $classify($n);
    if ($cl[0] === 'books') { $to = [$BOOKS, null, 'Books']; }
    elseif ($cl[0] === 'comics') { $to = [$BOOKS, $COMICS, 'Books > Comic Books']; }
    else { $to = [$MAG, $subId[mb_strtolower($cl[1])] ?? 'NEW:' . $cl[1], 'Magazines > ' . $cl[1]]; }
    $counts[$to[2]] = ($counts[$to[2]] ?? 0) + 1;
    $cur = (int) $p->category_id === $to[0] && (string) $p->sub_category_id === (string) $to[1];
    if (!$cur) $plan[] = ['id' => (int) $p->id, 'name' => $n, 'old_cat' => (int) $p->category_id, 'old_sub' => $p->sub_category_id ? (int) $p->sub_category_id : null, 'to' => $to];
}
ksort($counts);
echo "PLAN products=" . count($rows) . " to_move=" . count($plan) . " mode=$mode\n";
foreach ($counts as $k => $v) echo "  $k: $v\n";
echo "Date-only runs:\n"; foreach ($dateLog as $l) echo "  $l\n";
echo "Left in Pop Culture:\n"; foreach ($rows as $p) { $c = $classify($p->name); if ($c[1] === 'Pop Culture' && !$isDate($p->name)) echo "  #{$p->id} {$p->name}\n"; }
echo "To Books/Comics:\n"; foreach ($plan as $e) if ($e['to'][0] === $BOOKS) echo "  #{$e['id']} {$e['name']} -> {$e['to'][2]}\n";

if ($mode !== 'apply') { echo "DRY RUN: nothing changed\n"; exit; }
if (file_exists($logPath)) { echo "ABORT: log exists\n"; exit(1); }
@mkdir(dirname($logPath), 0775, true);
$log = ['moves' => [], 'created' => []];
DB::transaction(function () use (&$log, &$subId, $plan, $biz, $MAG) {
    if (isset($subId['mad magazine'])) DB::table('categories')->where('id', $subId['mad magazine'])->update(['name' => 'Mad & Humor']);
    foreach ($plan as $e) {
        [$cat, $sub] = $e['to'];
        if (is_string($sub) && strpos($sub, 'NEW:') === 0) {
            $nm = substr($sub, 4);
            if (!isset($subId[mb_strtolower($nm)])) {
                $c = App\Category::create(['business_id' => $biz, 'name' => $nm, 'category_type' => 'product', 'parent_id' => $MAG, 'created_by' => 1]);
                $subId[mb_strtolower($nm)] = (int) $c->id; $log['created'][] = (int) $c->id; echo "  + created Magazines > $nm (#{$c->id})\n";
            }
            $sub = $subId[mb_strtolower($nm)];
        }
        DB::table('products')->where('business_id', $biz)->where('id', $e['id'])->update(['category_id' => $cat, 'sub_category_id' => $sub, 'updated_at' => now()]);
        $log['moves'][] = ['id' => $e['id'], 'old_cat' => $e['old_cat'], 'old_sub' => $e['old_sub'], 'new_cat' => $cat, 'new_sub' => $sub];
    }
});
file_put_contents($logPath, json_encode($log, JSON_PRETTY_PRINT));
echo "APPLIED moved=" . count($log['moves']) . " created=" . count($log['created']) . "\n";
foreach (DB::table('categories')->where('parent_id', $MAG)->whereNull('deleted_at')->get(['id', 'name']) as $s)
    echo "  now Magazines > {$s->name}: " . DB::table('products')->where('category_id', $MAG)->where('sub_category_id', $s->id)->count() . "\n";
echo "  now Magazines (no sub): " . DB::table('products')->where('category_id', $MAG)->whereNull('sub_category_id')->count() . "\n";
