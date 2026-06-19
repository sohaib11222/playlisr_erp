<?php

namespace App\Console\Commands;

use App\BusinessLocation;
use App\Services\DiscogsService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Pull Discogs marketplace orders (via DiscogsService::fetchOrders, which
 * uses the seller token already configured in Business Settings >
 * Integrations) and store them as ERP transactions on channel='discogs'.
 *
 * 'discogs' is already a valid channel enum value, so this needs no
 * migration. Each order → one transaction, one sell line per order item
 * against a 'Discogs Sale' placeholder product (item text preserved in
 * legacy_title; no stock impact — release_id ↔ ERP SKU mapping is a
 * separate concern).
 *
 * Idempotency: (import_source='discogs', import_external_id=<Discogs order id>).
 * Cancelled/invalid orders are skipped. Safe to re-run.
 *
 * Usage:
 *   php artisan nivessa:sync-discogs-sales            # dry-run last 120 days
 *   php artisan nivessa:sync-discogs-sales --days=30
 *   php artisan nivessa:sync-discogs-sales --commit   # actually write
 */
class SyncDiscogsSales extends Command
{
    protected $signature = 'nivessa:sync-discogs-sales
                            {--business=1 : business_id}
                            {--user=1 : created_by user_id}
                            {--days=120 : Only sync orders created within this many days}
                            {--commit : Actually write (default: dry-run)}';

    protected $description = 'Sync Discogs marketplace orders into ERP transactions (dry-run by default).';

    /** Discogs statuses that are NOT a completed sale — skip these. */
    const SKIP_STATUSES = ['cancelled', 'cancelled (per buyer\'s request)', 'cancelled (refund)', 'cancelled (non-paying)', 'invoice sent', 'invalid'];

    public function handle()
    {
        $businessId = (int) $this->option('business');
        $userId = (int) $this->option('user');
        $days = max(1, (int) $this->option('days'));
        $commit = (bool) $this->option('commit');

        $this->info($commit ? '✅ COMMIT mode — writing to DB.' : '🧪 DRY RUN — no rows written. Re-run with --commit.');

        $svc = new DiscogsService($businessId);
        if (!$svc->isConfigured()) {
            $this->error('Discogs API token not configured (Business Settings > Integrations). Aborting.');
            return 1;
        }

        $walkInContactId = $this->resolveWalkInContact($businessId);
        if (!$walkInContactId) { $this->error('No walk-in/customer contact found. Aborting.'); return 1; }
        $locationId = $this->resolveDiscogsLocation($businessId);
        if (!$locationId) { $this->error('No business location found. Aborting.'); return 1; }
        $this->line("Walk-in contact id: {$walkInContactId}  ·  location id: {$locationId}  ·  window: last {$days} day(s)");

        $createdAfter = now()->subDays($days)->toIso8601String();
        $placeholder = $commit ? $this->ensurePlaceholder($businessId, $userId, 'Discogs Sale', 'NIV-DISCOGS-SALE') : [0, 0];

        $totals = ['created' => 0, 'dup' => 0, 'skip' => 0, 'revenue_cents' => 0];

        for ($page = 1; $page <= 500; $page++) {
            $resp = $svc->fetchOrders($createdAfter, null, $page, 100);
            if (!is_array($resp) || isset($resp['error'])) {
                $this->error('  Discogs fetch failed: ' . ($resp['error'] ?? 'unknown') . (isset($resp['body']) ? ' — ' . substr((string) $resp['body'], 0, 200) : ''));
                break;
            }
            $orders = $resp['orders'] ?? [];
            if (empty($orders)) break;
            $this->line("  page {$page}: " . count($orders) . ' order(s)');

            foreach ($orders as $o) {
                $extId = (string) ($o['id'] ?? '');
                $status = strtolower(trim((string) ($o['status'] ?? '')));
                if ($extId === '' || in_array($status, self::SKIP_STATUSES, true) || strpos($status, 'cancel') !== false) {
                    $totals['skip']++; continue;
                }
                if ($this->alreadyImported($businessId, $extId)) { $totals['dup']++; continue; }

                $final = (float) (data_get($o, 'total.value') ?? 0);
                if ($final <= 0) { $totals['skip']++; continue; }

                $date = $this->parseDate($o['created'] ?? null);
                $paid = in_array($status, ['payment received', 'shipped', 'merged', 'payment pending (paypal)'], true)
                    || strpos($status, 'shipped') !== false || strpos($status, 'payment received') !== false;

                $note = trim('Discogs order ' . $extId
                    . (data_get($o, 'buyer.username') ? ' · buyer: ' . data_get($o, 'buyer.username') : '')
                    . ($status !== '' ? ' · ' . $status : ''));

                if ($commit) {
                    $this->writeSale([
                        'business_id' => $businessId, 'user_id' => $userId,
                        'contact_id' => $walkInContactId, 'location_id' => $locationId,
                        'external_id' => $extId, 'date' => $date,
                        'final_total' => $final, 'payment_status' => $paid ? 'paid' : 'due',
                        'note' => $note, 'placeholder' => $placeholder,
                        'lines' => $this->orderLines($o['items'] ?? [], $final),
                    ]);
                }
                $totals['created']++;
                $totals['revenue_cents'] += (int) round($final * 100);
            }

            $pages = (int) (data_get($resp, 'pagination.pages') ?? 0);
            if ($pages && $page >= $pages) break;
        }

        $this->line('');
        $this->info($commit ? '✅ Sync complete.' : '🧪 DRY RUN complete — re-run with --commit to write.');
        $this->line(sprintf('Discogs orders: created=%d dup=%d skipped=%d', $totals['created'], $totals['dup'], $totals['skip']));
        $this->line('Total revenue: $' . number_format($totals['revenue_cents'] / 100, 2));
        return 0;
    }

    private function orderLines(array $items, $orderTotal)
    {
        $lines = [];
        foreach ($items as $it) {
            $price = (float) (data_get($it, 'price.value') ?? 0);
            $title = (string) ($it['release']['description'] ?? data_get($it, 'release.title') ?? 'Discogs item');
            $lines[] = [
                'qty' => 1, 'unit_price' => round($price, 4), 'unit_price_inc_tax' => round($price, 4),
                'item_tax' => 0.0, 'title' => trim($title),
            ];
        }
        if (empty($lines)) {
            $lines[] = ['qty' => 1, 'unit_price' => round($orderTotal, 4), 'unit_price_inc_tax' => round($orderTotal, 4), 'item_tax' => 0.0, 'title' => 'Discogs order'];
        }
        return $lines;
    }

    private function alreadyImported($businessId, $extId)
    {
        return DB::table('transactions')
            ->where('business_id', $businessId)
            ->where('import_source', 'discogs')
            ->where('import_external_id', $extId)
            ->exists();
    }

    private function writeSale(array $s)
    {
        DB::beginTransaction();
        try {
            [$prodId, $varId] = $s['placeholder'];
            $txId = DB::table('transactions')->insertGetId([
                'business_id' => $s['business_id'],
                'type' => 'sell', 'status' => 'final', 'payment_status' => $s['payment_status'],
                'contact_id' => $s['contact_id'], 'location_id' => $s['location_id'],
                'channel' => 'discogs', 'source' => 'discogs',
                'transaction_date' => $s['date'] ? $s['date']->format('Y-m-d H:i:s') : now(),
                'total_before_tax' => round($s['final_total'], 4),
                'tax_amount' => 0.0, 'discount_amount' => 0.0,
                'final_total' => round($s['final_total'], 4),
                'additional_notes' => $s['note'] ?: null,
                'created_by' => $s['user_id'],
                'import_source' => 'discogs', 'import_external_id' => $s['external_id'],
                'created_at' => now(), 'updated_at' => now(),
            ]);

            foreach ($s['lines'] as $ln) {
                DB::table('transaction_sell_lines')->insert([
                    'transaction_id' => $txId,
                    'product_id' => $prodId, 'variation_id' => $varId,
                    'quantity' => $ln['qty'], 'unit_price' => $ln['unit_price'],
                    'unit_price_inc_tax' => $ln['unit_price_inc_tax'], 'item_tax' => $ln['item_tax'],
                    'import_source' => 'discogs', 'import_external_id' => $s['external_id'],
                    'legacy_title' => $ln['title'] ?: null,
                    'created_at' => now(), 'updated_at' => now(),
                ]);
            }

            if ($s['payment_status'] === 'paid') {
                DB::table('transaction_payments')->insert([
                    'transaction_id' => $txId, 'business_id' => $s['business_id'],
                    'amount' => round($s['final_total'], 4), 'method' => 'other',
                    'paid_on' => $s['date'] ? $s['date']->format('Y-m-d H:i:s') : now(),
                    'created_by' => $s['user_id'], 'payment_for' => $s['contact_id'],
                    'note' => $s['note'] ?: null,
                    'created_at' => now(), 'updated_at' => now(),
                ]);
            }

            DB::commit();
        } catch (\Throwable $e) {
            DB::rollBack();
            $this->error('  write failed for discogs:' . $s['external_id'] . ' — ' . $e->getMessage());
        }
    }

    private function resolveWalkInContact($businessId)
    {
        $c = DB::table('contacts')->where('business_id', $businessId)
            ->where('is_default', 1)->whereNull('deleted_at')->orderBy('id')->first();
        if ($c) return $c->id;
        $c = DB::table('contacts')->where('business_id', $businessId)
            ->whereIn('type', ['customer', 'both'])->whereNull('deleted_at')->orderBy('id')->first();
        return $c ? $c->id : null;
    }

    /** Prefer the 'Discogs Warehouse' location (from the inventory importer) if present. */
    private function resolveDiscogsLocation($businessId)
    {
        $loc = BusinessLocation::where('business_id', $businessId)
            ->where('name', 'like', '%Discogs%')->orderBy('id')->first();
        if ($loc) return $loc->id;
        $loc = BusinessLocation::where('business_id', $businessId)->orderBy('id')->first();
        return $loc ? $loc->id : null;
    }

    private function parseDate($raw)
    {
        if (empty($raw)) return null;
        try { return \Carbon\Carbon::parse($raw); } catch (\Throwable $e) { return null; }
    }

    private function ensurePlaceholder($businessId, $userId, $name, $sku)
    {
        $product = DB::table('products')->where('business_id', $businessId)->where('name', $name)->first();
        $productId = $product->id ?? DB::table('products')->insertGetId([
            'business_id' => $businessId, 'name' => $name, 'type' => 'single', 'sku' => $sku,
            'created_by' => $userId, 'enable_stock' => 0, 'is_inactive' => 0,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $variation = DB::table('variations')->where('product_id', $productId)->orderBy('id')->first();
        if ($variation) return [$productId, $variation->id];

        $pvId = DB::table('product_variations')->insertGetId([
            'product_id' => $productId, 'name' => 'DUMMY', 'is_dummy' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $varId = DB::table('variations')->insertGetId([
            'product_id' => $productId, 'product_variation_id' => $pvId, 'name' => 'DUMMY',
            'sub_sku' => $sku . '-0', 'default_purchase_price' => 0, 'dpp_inc_tax' => 0,
            'profit_percent' => 0, 'default_sell_price' => 0, 'sell_price_inc_tax' => 0,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        return [$productId, $varId];
    }
}
