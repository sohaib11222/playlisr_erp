<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;

/**
 * Real-time POS-sale stock push to the nivessa.com website.
 *
 * The instant a POS sale (or edit) changes a product's quantity, we ping the
 * website so the storefront reflects it in SECONDS — instead of waiting for the
 * website's nightly POS stock sync to next pull the connector API. This closes
 * the ~overnight window where an item sold at the register still showed as
 * available online.
 *
 * Design notes:
 *  - We send only the affected POS product id(s). The website re-pulls each
 *    one's authoritative stock from the POS connector, so the POS stays the
 *    single source of truth and the website reuses its existing stock guards.
 *  - Fire-and-forget with short timeouts: a slow or down website must NEVER
 *    delay the register. If a push is lost (website down, network blip), the
 *    website's scheduled POS stock sync still reconciles it later — this is an
 *    accelerator, not the system of record.
 *  - Key/base resolution mirrors ProductController's Discogs image-refresh ping
 *    (ERP_API_KEY, sent as the `x-erp-key` header) so it works on the same
 *    hand-managed prod .env with no extra configuration.
 */
class NivessaStockNotifier
{
    /**
     * Notify the website that a POS sale changed stock. Only fires for FINAL
     * sell transactions — drafts and quotations don't move stock.
     */
    public function notifySale($transaction): void
    {
        try {
            if (!$transaction) {
                return;
            }
            if (($transaction->type ?? null) !== 'sell') {
                return;
            }
            if (($transaction->status ?? null) !== 'final') {
                return;
            }

            $this->push($this->productIdsForTransaction($transaction));
        } catch (\Throwable $e) {
            Log::info('NivessaStockNotifier::notifySale failed: ' . $e->getMessage());
        }
    }

    /** Collect distinct POS product ids from a transaction's sell lines. */
    private function productIdsForTransaction($transaction): array
    {
        $ids = [];
        try {
            $lines = $transaction->relationLoaded('sell_lines')
                ? $transaction->sell_lines
                : $transaction->sell_lines()->get();
            foreach ($lines as $line) {
                $pid = (int) ($line->product_id ?? 0);
                if ($pid > 0) {
                    $ids[$pid] = $pid;
                }
            }
        } catch (\Throwable $e) {
            Log::info('NivessaStockNotifier: could not read sell lines: ' . $e->getMessage());
        }
        return array_values($ids);
    }

    /**
     * POST the affected POS product ids to the website's ERP bridge. Public so
     * ad-hoc callers (e.g. returns, manual reconciles) can reuse it directly.
     */
    public function push(array $posProductIds): void
    {
        $posProductIds = array_values(array_unique(array_filter(
            array_map('intval', $posProductIds),
            static fn ($v) => $v > 0
        )));
        if (empty($posProductIds)) {
            return;
        }

        $base = $this->resolveBase();
        $key = $this->resolveKey();
        if ($base === '' || $key === '') {
            Log::info('NivessaStockNotifier: base/key not configured — skipping stock push for '
                . count($posProductIds) . ' product(s).');
            return;
        }

        $payload = json_encode(['pos_product_ids' => $posProductIds]);

        try {
            $ch = curl_init($base . '/erp/pos-stock-changed');
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_CONNECTTIMEOUT => 2,
                CURLOPT_TIMEOUT        => 4,
                CURLOPT_POST           => true,
                CURLOPT_POSTFIELDS     => $payload,
                CURLOPT_HTTPHEADER     => [
                    'Accept: application/json',
                    'Content-Type: application/json',
                    'x-erp-key: ' . $key,
                ],
            ]);
            $resp = curl_exec($ch);
            $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $err  = curl_error($ch);
            curl_close($ch);

            Log::info('NivessaStockNotifier push ' . count($posProductIds) . ' product(s) → HTTP ' . $code
                . ($err ? (' curl_err=' . $err) : '')
                . ' body=' . substr((string) $resp, 0, 200));
        } catch (\Throwable $e) {
            Log::info('NivessaStockNotifier push failed: ' . $e->getMessage());
        }
    }

    /** Website API base — same resolution as ProductController's image ping. */
    private function resolveBase(): string
    {
        $base = trim((string) config('constants.nivessa_api'));
        if ($base === '') {
            $base = trim((string) env('NIVESSA_API', ''));
        }
        if ($base === '') {
            $base = 'https://nivessa.com/api/v1';
        }
        return rtrim($base, '/');
    }

    /**
     * Shared ERP bridge key — resolved EXACTLY like the image ping: config, then
     * .env, then the .env file off disk (cache-proof), then the UI-set key in
     * storage/app/events-bridge.json. The prod .env is hand-managed, so on some
     * deployments the key only lives in that store file.
     */
    private function resolveKey(): string
    {
        $key = trim((string) config('constants.erp_api_key'));
        if ($key === '') {
            $key = trim((string) env('ERP_API_KEY', ''));
        }
        if ($key === '') {
            $key = $this->readKeyFromDisk();
        }
        if ($key === '') {
            $key = $this->readKeyFromStore();
        }
        return $key;
    }

    private function readKeyFromDisk(): string
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

    private function readKeyFromStore(): string
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
