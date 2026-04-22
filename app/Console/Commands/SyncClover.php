<?php

namespace App\Console\Commands;

use App\Business;
use App\Contact;
use App\Product;
use App\Services\CloverService;
use App\Transaction;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Umbrella bidirectional Clover ↔ ERP sync.
 *
 *   php artisan clover:sync                 # pull + push, all domains, since last run
 *   php artisan clover:sync --only=items    # scope to one domain (items|orders|customers|push)
 *   php artisan clover:sync --days=7        # force-rewalk N days instead of using the watermark
 *   php artisan clover:sync --business=1    # pin to one business_id
 *
 * Domains and direction:
 *   items      Clover → ERP    pull inventory, match on sku, link clover_item_id
 *   orders     Clover → ERP    pull locked orders, link transactions via clover_order_id
 *   customers  Clover → ERP    pull customer list into contacts
 *   push       ERP → Clover    push dirty products + contacts (updated_at > clover_synced_at)
 *
 * Payments continue to use the existing clover:sync-payments command; this
 * one is additive so we don't break the reconciliation report.
 *
 * Watermarks are stored in the Laravel cache under
 *   clover.sync.<business_id>.<domain>.last_run
 * so a brief outage doesn't leave a gap — the next run just rewinds to the
 * saved timestamp.
 */
class SyncClover extends Command
{
    protected $signature = 'clover:sync
                            {--only= : items|orders|customers|push  (default: all)}
                            {--days= : Re-walk the last N days instead of using the watermark}
                            {--business= : Specific business_id, defaults to all configured}';

    protected $description = 'Bidirectional Clover ↔ ERP sync — pulls items/orders/customers, pushes dirty products + contacts.';

    public function handle()
    {
        $only = strtolower((string) $this->option('only'));
        $days = $this->option('days') !== null ? max(1, (int) $this->option('days')) : null;

        $businessIds = $this->option('business')
            ? [(int) $this->option('business')]
            : Business::pluck('id')->all();

        foreach ($businessIds as $businessId) {
            $this->line("\n=== business_id={$businessId} ===");
            $probe = new CloverService($businessId);
            if (!$probe->isConfigured()) {
                $this->warn('  Clover not configured — skipped.');
                continue;
            }

            // A multi-store business may have one Clover merchant per
            // location (Nivessa: Hollywood + Pico). Walk each configured
            // scope and instantiate a location-scoped service so the right
            // merchant_id/private_token are used for each leg.
            $scopes = $probe->getConfiguredScopes() ?: [null];
            foreach ($scopes as $scope) {
                $scopeLabel = $scope === null ? 'top-level' : ('location #' . $scope);
                $this->line("  · scope: {$scopeLabel}");
                $clover = (new CloverService($businessId))->forLocation($scope);

                if ($only === '' || $only === 'items')     $this->syncItems($clover, $businessId, $scope, $days);
                if ($only === '' || $only === 'orders')    $this->syncOrders($clover, $businessId, $scope, $days);
                if ($only === '' || $only === 'customers') $this->syncCustomers($clover, $businessId, $scope, $days);
                if ($only === '' || $only === 'push')      $this->pushPending($clover, $businessId, $scope);
            }
        }

        return 0;
    }

    /* -------------------- items pull -------------------- */

    private function syncItems(CloverService $clover, int $businessId, $scope, ?int $days): void
    {
        $this->line('— items pull');
        $since = $this->watermark($businessId, $scope, 'items', $days);
        $result = $clover->getItems($since);
        if (empty($result['success'])) {
            $this->error('  ' . ($result['msg'] ?? 'failed'));
            return;
        }

        $linked = 0;
        foreach ($result['items'] as $it) {
            $cloverItemId = $it['id'] ?? null;
            $sku  = $it['sku'] ?? $it['code'] ?? null;
            if (!$cloverItemId) continue;

            // Match-by-link first, then by sku within this business.
            $product = Product::where('business_id', $businessId)
                ->where('clover_item_id', $cloverItemId)
                ->first();
            if (!$product && $sku) {
                $product = Product::where('business_id', $businessId)
                    ->where('sku', $sku)
                    ->first();
            }

            if ($product) {
                if (empty($product->clover_item_id)) {
                    $product->clover_item_id = $cloverItemId;
                    $linked++;
                }
                $product->clover_synced_at = now();
                $product->save();
            } else {
                // Unlinked item — log so Sarah can see what needs a matching
                // ERP product. We don't auto-create because Nivessa's product
                // schema has required joins (variations/units/taxes) that
                // aren't safe to materialize from a bare Clover item.
                Log::info('Clover item has no matching ERP product', [
                    'clover_item_id' => $cloverItemId,
                    'sku' => $sku,
                    'name' => $it['name'] ?? null,
                ]);
            }
        }
        $this->line("  fetched " . count($result['items']) . ", linked {$linked}");
        $this->setWatermark($businessId, $scope, 'items');
    }

    /* -------------------- orders pull -------------------- */

    private function syncOrders(CloverService $clover, int $businessId, $scope, ?int $days): void
    {
        $this->line('— orders pull');
        $start = $days
            ? Carbon::now()->subDays($days)
            : $this->watermark($businessId, $scope, 'orders', null);
        $end = Carbon::now();

        $result = $clover->getOrders($start, $end);
        if (empty($result['success'])) {
            $this->error('  ' . ($result['msg'] ?? 'failed'));
            return;
        }

        $linked = 0;
        foreach ($result['orders'] as $o) {
            $cloverOrderId = $o['id'] ?? null;
            if (!$cloverOrderId) continue;

            // Stamp the existing ERP transaction with the Clover order id if
            // it's already linked (idempotent refresh). Matching unlinked
            // ERP transactions to Clover orders is the reconciliation
            // report's job (it joins on clover_payments.amount + employee
            // + day) — we don't do a looser heuristic here because a wrong
            // link is worse than a missing one.
            $txn = Transaction::where('business_id', $businessId)
                ->where('clover_order_id', $cloverOrderId)
                ->first();
            if ($txn) {
                $txn->clover_synced_at = now();
                $txn->save();
                $linked++;
            }
        }
        $this->line('  fetched ' . count($result['orders']) . ", linked {$linked}");
        $this->setWatermark($businessId, $scope, 'orders');
    }

    /* -------------------- customers pull -------------------- */

    private function syncCustomers(CloverService $clover, int $businessId, $scope, ?int $days): void
    {
        $this->line('— customers pull');
        $offset = 0; $limit = 100; $touched = 0; $created = 0;
        while (true) {
            $resp = $clover->getCustomers($limit, $offset);
            if (empty($resp['success'])) {
                $this->error('  ' . ($resp['msg'] ?? 'failed'));
                break;
            }
            foreach ($resp['customers'] ?? [] as $c) {
                $cId = $c['id'] ?? null;
                if (!$cId) continue;

                $email = $c['emailAddresses'][0]['emailAddress'] ?? null;
                $phone = $c['phoneNumbers'][0]['phoneNumber'] ?? null;

                $existing = Contact::where('business_id', $businessId)
                    ->where(function ($q) use ($cId, $email, $phone) {
                        $q->where('clover_customer_id', $cId);
                        if ($email) $q->orWhere('email', $email);
                        if ($phone) $q->orWhere('mobile', $phone);
                    })
                    ->first();

                if ($existing) {
                    if (empty($existing->clover_customer_id)) {
                        $existing->clover_customer_id = $cId;
                    }
                    $existing->clover_synced_at = now();
                    $existing->save();
                    $touched++;
                } else {
                    $fn = $c['firstName'] ?? '';
                    $ln = $c['lastName'] ?? '';
                    $name = trim($fn . ' ' . $ln) ?: ($email ?? $phone ?? 'Clover Customer');
                    $addr = $c['addresses'][0] ?? [];
                    Contact::create([
                        'business_id' => $businessId,
                        'type' => 'customer',
                        'name' => $name,
                        'first_name' => $fn,
                        'last_name' => $ln,
                        'email' => $email,
                        'mobile' => $phone,
                        'address_line_1' => $addr['address1'] ?? null,
                        'address_line_2' => $addr['address2'] ?? null,
                        'city' => $addr['city'] ?? null,
                        'state' => $addr['state'] ?? null,
                        'zip_code' => $addr['zip'] ?? null,
                        'country' => $addr['country'] ?? null,
                        'clover_customer_id' => $cId,
                        'clover_synced_at' => now(),
                        'created_by' => optional(auth()->user())->id,
                    ]);
                    $created++;
                }
            }
            $count = count($resp['customers'] ?? []);
            if ($count < $limit) break;
            $offset += $limit;
            if ($offset > 10000) break;  // safety
        }
        $this->line("  touched {$touched}, created {$created}");
        $this->setWatermark($businessId, $scope, 'customers');
    }

    /* -------------------- ERP → Clover push -------------------- */

    private function pushPending(CloverService $clover, int $businessId, $scope): void
    {
        $this->line('— push pending (ERP → Clover)');

        // Products: push any row where updated_at > clover_synced_at
        // (or clover_synced_at is null and it has an sku).
        $products = Product::where('business_id', $businessId)
            ->whereNotNull('sku')
            ->where(function ($q) {
                $q->whereNull('clover_synced_at')
                  ->orWhereColumn('updated_at', '>', 'clover_synced_at');
            })
            ->limit(500)  // per-run cap, avoids a cold-start flood
            ->get();

        $pushedItems = 0;
        foreach ($products as $p) {
            $variation = DB::table('variations')
                ->where('product_id', $p->id)
                ->whereNull('deleted_at')
                ->orderBy('id')
                ->first();
            $price = $variation->sell_price_inc_tax ?? $variation->default_sell_price ?? 0;

            $payload = [
                'name'  => $p->name,
                'sku'   => $p->sku,
                'price' => (float) $price,
            ];

            if (!empty($p->clover_item_id)) {
                $resp = $clover->updateItem($p->clover_item_id, $payload);
            } else {
                $resp = $clover->createItem($payload);
                if (!empty($resp['success']) && !empty($resp['clover_item_id'])) {
                    $p->clover_item_id = $resp['clover_item_id'];
                }
            }
            if (!empty($resp['success'])) {
                $p->clover_synced_at = now();
                $p->save();
                $pushedItems++;
            } else {
                Log::warning('Clover push item failed', [
                    'product_id' => $p->id, 'msg' => $resp['msg'] ?? 'unknown',
                ]);
            }
        }

        // Contacts: same dirty-check, customers only.
        $contacts = Contact::where('business_id', $businessId)
            ->where('type', 'customer')
            ->where(function ($q) {
                $q->whereNull('clover_synced_at')
                  ->orWhereColumn('updated_at', '>', 'clover_synced_at');
            })
            ->whereNotNull('name')
            ->limit(500)
            ->get();

        $pushedContacts = 0;
        foreach ($contacts as $c) {
            $payload = [
                'first_name' => $c->first_name,
                'last_name'  => $c->last_name,
                'email'      => $c->email,
                'mobile'     => $c->mobile,
                'address_line_1' => $c->address_line_1,
                'address_line_2' => $c->address_line_2,
                'city'   => $c->city,
                'state'  => $c->state,
                'zip_code' => $c->zip_code,
                'country'  => $c->country,
            ];
            if (!empty($c->clover_customer_id)) {
                $resp = $clover->updateCustomer($c->clover_customer_id, $payload);
            } else {
                $resp = $clover->createCustomer($payload);
                if (!empty($resp['success']) && !empty($resp['clover_customer_id'])) {
                    $c->clover_customer_id = $resp['clover_customer_id'];
                }
            }
            if (!empty($resp['success'])) {
                $c->clover_synced_at = now();
                $c->save();
                $pushedContacts++;
            } else {
                Log::warning('Clover push customer failed', [
                    'contact_id' => $c->id, 'msg' => $resp['msg'] ?? 'unknown',
                ]);
            }
        }

        $this->line("  pushed {$pushedItems} items, {$pushedContacts} customers");
    }

    /* -------------------- watermark helpers -------------------- */

    /**
     * Return a Carbon pointing at (a) N days ago if --days was given,
     * (b) the cached last-run minus a small 5-minute overlap so we don't
     * miss straggler updates, or (c) 2 days ago if there's no cache entry.
     */
    private function watermark(int $businessId, $scope, string $domain, ?int $days): Carbon
    {
        if ($days) return Carbon::now()->subDays($days);
        $cached = Cache::get($this->watermarkKey($businessId, $scope, $domain));
        if ($cached) return Carbon::parse($cached)->subMinutes(5);
        return Carbon::now()->subDays(2);
    }

    private function setWatermark(int $businessId, $scope, string $domain): void
    {
        Cache::forever(
            $this->watermarkKey($businessId, $scope, $domain),
            Carbon::now()->toIso8601String()
        );
    }

    private function watermarkKey(int $businessId, $scope, string $domain): string
    {
        $scopeKey = $scope === null ? 'top' : ('loc' . (int) $scope);
        return "clover.sync.{$businessId}.{$scopeKey}.{$domain}.last_run";
    }
}
