<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * eBay Marketplace Account Deletion / Closure notifications.
 *
 * Register in eBay Developer → Alerts & Notifications:
 *   Endpoint: https://playlist.nivessa.com/webhooks/ebay/marketplace-account-deletion
 *   Verification token: same value as EBAY_MARKETPLACE_DELETION_VERIFICATION_TOKEN in .env
 *
 * eBay validates with GET ?challenge_code=… — we respond with SHA-256 hex of
 * challengeCode + verificationToken + endpointUrl (exact string, in that order).
 */
class EbayMarketplaceDeletionController extends Controller
{
    public function handle(Request $request)
    {
        $challengeCode = $request->query('challenge_code');
        if ($challengeCode !== null && $challengeCode !== '') {
            return $this->handleChallenge($request, (string) $challengeCode);
        }

        if ($request->isMethod('POST')) {
            return $this->handleNotification($request);
        }

        Log::warning('eBay marketplace deletion: unexpected request', [
            'method' => $request->method(),
            'query' => $request->query(),
        ]);

        return response('Bad Request', 400);
    }

    protected function handleChallenge(Request $request, string $challengeCode)
    {
        $token = config('services.ebay.marketplace_deletion_verification_token', '');
        $endpoint = config('services.ebay.marketplace_deletion_endpoint_url', '');

        Log::info('eBay marketplace deletion: CHALLENGE received (save this in logs)', [
            'challenge_code' => $challengeCode,
            'verification_token_set' => $token !== '',
            'verification_token_length' => strlen($token),
            'endpoint_url_configured' => $endpoint,
            'request_ip' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'full_url' => $request->fullUrl(),
        ]);

        if ($token === '' || $endpoint === '') {
            Log::error('eBay marketplace deletion: missing .env — set EBAY_MARKETPLACE_DELETION_VERIFICATION_TOKEN and EBAY_MARKETPLACE_DELETION_ENDPOINT_URL (must match eBay portal exactly)');

            return response()->json([
                'error' => 'Endpoint not configured on server',
            ], 503);
        }

        $challengeResponse = hash('sha256', $challengeCode . $token . $endpoint);

        Log::info('eBay marketplace deletion: CHALLENGE response sent to eBay', [
            'challenge_code' => $challengeCode,
            'challengeResponse' => $challengeResponse,
            'hash_input_order' => 'challengeCode + verificationToken + endpointUrl',
        ]);

        return response()->json([
            'challengeResponse' => $challengeResponse,
        ], 200, [
            'Content-Type' => 'application/json',
        ]);
    }

    protected function handleNotification(Request $request)
    {
        Log::info('eBay marketplace deletion: NOTIFICATION received', [
            'ip' => $request->ip(),
            'headers' => [
                'x-ebay-signature' => $request->header('X-EBAY-SIGNATURE'),
                'content-type' => $request->header('Content-Type'),
            ],
            'body' => $request->getContent(),
            'json' => $request->json()->all(),
        ]);

        // Acknowledge immediately per eBay requirements. Implement GDPR-style
        // user data deletion here if you store eBay buyer PII in the ERP.
        return response('', 200);
    }
}
