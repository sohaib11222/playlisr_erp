<?php

namespace App\Console\Commands;

use App\Business;
use App\Contact;
use App\Services\CloverService;
use Carbon\Carbon;
use Illuminate\Console\Command;

/**
 * Read-only pull of Clover built-in Rewards data into the ERP's contact
 * loyalty fields.
 *
 * For every contact with a linked clover_customer_id we refresh:
 *   - loyalty_points        (from Clover customer.metadata)
 *   - lifetime_purchases    (sum of Clover order totals, dollars)
 *   - last_purchase_date    (most recent Clover order)
 *
 * The ERP stays the source of truth for redemptions / tier rules — this
 * command only mirrors Clover state so reporting, the POS customer widget,
 * and the loyalty tier computation see the up-to-date balance.
 *
 * Idempotent; safe to run repeatedly. Does not write anything back to
 * Clover.
 */
class SyncCloverCustomerRewards extends Command
{
    protected $signature = 'clover:sync-customer-rewards
                            {--business= : Specific business_id, defaults to all configured}
                            {--location= : Specific BusinessLocation id to scope Clover creds}
                            {--contact= : Only sync this ERP contact id (debug)}
                            {--limit= : Cap the number of contacts processed this run}
                            {--dry-run : Show what would change without writing}';

    protected $description = 'Read-only pull of Clover customer rewards + order history into contacts.loyalty_points / lifetime_purchases / last_purchase_date.';

    public function handle()
    {
        $businessIds = $this->resolveBusinessIds();
        if (empty($businessIds)) {
            $this->warn('No businesses to sync. Pass --business=X or configure Clover on at least one business.');
            return 0;
        }

        $grandUpdated = 0;
        $grandSkipped = 0;
        $grandMissing = 0;

        foreach ($businessIds as $businessId) {
            $this->line("\n— business_id={$businessId}");

            $scopeService = new CloverService($businessId);
            if (!$scopeService->isConfigured()) {
                $this->warn('  skipped: Clover not configured for this business.');
                continue;
            }

            // Respect a --location override; otherwise sync every configured
            // Clover scope (top-level creds + each per-location merchant) so
            // Hollywood and Pico both get their rosters pulled in one run.
            $overrideLoc = $this->option('location');
            $scopes = $overrideLoc !== null ? [(int) $overrideLoc] : $scopeService->getConfiguredScopes();
            if (empty($scopes)) {
                $this->warn('  skipped: no Clover scopes resolved.');
                continue;
            }

            // Build one combined roster across all scopes — a customer might
            // exist in either merchant, and the contact's clover_customer_id
            // doesn't remember which one. Later-scope entries win on
            // collision, which is fine (they'd be identical data anyway).
            $roster = [];
            foreach ($scopes as $locId) {
                $scopeLabel = $locId === null ? 'top-level' : ('location#' . $locId);
                $svc = (new CloverService($businessId))->forLocation($locId);
                $rosterResult = $svc->getAllCustomersExpanded(100);
                if (empty($rosterResult['success'])) {
                    $this->warn("  roster pull warning ({$scopeLabel}): " . ($rosterResult['msg'] ?? 'unknown'));
                }
                foreach ($rosterResult['customers'] ?? [] as $c) {
                    if (!empty($c['id'])) {
                        $roster[$c['id']] = ['locId' => $locId, 'customer' => $c];
                    }
                }
                $this->line("  {$scopeLabel}: " . count($rosterResult['customers'] ?? []) . ' customers');
            }

            $query = Contact::where('business_id', $businessId)
                ->whereNotNull('clover_customer_id')
                ->where('clover_customer_id', '!=', '');

            if ($contactId = $this->option('contact')) {
                $query->where('id', (int) $contactId);
            }
            if ($limit = $this->option('limit')) {
                $query->limit((int) $limit);
            }

            $contacts = $query->get();
            $this->line('  contacts linked to Clover: ' . $contacts->count());

            foreach ($contacts as $contact) {
                $cloverId = $contact->clover_customer_id;
                $entry = $roster[$cloverId] ?? null;
                $cloverCustomer = $entry['customer'] ?? null;
                $customerLocId = $entry['locId'] ?? null;

                if ($cloverCustomer === null) {
                    // Not in any roster — fall back to a direct lookup per
                    // scope until one returns a hit. Handles customers
                    // created after the page cap and flags customers that
                    // were deleted on Clover's side.
                    $found = false;
                    foreach ($scopes as $locId) {
                        $svc = (new CloverService($businessId))->forLocation($locId);
                        $single = $svc->getCustomer($cloverId);
                        if (!empty($single['success'])) {
                            $cloverCustomer = $single['customer'];
                            $customerLocId = $locId;
                            $found = true;
                            break;
                        }
                    }
                    if (!$found) {
                        $this->line("    contact #{$contact->id} ({$contact->name}) — not found in Clover");
                        $grandMissing++;
                        continue;
                    }
                }

                // Pull reward points + lifetime stats from the merchant that
                // actually knows this customer. Using the wrong scope would
                // return 404 on /customers/{id}/orders.
                $scopedService = (new CloverService($businessId))->forLocation($customerLocId);
                $points = $scopedService->extractRewardPoints($cloverCustomer);
                $stats  = $scopedService->getCustomerLifetimeStats($cloverId);

                $before = [
                    'loyalty_points'     => (int) $contact->loyalty_points,
                    'lifetime_purchases' => (float) $contact->lifetime_purchases,
                    'last_purchase_date' => $this->formatDate($contact->last_purchase_date),
                ];

                if ($points !== null) {
                    $contact->loyalty_points = $points;
                }
                if (!empty($stats['success'])) {
                    $contact->lifetime_purchases = $stats['lifetime_purchases'];
                    if (!empty($stats['last_purchase_date'])) {
                        $contact->last_purchase_date = $stats['last_purchase_date'];
                    }
                }

                $after = [
                    'loyalty_points'     => (int) $contact->loyalty_points,
                    'lifetime_purchases' => (float) $contact->lifetime_purchases,
                    'last_purchase_date' => $this->formatDate($contact->last_purchase_date),
                ];

                if ($before === $after) {
                    $grandSkipped++;
                    continue;
                }

                if ($this->option('dry-run')) {
                    $this->line("    [dry] #{$contact->id} {$contact->name}: "
                        . "pts {$before['loyalty_points']}→{$after['loyalty_points']} · "
                        . "lifetime \${$before['lifetime_purchases']}→\${$after['lifetime_purchases']} · "
                        . "last {$before['last_purchase_date']}→{$after['last_purchase_date']}");
                } else {
                    $contact->save();
                    $this->line("    #{$contact->id} {$contact->name}: "
                        . "pts {$before['loyalty_points']}→{$after['loyalty_points']} · "
                        . "lifetime \${$before['lifetime_purchases']}→\${$after['lifetime_purchases']} · "
                        . "last {$before['last_purchase_date']}→{$after['last_purchase_date']}");
                }
                $grandUpdated++;
            }
        }

        $mode = $this->option('dry-run') ? ' (dry-run)' : '';
        $this->info("\nDone{$mode}. Updated={$grandUpdated} · unchanged={$grandSkipped} · missing-in-clover={$grandMissing}.");
        return 0;
    }

    private function resolveBusinessIds(): array
    {
        if ($b = $this->option('business')) {
            return [(int) $b];
        }
        return Business::pluck('id')->all();
    }

    /**
     * Coerce whatever contacts.last_purchase_date happens to be (Carbon,
     * DateTime, string, null) into a comparable 'Y-m-d' string or ''.
     */
    private function formatDate($value): string
    {
        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d');
        }
        if (is_string($value) && $value !== '') {
            try {
                return Carbon::parse($value)->format('Y-m-d');
            } catch (\Exception $e) {
                return '';
            }
        }
        return '';
    }
}
