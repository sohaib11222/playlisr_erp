<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

/**
 * Guards the /api/v1/nivessa-web/* routes with a shared bearer token.
 *
 * The website API (jonhedvat/server) is the only caller; it reads
 * NIVESSA_ERP_API_TOKEN from its own env and sends it as
 * `Authorization: Bearer <token>`. Constant-time compare avoids leaking
 * token length/content through timing.
 */
class VerifyNivessaWebToken
{
    public function handle(Request $request, Closure $next)
    {
        $expected = (string) config('services.nivessa_web.api_token');
        if ($expected === '') {
            return response()->json(['error' => 'nivessa_web bridge not configured'], 503);
        }

        $header = (string) $request->header('Authorization', '');
        $provided = preg_match('/^Bearer\s+(.+)$/i', $header, $m) ? trim($m[1]) : '';

        if ($provided === '' || !hash_equals($expected, $provided)) {
            return response()->json(['error' => 'unauthorized'], 401);
        }

        return $next($request);
    }
}
