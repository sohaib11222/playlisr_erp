<?php
// READ-ONLY: boots Laravel, impersonates an admin, calls HomeController@index
// and prints total time + the slowest SQL queries. Writes nothing.
require __DIR__ . '/../vendor/autoload.php';
$app = require __DIR__ . '/../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Http\Kernel::class);
$request = Illuminate\Http\Request::create('/home', 'GET');
$app->instance('request', $request);
$kernel->bootstrap();
ini_set('memory_limit', '1024M');
set_time_limit(300);

$userId = (int) ($argv[1] ?? 0);
$user = $userId ? App\User::find($userId)
    : App\User::where('business_id', 1)->orderBy('id')->first();
$request->setLaravelSession($session = $app['session']->driver());
$session->start();
auth()->login($user);
$business = App\Business::find($user->business_id);
$session->put('user', ['id' => $user->id, 'business_id' => $user->business_id]);
$session->put('business', $business->toArray());
$session->put('financial_year', app(App\Utils\BusinessUtil::class)->getCurrentFinancialYear($user->business_id));
echo "user {$user->id} {$user->username}\n";

$queries = [];
DB::listen(function ($q) use (&$queries) {
    $queries[] = [$q->time, preg_replace('/\s+/', ' ', $q->sql), $q->bindings];
});
$t = microtime(true);
try {
    $resp = app(App\Http\Controllers\HomeController::class)->index();
    $html = $resp instanceof Illuminate\View\View ? $resp->render() : (string) $resp;
    echo "OK html=" . round(strlen($html) / 1024) . "KB\n";
} catch (Throwable $e) {
    echo "EXCEPTION " . get_class($e) . ': ' . substr($e->getMessage(), 0, 500) . ' @ ' . $e->getFile() . ':' . $e->getLine() . "\n";
}
$total = round((microtime(true) - $t) * 1000);
$sqlMs = round(array_sum(array_column($queries, 0)));
echo "TOTAL {$total}ms, queries=" . count($queries) . " sql={$sqlMs}ms peakMem=" . round(memory_get_peak_usage(true) / 1048576) . "MB\n";
usort($queries, fn($a, $b) => $b[0] <=> $a[0]);
foreach (array_slice($queries, 0, 25) as $q) {
    echo round($q[0]) . "ms  " . substr($q[1], 0, 600) . "  [" . substr(json_encode($q[2]), 0, 150) . "]\n";
}
// group by caller-ish signature
$groups = [];
foreach ($queries as $q) { $k = substr($q[1], 0, 120); $groups[$k][0] = ($groups[$k][0] ?? 0) + 1; $groups[$k][1] = ($groups[$k][1] ?? 0) + $q[0]; }
uasort($groups, fn($a, $b) => $b[1] <=> $a[1]);
echo "--- by shape ---\n";
foreach (array_slice($groups, 0, 15, true) as $k => $g) echo "x{$g[0]} " . round($g[1]) . "ms  $k\n";
