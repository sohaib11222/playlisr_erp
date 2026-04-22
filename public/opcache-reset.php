<?php
// OPcache reset endpoint for the deploy pipeline.
//
// Why this exists: scripts/deploy.sh runs `php artisan optimize:clear`
// which clears CLI-side caches — but the compiled Blade templates live
// in PHP-FPM's OPcache, which is a SEPARATE in-memory cache the CLI
// can't reach. So after a deploy, FPM would keep serving the OLD
// bytecode of the compiled view files even though the source Blade
// templates on disk had been updated. Symptom: CSS / markup changes
// invisible on the live site until FPM restarted.
//
// This endpoint runs inside FPM, so calling opcache_reset() here
// clears the cache that actually matters. deploy.sh curls it right
// after optimize:clear.
//
// Auth: shared token against the server's APP_KEY (not exposed — only
// the deploy user and the server itself can read .env). Even if the
// token leaked, the worst case is an attacker clearing OPcache
// repeatedly (mild performance hit, no data exposure).

header('Content-Type: text/plain');

$envPath = __DIR__ . '/../.env';
$appKey = '';
if (is_readable($envPath)) {
    foreach (file($envPath, FILE_IGNORE_NEW_LINES) as $line) {
        if (strpos($line, 'APP_KEY=') === 0) {
            $appKey = substr($line, strlen('APP_KEY='));
            if (strlen($appKey) >= 2
                && $appKey[0] === '"'
                && substr($appKey, -1) === '"') {
                $appKey = substr($appKey, 1, -1);
            }
            break;
        }
    }
}

$token = $_GET['t'] ?? '';
if ($appKey === '' || $token === '' || !hash_equals($appKey, $token)) {
    http_response_code(403);
    echo "denied\n";
    exit;
}

if (function_exists('opcache_reset')) {
    $ok = opcache_reset();
    echo ($ok ? 'reset ok' : 'reset failed') . "\n";
} else {
    echo "opcache not enabled\n";
}
