<?php

namespace App\Console\Commands;

use App\Business;
use App\CloverBatch;
use App\CloverPayment;
use App\Services\CloverService;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class SyncCloverPayments extends Command
{
    /**
     * Artisan signature:
     *   php artisan clover:sync-payments
     *   php artisan clover:sync-payments --date=2026-04-19
     *   php artisan clover:sync-payments --start=2026-04-01 --end=2026-04-20
     *   php artisan clover:sync-payments --business=1 --days=7
     *
     * Defaults to syncing yesterday + today (so a 2 AM cron picks up
     * late-night sales that finalized after midnight in one pass).
     */
    protected $signature = 'clover:sync-payments
                            {--date= : Single date (YYYY-MM-DD)}
                            {--start= : Range start (YYYY-MM-DD), use with --end}
                            {--end= : Range end (YYYY-MM-DD)}
                            {--days= : Sync the last N days (overrides other date options)}
                            {--business= : Specific business_id, defaults to all configured}';

    protected $description = 'Pull Clover payments into clover_payments so the Clover vs ERP reconciliation report can light up.';

    public function handle()
    {
        [$start, $end] = $this->resolveDateRange();

        $this->info(sprintf('Syncing Clover payments from %s to %s', $start->toDateString(), $end->toDateString()));

        $businessIds = $this->resolveBusinessIds();
        if (empty($businessIds)) {
            $this->warn('No businesses to sync. Pass --business=X or configure Clover on at least one business.');
            return 0;
        }

        $total = 0;
        $upsertedTotal = 0;
        $batchUpsertedTotal = 0;

        foreach ($businessIds as $businessId) {
            $this->line("\n— business_id={$businessId}");

            $clover = new CloverService($businessId);
            if (!$clover->isConfigured()) {
                $this->warn("  skipped: Clover not configured for this business.");
                continue;
            }

            // Clover can be configured two ways per business (and occasionally
            // both): one top-level merchant, or separate merchants per location
            // (e.g. Hollywood + Pico, each with its own Clover account). Pull
            // once per configured scope using that scope's merchant_id/token,
            // and tag upserted rows with the ERP location_id so per-location
            // reports roll up correctly.
            $scopes = $clover->getConfiguredScopes();
            if (empty($scopes)) {
                $this->warn('  skipped: no valid Clover scopes found (unexpected).');
                continue;
            }

            foreach ($scopes as $scopeLocId) {
                $scoped = new CloverService($businessId);
                if ($scopeLocId !== null) {
                    $scoped->forLocation($scopeLocId);
                }
                $label = $scopeLocId === null ? '(top-level merchant)' : "location_id={$scopeLocId}";
                $this->line("  · {$label}");

                $result = $scoped->getPayments($start, $end);
                if (empty($result['success'])) {
                    $this->error('    ' . ($result['msg'] ?? 'unknown error'));
                    continue;
                }

                $payments = $result['payments'] ?? [];
                $this->line('    fetched ' . count($payments) . ' payments');
                $total += count($payments);

                $upserted = $this->upsertPayments($businessId, $payments, $scopeLocId);
                $upsertedTotal += $upserted;
                $this->line("    upserted {$upserted} rows");

                $batchRows = $scoped->summarizeBatchesFromPayments($payments);
                $batchUpserted = $this->upsertBatches($businessId, $batchRows, $scopeLocId);
                $batchUpsertedTotal += $batchUpserted;
                $this->line("    upserted {$batchUpserted} batch/deposit rows");
            }
        }

        $this->info("\nDone. Fetched {$total} · upserted {$upsertedTotal} payments · upserted {$batchUpsertedTotal} batches.");
        return 0;
    }

    /**
     * Resolve a Carbon start + end from the CLI options. Default: yesterday
     * 00:00 through today 23:59 so an overnight cron is idempotent + catches
     * any stragglers that finalized after midnight.
     */
    private function resolveDateRange(): array
    {
        if ($days = $this->option('days')) {
            return [Carbon::today()->subDays((int) $days - 1), Carbon::today()];
        }
        if ($date = $this->option('date')) {
            $d = Carbon::parse($date);
            return [$d->copy(), $d->copy()];
        }
        if (($s = $this->option('start')) && ($e = $this->option('end'))) {
            return [Carbon::parse($s), Carbon::parse($e)];
        }
        return [Carbon::yesterday(), Carbon::today()];
    }

    private function resolveBusinessIds(): array
    {
        if ($b = $this->option('business')) {
            return [(int) $b];
        }
        // Default: every business in the table. In practice Nivessa is a single
        // business; loop keeps it future-proof for a multi-tenant deploy.
        return Business::pluck('id')->all();
    }

    /**
     * Upsert a batch of Clover payments into clover_payments. Idempotent on
     * clover_payment_id — re-running the same day's sync won't duplicate.
     *
     * Returns the number of rows touched (inserted + updated).
     */
    private function upsertPayments(int $businessId, array $payments, ?int $locationId = null): int
    {
        $count = 0;
        foreach ($payments as $p) {
            $id = $p['id'] ?? null;
            if (!$id) {
                continue;
            }

            $createdMs = $p['createdTime'] ?? 0;
            $paidAt = $createdMs
                ? Carbon::createFromTimestampMs($createdMs)
                : Carbon::now();
            $paidOn = $paidAt->copy()->setTimezone(config('app.timezone'))->toDateString();

            $employee = $p['employee'] ?? null;
            $employeeName = null;
            if (is_array($employee)) {
                $employeeName = trim(($employee['name'] ?? '') ?: (($employee['nickname'] ?? '')));
            }

            $tender = $p['tender']['labelKey'] ?? ($p['tender']['label'] ?? null);
            $cardTransaction = $p['cardTransaction'] ?? [];
            $cardType = $cardTransaction['cardType'] ?? null;
            $cardLast4 = $cardTransaction['last4'] ?? null;

            $amountCents = (int) ($p['amount'] ?? 0);
            $tipCents    = (int) ($p['tipAmount'] ?? 0);
            $taxCents    = (int) ($p['taxAmount'] ?? 0);

            CloverPayment::updateOrCreate(
                ['clover_payment_id' => $id],
                [
                    'business_id' => $businessId,
                    // Populated when we pulled this payment via a per-location
                    // Clover merchant scope — lets the EOD report roll up
                    // Clover totals per ERP location instead of bucketing them
                    // globally. Null when the payment came from a top-level
                    // (single-merchant) scope.
                    'location_id' => $locationId,
                    'clover_order_id' => $p['order']['id'] ?? null,
                    'clover_employee_id' => $employee['id'] ?? null,
                    'employee_name' => $employeeName ?: null,
                    'amount_cents' => $amountCents,
                    'tip_cents' => $tipCents,
                    'tax_cents' => $taxCents,
                    'amount' => $amountCents / 100,
                    'card_type' => $cardType,
                    'card_last4' => $cardLast4,
                    'tender_type' => $tender,
                    'result' => $p['result'] ?? null,
                    'paid_at' => $paidAt,
                    'paid_on' => $paidOn,
                    'raw_payload' => json_encode($p),
                ]
            );
            $count++;
        }
        return $count;
    }

    /**
     * Upsert normalized Clover batch/deposit rows (derived from payment payloads).
     */
    private function upsertBatches(int $businessId, array $batchRows, ?int $locationId = null): int
    {
        $count = 0;
        foreach ($batchRows as $b) {
            if (empty($b['clover_batch_id']) || empty($b['batch_on'])) {
                continue;
            }

            CloverBatch::updateOrCreate(
                [
                    'business_id' => $businessId,
                    'location_id' => $locationId,
                    'clover_batch_id' => $b['clover_batch_id'],
                    'batch_on' => $b['batch_on'],
                ],
                [
                    'batch_at' => $b['batch_at'] ?? null,
                    'payment_count' => (int) ($b['payment_count'] ?? 0),
                    'amount_cents' => (int) ($b['amount_cents'] ?? 0),
                    'amount' => (float) ($b['amount'] ?? 0),
                    'deposit_cents' => is_null($b['deposit_cents']) ? null : (int) $b['deposit_cents'],
                    'deposit_total' => is_null($b['deposit_total']) ? null : (float) $b['deposit_total'],
                    'status' => $b['status'] ?? null,
                    'raw_payload' => isset($b['raw_payload']) ? json_encode($b['raw_payload']) : null,
                ]
            );
            $count++;
        }

        return $count;
    }
}
