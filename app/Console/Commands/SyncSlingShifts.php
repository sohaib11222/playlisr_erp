<?php

namespace App\Console\Commands;

use App\Services\SlingClient;
use App\SlingShift;
use App\User;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class SyncSlingShifts extends Command
{
    /**
     * Pull every Sling shift in a window into the sling_shifts table so the
     * ERP has an authoritative roster of who is/was scheduled, independent
     * of the live Hours Worked report.
     *
     * Defaults to a window that covers last week + the next month so a daily
     * run can pick up after-the-fact edits to recent shifts as well as
     * newly-published future ones.
     *
     * Usage:
     *   php artisan sling:sync-shifts
     *   php artisan sling:sync-shifts --start=2026-05-01 --end=2026-05-31
     *   php artisan sling:sync-shifts --past-days=14 --future-days=60
     */
    protected $signature = 'sling:sync-shifts
                            {--start= : Range start (YYYY-MM-DD)}
                            {--end= : Range end (YYYY-MM-DD)}
                            {--past-days=7 : Past days included if --start/--end omitted}
                            {--future-days=30 : Future days included if --start/--end omitted}';

    protected $description = 'Pull Sling shifts into the ERP sling_shifts table.';

    public function handle()
    {
        $client = new SlingClient();
        if (!$client->isConfigured()) {
            $this->warn('Sling is not connected. Visit /admin/sling/login to set the token.');
            return 0;
        }

        [$start, $end] = $this->resolveRange();
        $this->info(sprintf('Sling shift sync: %s → %s', $start->toDateString(), $end->toDateString()));

        // Build sling_user_id → email map once. Used both for the email/name
        // columns and for the lowercased-email join into ERP users (matches
        // the convention SlingClient::hoursByErpUser already relies on).
        $slingUsers = $client->users();
        $emailById = [];
        $nameById = [];
        foreach ($slingUsers as $u) {
            $sid = (string) ($u['id'] ?? '');
            if ($sid === '') continue;
            $email = isset($u['email']) ? strtolower(trim((string) $u['email'])) : null;
            if ($email) {
                $emailById[$sid] = $email;
            }
            $first = trim((string) ($u['legalName'] ?? $u['firstName'] ?? ''));
            $last = trim((string) ($u['lastName'] ?? ''));
            $name = trim($first . ' ' . $last);
            if ($name === '') {
                $name = (string) ($u['name'] ?? '');
            }
            if ($name !== '') {
                $nameById[$sid] = $name;
            }
        }

        // ERP user roster — map lowercased email → user_id so we can join.
        $erpUserIdByEmail = User::query()
            ->whereNotNull('email')
            ->pluck('id', 'email')
            ->mapWithKeys(function ($id, $email) {
                return [strtolower(trim($email)) => $id];
            })
            ->toArray();

        $shifts = $client->shifts($start->toDateString(), $end->toDateString());
        $count = is_array($shifts) ? count($shifts) : 0;
        $this->line("Fetched {$count} shift records from Sling.");
        if ($count === 0) {
            return 0;
        }

        $now = Carbon::now();
        $upserted = 0;
        $skipped = 0;

        DB::beginTransaction();
        try {
            foreach ($shifts as $shift) {
                if (!is_array($shift)) { $skipped++; continue; }

                $shiftId = (string) ($shift['id'] ?? '');
                $sid = (string) ($shift['user']['id'] ?? ($shift['userId'] ?? ''));
                $startAt = $shift['dtstart'] ?? ($shift['startDate'] ?? null);
                $endAt = $shift['dtend'] ?? ($shift['endDate'] ?? null);

                if ($shiftId === '' || !$startAt) {
                    $skipped++;
                    continue;
                }

                $email = $sid !== '' ? ($emailById[$sid] ?? null) : null;
                $name = $sid !== '' ? ($nameById[$sid] ?? null) : null;
                $erpUid = $email ? ($erpUserIdByEmail[$email] ?? null) : null;

                $hours = 0.0;
                if ($endAt) {
                    $sec = max(0, strtotime($endAt) - strtotime($startAt));
                    $hours = round($sec / 3600.0, 2);
                }

                $published = true;
                if (isset($shift['status'])) {
                    $published = strtolower((string) $shift['status']) !== 'draft';
                } elseif (isset($shift['published'])) {
                    $published = (bool) $shift['published'];
                }

                $locationName = $shift['location']['name']
                    ?? ($shift['locationName'] ?? null);
                $positionName = $shift['position']['name']
                    ?? ($shift['positionName'] ?? null);

                SlingShift::updateOrCreate(
                    ['sling_shift_id' => $shiftId],
                    [
                        'sling_user_id' => $sid !== '' ? $sid : null,
                        'user_email' => $email,
                        'user_name' => $name,
                        'erp_user_id' => $erpUid,
                        'location_name' => $locationName,
                        'position_name' => $positionName,
                        'dtstart' => Carbon::parse($startAt),
                        'dtend' => $endAt ? Carbon::parse($endAt) : null,
                        'hours' => $hours,
                        'published' => $published,
                        'raw_payload' => json_encode($shift),
                        'last_synced_at' => $now,
                    ]
                );
                $upserted++;
            }
            DB::commit();
        } catch (\Throwable $e) {
            DB::rollBack();
            $this->error('Sync aborted: ' . $e->getMessage());
            \Log::warning('SyncSlingShifts failed: ' . $e->getMessage());
            return 1;
        }

        $this->info("Upserted {$upserted}, skipped {$skipped}.");
        return 0;
    }

    /**
     * @return array{0: Carbon, 1: Carbon}
     */
    private function resolveRange(): array
    {
        $tz = 'America/Los_Angeles';
        $startOpt = $this->option('start');
        $endOpt = $this->option('end');
        if ($startOpt && $endOpt) {
            return [
                Carbon::parse($startOpt, $tz)->startOfDay(),
                Carbon::parse($endOpt, $tz)->endOfDay(),
            ];
        }
        $past = (int) $this->option('past-days');
        $future = (int) $this->option('future-days');
        return [
            Carbon::today($tz)->subDays(max(0, $past))->startOfDay(),
            Carbon::today($tz)->addDays(max(0, $future))->endOfDay(),
        ];
    }
}
