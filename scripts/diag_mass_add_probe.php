<?php
// READ-ONLY diagnostic. No writes. Identifies who/what creates mass_add
// products, the account's login flag, the import cadence (the "timer"),
// and any live session's IP + user-agent (the external caller).

echo "=== creators of added_via=mass_add (last 14 days) ===\n";
$creators = DB::table('products')
    ->where('added_via', 'mass_add')
    ->where('created_at', '>=', now()->subDays(14))
    ->select('created_by', DB::raw('COUNT(*) as c'),
             DB::raw('MIN(created_at) as first_at'),
             DB::raw('MAX(created_at) as last_at'))
    ->groupBy('created_by')
    ->orderByDesc('c')
    ->get();
$ids = [];
foreach ($creators as $r) {
    $ids[] = $r->created_by;
    $u = DB::table('users')->where('id', $r->created_by)->first();
    $name = $u ? trim(($u->first_name ?? '') . ' ' . ($u->last_name ?? $u->surname ?? '')) : '(missing user)';
    $login = $u ? ($u->allow_login ?? '?') : '?';
    echo "USER\tid={$r->created_by}\tname={$name}\tallow_login={$login}\tcount={$r->c}\tfirst={$r->first_at}\tlast={$r->last_at}\n";
}

echo "=== cadence: mass_add rows per hour-slot (last 7 days) ===\n";
$slots = DB::table('products')
    ->where('added_via', 'mass_add')
    ->where('created_at', '>=', now()->subDays(7))
    ->select(DB::raw("DATE_FORMAT(created_at, '%Y-%m-%d %H:00') as slot"), DB::raw('COUNT(*) as c'))
    ->groupBy('slot')->orderBy('slot')->get();
foreach ($slots as $r) {
    echo "SLOT\t{$r->slot}\t{$r->c}\n";
}

echo "=== live sessions for those accounts (if DB session driver) ===\n";
try {
    if (!empty($ids) && \Schema::hasTable('sessions')) {
        $sess = DB::table('sessions')->whereIn('user_id', $ids)
            ->select('user_id', 'ip_address', 'user_agent', 'last_activity')
            ->orderByDesc('last_activity')->limit(15)->get();
        if ($sess->isEmpty()) {
            echo "SESSION\tno rows (file/redis session driver, or no active sessions)\n";
        }
        foreach ($sess as $r) {
            echo "SESSION\tuid={$r->user_id}\tip={$r->ip_address}\tlast=" . date('c', (int) $r->last_activity)
                . "\tua=" . substr((string) $r->user_agent, 0, 140) . "\n";
        }
    } else {
        echo "SESSION\tsessions table not present / no creator ids\n";
    }
} catch (\Throwable $e) {
    echo "SESSION\terror: " . $e->getMessage() . "\n";
}
echo "DONE\n";
