<?php

namespace App\Console\Commands;

use App\BusinessLocation;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Pull nivessa.com web orders + space-rental (venue booking) sales from the
 * website backend (jonhedvat/server) and store them as real ERP
 * transactions, so the ERP — not a live API pull — is the source of truth.
 *
 *   Web orders   → channel = 'web',          source = 'nivessa_web'
 *   Space rentals→ channel = 'space_rental',  source = 'nivessa_rental'
 *
 * Both go in as type=sell, status=final, paid. Each gets one sell line per
 * item against a per-channel placeholder product (no stock impact — the web
 * store and ERP don't share product identity, so we preserve the item text
 * in legacy_title rather than guess a product_id).
 *
 * Idempotency: (import_source, import_external_id) on transactions. The
 * external id is the Mongo order _id / bookingNumber, so re-running only
 * inserts orders not seen before. Safe to run as often as you like.
 *
 * Config (config/nivessa.php ← .env):
 *   NIVESSA_WEBSITE_API_URL  (default https://nivessa.com)
 *   NIVESSA_WEBSITE_API_KEY  (sent as X-API-Key)
 *
 * Usage:
 *   php artisan nivessa:sync-web-sales              # dry-run last 120 days
 *   php artisan nivessa:sync-web-sales --days=30    # dry-run last 30 days
 *   php artisan nivessa:sync-web-sales --commit     # actually write
 */
class SyncNivessaWebSales extends Command
{
    protected $signature = 'nivessa:sync-web-sales
                            {--business=1 : business_id}
                            {--user=1 : created_by user_id}
                            {--days=120 : Only sync orders/bookings newer than this many days}
                            {--commit : Actually write (default: dry-run)}';

    protected $description = 'Sync nivessa.com web orders + space rentals into ERP transactions (dry-run by default).';

    public function handle()
    {
        $businessId = (int) $this->option('business');
        $userId = (int) $this->option('user');
        $days = max(1, (int) $this->option('days'));
        $commit = (bool) $this->option('commit');

        $base = rtrim((string) config('nivessa.website_api_url', 'https://nivessa.com'), '/');
        $key = trim((string) config('nivessa.website_api_key', ''));

        $this->info($commit ? '✅ COMMIT mode — writing to DB.' : '🧪 DRY RUN — no rows written. Re-run with --commit.');
        $this->line("Website: {$base}  ·  window: last {$days} day(s)  ·  X-API-Key: " . ($key === '' ? 'NOT SET' : 'set'));

        $walkInContactId = $this->resolveWalkInContact($businessId);
        if (!$walkInContactId) {
            $this->error('No walk-in / customer contact found for business ' . $businessId . ' — cannot satisfy contact_id. Aborting.');
            return 1;
        }
        $defaultLocationId = $this->resolveDefaultLocation($businessId);
        if (!$defaultLocationId) {
            $this->error('No business location found for business ' . $businessId . '. Aborting.');
            return 1;
        }
        $storeLocations = $this->buildLocationCache($businessId);
        $this->line("Walk-in contact id: {$walkInContactId}  ·  default location id: {$defaultLocationId}");

        $cutoff = now()->subDays($days);

        $totals = ['orders' => 0, 'order_dup' => 0, 'order_skip' => 0, 'rentals' => 0, 'rental_dup' => 0, 'rental_skip' => 0, 'revenue_cents' => 0];

        // ---- Web orders ----------------------------------------------------
        $this->line('');
        $this->line('— Web orders —');
        $orders = $this->httpGetJson($base . '/api/v1/order/all?payment_status=completed', $key);
        if ($orders === null) {
            $this->error('  Could not fetch /api/v1/order/all (see above). Skipping web orders.');
        } else {
            $orders = $this->unwrapList($orders, ['orders', 'data']);
            $this->line('  fetched ' . count($orders) . ' completed order(s)');
            $placeholder = $commit ? $this->ensurePlaceholder($businessId, $userId, 'Nivessa Web Sale', 'NIV-WEB-SALE') : [0, 0];
            foreach ($orders as $o) {
                $createdAt = $this->parseDate($o['createdAt'] ?? $o['created_at'] ?? null);
                if ($createdAt && $createdAt->lt($cutoff)) { $totals['order_skip']++; continue; }

                $extId = (string) ($o['_id'] ?? $o['id'] ?? '');
                if ($extId === '') { $totals['order_skip']++; continue; }

                if ($this->alreadyImported($businessId, 'nivessa_web', $extId)) { $totals['order_dup']++; continue; }

                $final = (float) ($o['total_amount'] ?? $o['total'] ?? 0);
                if ($final <= 0) { $totals['order_skip']++; continue; }

                // Pickup orders map to the picked-up store; shipping → default.
                $locId = $defaultLocationId;
                $pickup = strtolower((string) ($o['pickup_location'] ?? ''));
                if ($pickup !== '') {
                    foreach ($storeLocations as $name => $id) {
                        if (strpos($name, $pickup) !== false) { $locId = $id; break; }
                    }
                }

                $items = is_array($o['items'] ?? null) ? $o['items'] : [];
                $note = trim('Web order ' . $extId
                    . (!empty($o['fulfillment_method']) ? ' · ' . $o['fulfillment_method'] : '')
                    . ($pickup !== '' ? ' · pickup: ' . $pickup : '')
                    . (!empty($o['paymentMethod']) ? ' · ' . $o['paymentMethod'] : ''));

                if ($commit) {
                    $this->writeSale([
                        'business_id' => $businessId, 'user_id' => $userId,
                        'contact_id' => $walkInContactId, 'location_id' => $locId,
                        'channel' => 'web', 'source' => 'nivessa_web',
                        'import_source' => 'nivessa_web', 'external_id' => $extId,
                        'date' => $createdAt, 'final_total' => $final, 'tax_amount' => 0.0,
                        'discount_amount' => (float) ($o['total_discount'] ?? 0),
                        'pay_method' => $this->mapWebPayment($o['paymentMethod'] ?? ''),
                        'note' => $note,
                        'placeholder' => $placeholder,
                        'lines' => $this->webOrderLines($items),
                    ]);
                }
                $totals['orders']++;
                $totals['revenue_cents'] += (int) round($final * 100);
            }
        }

        // ---- Space rentals (venue bookings) --------------------------------
        $this->line('');
        $this->line('— Space rentals —');
        $bookings = $this->fetchAllBookings($base, $key);
        if ($bookings === null) {
            $this->error('  Could not fetch /api/v1/bookings (see above). Skipping space rentals.');
        } else {
            $this->line('  fetched ' . count($bookings) . ' booking(s)');
            $placeholder = $commit ? $this->ensurePlaceholder($businessId, $userId, 'Space Rental', 'NIV-SPACE-RENTAL') : [0, 0];
            foreach ($bookings as $b) {
                $payStatus = strtolower((string) (data_get($b, 'payment.status') ?? ''));
                if (!in_array($payStatus, ['paid', 'partial'], true)) { $totals['rental_skip']++; continue; }

                $createdAt = $this->parseDate($b['createdAt'] ?? data_get($b, 'booking.date') ?? null);
                if ($createdAt && $createdAt->lt($cutoff)) { $totals['rental_skip']++; continue; }

                $extId = (string) ($b['bookingNumber'] ?? $b['_id'] ?? '');
                if ($extId === '') { $totals['rental_skip']++; continue; }
                if ($this->alreadyImported($businessId, 'nivessa_rental', $extId)) { $totals['rental_dup']++; continue; }

                $final = (float) (data_get($b, 'pricing.total') ?? data_get($b, 'payment.paidAmount') ?? 0);
                if ($final <= 0) { $totals['rental_skip']++; continue; }
                $tax = (float) (data_get($b, 'pricing.tax') ?? 0);
                $subtotal = (float) (data_get($b, 'pricing.subtotal') ?? ($final - $tax));

                // Map the venue's store to an ERP location when present.
                $locId = $defaultLocationId;
                $store = strtolower((string) (data_get($b, 'venue.location.store') ?? ''));
                if ($store !== '') {
                    foreach ($storeLocations as $name => $id) {
                        if (strpos($name, $store) !== false) { $locId = $id; break; }
                    }
                }

                $title = trim((string) (data_get($b, 'eventDetails.title') ?: data_get($b, 'venue.name') ?: 'Space Rental'));
                $note = trim('Booking ' . $extId
                    . (data_get($b, 'venue.name') ? ' · ' . data_get($b, 'venue.name') : '')
                    . (data_get($b, 'booking.date') ? ' · ' . data_get($b, 'booking.date') : ''));

                if ($commit) {
                    $this->writeSale([
                        'business_id' => $businessId, 'user_id' => $userId,
                        'contact_id' => $walkInContactId, 'location_id' => $locId,
                        'channel' => 'space_rental', 'source' => 'nivessa_rental',
                        'import_source' => 'nivessa_rental', 'external_id' => $extId,
                        'date' => $createdAt, 'final_total' => $final, 'tax_amount' => $tax,
                        'discount_amount' => (float) (data_get($b, 'pricing.discount.amount') ?? 0),
                        'pay_method' => $this->mapBookingPayment((string) (data_get($b, 'payment.method') ?? '')),
                        'note' => $note,
                        'placeholder' => $placeholder,
                        'lines' => [[
                            'qty' => 1, 'unit_price' => round($subtotal, 4), 'unit_price_inc_tax' => round($final, 4),
                            'item_tax' => round($tax, 4), 'title' => $title,
                        ]],
                    ]);
                }
                $totals['rentals']++;
                $totals['revenue_cents'] += (int) round($final * 100);
            }
        }

        // ---- Summary -------------------------------------------------------
        $this->line('');
        $this->info($commit ? '✅ Sync complete.' : '🧪 DRY RUN complete — re-run with --commit to write.');
        $this->line(sprintf('Web orders:   created=%d dup=%d skipped=%d', $totals['orders'], $totals['order_dup'], $totals['order_skip']));
        $this->line(sprintf('Space rentals: created=%d dup=%d skipped=%d', $totals['rentals'], $totals['rental_dup'], $totals['rental_skip']));
        $this->line('Total revenue: $' . number_format($totals['revenue_cents'] / 100, 2));
        return 0;
    }

    /* ===================== HTTP ===================== */

    private function httpGetJson($url, $key)
    {
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_TIMEOUT => 60,
            CURLOPT_HTTPHEADER => array_filter([
                'Accept: application/json',
                $key !== '' ? 'X-API-Key: ' . $key : null,
            ]),
        ]);
        $body = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);
        if ($err) { $this->error("  HTTP error: {$err}"); return null; }
        if ($code !== 200) { $this->error("  HTTP {$code} from {$url}"); return null; }
        $data = json_decode($body, true);
        if (!is_array($data)) { $this->error('  Invalid JSON from ' . $url); return null; }
        return $data;
    }

    /** Bookings are paginated (page/limit). Walk pages until exhausted. */
    private function fetchAllBookings($base, $key)
    {
        $all = [];
        for ($page = 1; $page <= 200; $page++) {
            $resp = $this->httpGetJson($base . '/api/v1/bookings/?page=' . $page . '&limit=100', $key);
            if ($resp === null) { return $page === 1 ? null : $all; }
            $list = $this->unwrapList($resp, ['bookings', 'data']);
            if (empty($list)) break;
            $all = array_merge($all, $list);
            if (count($list) < 100) break;
        }
        return $all;
    }

    /** Accept either a bare array of records or {key: [...]} envelope. */
    private function unwrapList($data, array $keys)
    {
        if (array_key_exists(0, $data)) return $data;
        foreach ($keys as $k) {
            if (isset($data[$k]) && is_array($data[$k])) return $data[$k];
        }
        return [];
    }

    private function mapWebPayment($m)
    {
        $m = strtolower(trim((string) $m));
        if (in_array($m, ['stripe', 'paypal', 'card'], true)) return 'card';
        return 'other';
    }

    private function mapBookingPayment($m)
    {
        $m = strtolower(trim((string) $m));
        if (in_array($m, ['card', 'stripe', 'paypal', 'apple_google'], true)) return 'card';
        if ($m === 'cash') return 'cash';
        if ($m === 'check' || $m === 'cheque') return 'cheque';
        if ($m === 'wire') return 'bank_transfer';
        return 'other';
    }

    private function webOrderLines(array $items)
    {
        $lines = [];
        foreach ($items as $it) {
            $qty = (float) ($it['quantity'] ?? 1);
            $price = (float) ($it['price'] ?? $it['gift_card_amount'] ?? 0);
            $title = $it['is_gift_card'] ?? false
                ? 'Gift Card'
                : (string) (data_get($it, 'product_id.name') ?: data_get($it, 'product.name') ?: 'Web item');
            $lines[] = [
                'qty' => $qty ?: 1,
                'unit_price' => round($price, 4),
                'unit_price_inc_tax' => round($price, 4),
                'item_tax' => 0.0,
                'title' => trim($title),
            ];
        }
        if (empty($lines)) {
            $lines[] = ['qty' => 1, 'unit_price' => 0.0, 'unit_price_inc_tax' => 0.0, 'item_tax' => 0.0, 'title' => 'Web item'];
        }
        return $lines;
    }

    /* ===================== DB writes ===================== */

    private function alreadyImported($businessId, $importSource, $extId)
    {
        return DB::table('transactions')
            ->where('business_id', $businessId)
            ->where(function ($q) use ($importSource, $extId) {
                // (a) Already imported by a prior run of this command.
                $q->where(function ($q2) use ($importSource, $extId) {
                    $q2->where('import_source', $importSource)
                       ->where('import_external_id', $extId);
                });
                // (b) Web orders only: the website already pushes each paid
                // order into the ERP LIVE against the real product (stock
                // decrements), noting it "Website order <id>". Skip so this
                // command never adds a second placeholder copy of the same
                // order - that was the duplicate row in the transactions list.
                if ($importSource === 'nivessa_web') {
                    $q->orWhere('additional_notes', 'Website order ' . $extId)
                      ->orWhere('additional_notes', 'like', 'Website order ' . $extId . '%');
                }
            })
            ->exists();
    }

    /** Insert one sale + its sell lines + a single payment line, all in a tx. */
    private function writeSale(array $s)
    {
        DB::beginTransaction();
        try {
            [$prodId, $varId] = $s['placeholder'];
            $txId = DB::table('transactions')->insertGetId([
                'business_id' => $s['business_id'],
                'type' => 'sell', 'status' => 'final', 'payment_status' => 'paid',
                'contact_id' => $s['contact_id'], 'location_id' => $s['location_id'],
                'channel' => $s['channel'], 'source' => $s['source'],
                'transaction_date' => $s['date'] ? $s['date']->format('Y-m-d H:i:s') : now(),
                'total_before_tax' => round($s['final_total'] - $s['tax_amount'], 4),
                'tax_amount' => round($s['tax_amount'], 4),
                'discount_amount' => round($s['discount_amount'], 4),
                'final_total' => round($s['final_total'], 4),
                'additional_notes' => $s['note'] ?: null,
                'created_by' => $s['user_id'],
                'import_source' => $s['import_source'], 'import_external_id' => $s['external_id'],
                'created_at' => now(), 'updated_at' => now(),
            ]);

            foreach ($s['lines'] as $ln) {
                DB::table('transaction_sell_lines')->insert([
                    'transaction_id' => $txId,
                    'product_id' => $prodId, 'variation_id' => $varId,
                    'quantity' => $ln['qty'],
                    'unit_price' => $ln['unit_price'],
                    'unit_price_inc_tax' => $ln['unit_price_inc_tax'],
                    'item_tax' => $ln['item_tax'],
                    'import_source' => $s['import_source'], 'import_external_id' => $s['external_id'],
                    'legacy_title' => $ln['title'] ?: null,
                    'created_at' => now(), 'updated_at' => now(),
                ]);
            }

            DB::table('transaction_payments')->insert([
                'transaction_id' => $txId,
                'business_id' => $s['business_id'],
                'amount' => round($s['final_total'], 4),
                'method' => $s['pay_method'],
                'paid_on' => $s['date'] ? $s['date']->format('Y-m-d H:i:s') : now(),
                'created_by' => $s['user_id'],
                'payment_for' => $s['contact_id'],
                'note' => $s['note'] ?: null,
                'created_at' => now(), 'updated_at' => now(),
            ]);

            DB::commit();
        } catch (\Throwable $e) {
            DB::rollBack();
            $this->error('  write failed for ' . $s['import_source'] . ':' . $s['external_id'] . ' — ' . $e->getMessage());
        }
    }

    /* ===================== Lookups / bootstrap ===================== */

    private function resolveWalkInContact($businessId)
    {
        $c = DB::table('contacts')->where('business_id', $businessId)
            ->where('is_default', 1)->whereNull('deleted_at')->orderBy('id')->first();
        if ($c) return $c->id;
        $c = DB::table('contacts')->where('business_id', $businessId)
            ->whereIn('type', ['customer', 'both'])->whereNull('deleted_at')->orderBy('id')->first();
        return $c ? $c->id : null;
    }

    private function resolveDefaultLocation($businessId)
    {
        $loc = BusinessLocation::where('business_id', $businessId)->orderBy('id')->first();
        return $loc ? $loc->id : null;
    }

    private function buildLocationCache($businessId)
    {
        $out = [];
        foreach (BusinessLocation::where('business_id', $businessId)->get() as $loc) {
            $out[strtolower($loc->name)] = $loc->id;
        }
        return $out;
    }

    private function parseDate($raw)
    {
        if (empty($raw)) return null;
        try { return \Carbon\Carbon::parse($raw); } catch (\Throwable $e) { return null; }
    }

    /** Ensure a placeholder product + its dummy variation exist; return [productId, variationId]. */
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
