<?php

namespace App\Http\Controllers;

use App\Utils\BusinessUtil;
use Illuminate\Http\Request;

/**
 * Third-party integration credentials that need to live on the WEBSITE
 * server (jonhedvat/server), not the ERP — e.g. OpenPhone. The website's
 * .env is hand-managed and never touched by deploys, so this page forwards
 * the value through the existing ERP↔website bridge (same key/base
 * resolution as everywhere else) instead of asking Sarah to SSH in.
 * Admin-only: these are live API credentials, not routine settings.
 */
class IntegrationSettingsController extends Controller
{
    protected $businessUtil;

    public function __construct(BusinessUtil $businessUtil)
    {
        $this->businessUtil = $businessUtil;
    }

    private function ensureAdmin(): void
    {
        if (!auth()->check() || !$this->businessUtil->is_admin(auth()->user())) {
            abort(403, 'Integration settings are admin-only.');
        }
    }

    public function openPhoneEdit()
    {
        $this->ensureAdmin();
        return view('integration_settings.openphone');
    }

    public function openPhoneSave(Request $request)
    {
        $this->ensureAdmin();

        $request->validate([
            'api_key' => 'required|string|max:255',
            'from_number' => 'required|string|max:20',
        ]);

        $apiKey = trim($request->input('api_key'));
        $fromNumber = trim($request->input('from_number'));
        // Tolerate a raw 10-digit US number typed without the country code.
        if (preg_match('/^\d{10}$/', $fromNumber)) {
            $fromNumber = '+1' . $fromNumber;
        }

        $sent = $this->pushToErpBridge('/erp/settings/openphone', [
            'apiKey' => $apiKey,
            'fromNumber' => $fromNumber,
            'updatedBy' => trim((string) optional(auth()->user())->first_name),
        ]);

        if (!$sent) {
            return redirect()->back()->with('error', 'Could not save — website bridge unreachable. Try again.');
        }

        return redirect()->back()->with('status', 'OpenPhone settings saved. Texting receipts is live.');
    }

    /**
     * Same base/key resolution as SellPosController's pushToErpBridge —
     * config, then .env, then .env off disk, then the UI-set store file.
     */
    private function pushToErpBridge(string $path, array $payload): bool
    {
        $base = trim((string) config('constants.nivessa_api'));
        if ($base === '') {
            $base = trim((string) env('NIVESSA_API', ''));
        }
        if ($base === '') {
            $base = 'https://nivessa.com/api/v1';
        }
        $base = rtrim($base, '/');

        $key = trim((string) config('constants.erp_api_key'));
        if ($key === '') {
            $key = trim((string) env('ERP_API_KEY', ''));
        }
        if ($key === '') {
            $key = $this->readErpKeyFromDisk();
        }
        if ($key === '') {
            $key = $this->readErpKeyFromStore();
        }
        if ($base === '' || $key === '') {
            \Log::warning('IntegrationSettingsController: website base/key not configured.');
            return false;
        }

        $ch = curl_init($base . $path);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 3,
            CURLOPT_TIMEOUT        => 8,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode($payload),
            CURLOPT_HTTPHEADER     => [
                'Accept: application/json',
                'Content-Type: application/json',
                'x-erp-key: ' . $key,
            ],
        ]);
        $resp = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);

        if ($code < 200 || $code >= 300) {
            \Log::warning('IntegrationSettingsController push failed: HTTP ' . $code . ($err ? " curl_err={$err}" : '') . ' body=' . substr((string) $resp, 0, 300));
            return false;
        }
        return true;
    }

    private function readErpKeyFromDisk(): string
    {
        try {
            $path = base_path('.env');
            if (!is_readable($path)) {
                return '';
            }
            foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
                if (strpos(ltrim($line), 'ERP_API_KEY=') === 0) {
                    return trim(trim(substr(ltrim($line), strlen('ERP_API_KEY='))), "\"'");
                }
            }
        } catch (\Throwable $e) {
            // fall through
        }
        return '';
    }

    private function readErpKeyFromStore(): string
    {
        try {
            $path = storage_path('app/events-bridge.json');
            if (!is_file($path)) {
                return '';
            }
            $j = json_decode((string) file_get_contents($path), true);
            return is_array($j) ? trim((string) ($j['erpApiKey'] ?? '')) : '';
        } catch (\Throwable $e) {
            return '';
        }
    }
}
