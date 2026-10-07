<?php

namespace App\Console\Commands;

use App\Business;
use App\Http\Controllers\EventsController;
use App\Services\OpenPhoneService;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Texts every RSVP guest the giveaway check-in link when an event starts
 * (Sarah, 2026-10-07). The short link nivessa.com/checkin opens today's
 * event check-in on the website.
 *
 * - Runs every 5 min; sends once, in the first 30 minutes after the event's
 *   start time (LA time), so a missed run or deploy still catches it.
 * - Guests who RSVP'd yes/maybe with a phone number, deduped by phone,
 *   skipping anyone already checked in. Additional guests with their own
 *   phone get a text too.
 * - Sent from the store's Quo line (Pico guests from Pico, everyone else
 *   from Hollywood).
 * - Who was texted is logged in storage/app/event-checkin-texts.json so
 *   nobody is ever texted twice for the same event.
 */
class SendEventCheckinTexts extends Command
{
    protected $signature = 'events:send-checkin-texts {--dry : Print what would be sent, send nothing} {--event= : Only this event id} {--force : Ignore the start-time window (with --dry for testing)}';

    protected $description = 'Text RSVP guests the giveaway check-in link when an event starts';

    const WINDOW_MINUTES = 30;
    const LINK = 'nivessa.com/checkin';

    public function handle()
    {
        $dry = (bool) $this->option('dry');
        $force = (bool) $this->option('force');
        if ($force && !$dry) {
            $this->error('--force only works with --dry.');
            return 1;
        }
        $business = Business::first();
        if (!$business) { return 0; }
        if ($this->erpApiKey() === '') {
            $this->error('No ERP bridge key configured.');
            return 1;
        }

        $now = Carbon::now('America/Los_Angeles');
        $logPath = storage_path('app/event-checkin-texts.json');
        $log = is_file($logPath) ? (json_decode((string) file_get_contents($logPath), true) ?: []) : [];
        $sms = app(OpenPhoneService::class);

        foreach ((EventsController::load($business->id)['items'] ?? []) as $event) {
            $eventId = (string) ($event['id'] ?? '');
            $eventName = trim((string) ($event['name'] ?? ''));
            if ($eventId === '' || $eventName === '') { continue; }
            if ($this->option('event') && $this->option('event') !== $eventId) { continue; }
            $status = strtolower((string) ($event['status'] ?? ''));
            if (in_array($status, ['cancelled', 'canceled', 'draft'], true) || !empty($event['canceled']) || !empty($event['cancelled'])) { continue; }
            $date = substr((string) ($event['date'] ?? ''), 0, 10);
            $time = (string) ($event['time'] ?? '');
            if ($date === '' || !preg_match('/^\d{1,2}:\d{2}/', $time)) { continue; }
            try {
                $start = Carbon::parse($date . ' ' . substr($time, 0, 5), 'America/Los_Angeles');
            } catch (\Throwable $e) { continue; }
            $mins = $start->diffInMinutes($now, false);
            if (!$force && ($mins < 0 || $mins > self::WINDOW_MINUTES)) { continue; }

            $rsvps = $this->fetchRsvps($eventName, $eventId);
            if ($rsvps === null) {
                Log::info("SendEventCheckinTexts: website unreachable for {$eventId}, will retry");
                continue;
            }

            // phone => [first name, store]
            $targets = [];
            foreach ($rsvps as $r) {
                if (!in_array(strtolower((string) ($r['attendance'] ?? 'yes')), ['yes', 'maybe'], true)) { continue; }
                $store = strtolower((string) ($r['eventLocationKey'] ?? ''));
                $people = [[
                    'first' => $r['firstName'] ?? '', 'last' => $r['lastName'] ?? '',
                    'phone' => $r['phone'] ?? '', 'checkedIn' => !empty($r['checkedIn']),
                ]];
                foreach (array_values(array_filter((array) ($r['additionalGuests'] ?? []))) as $g) {
                    $people[] = [
                        'first' => $g['firstName'] ?? '', 'last' => $g['lastName'] ?? '',
                        'phone' => $g['phone'] ?? '', 'checkedIn' => !empty($g['checkedIn']),
                    ];
                }
                foreach ($people as $p) {
                    if ($p['checkedIn']) { continue; }
                    if (preg_match('/^zz\b/i', trim($p['first'] . ' ' . $p['last']))) { continue; }
                    $phone = $sms->normalize((string) $p['phone']);
                    if (!$phone || isset($targets[$phone])) { continue; }
                    $targets[$phone] = ['first' => trim((string) $p['first']), 'store' => $store];
                }
            }

            $sent = (array) ($log[$eventId] ?? []);
            foreach ($targets as $phone => $t) {
                if (in_array($phone, $sent, true)) { continue; }
                // Wording from Sarah, 2026-10-07.
                $message = 'Thanks for coming out to Nivessa Records! Please check in here to be entered into tonight\'s giveaway. Must be present to win. ' . self::LINK;
                $line = array_search($t['store'] === 'pico' ? 'phone_1' : 'phone_2', \App\Communication::QUO_NUMBERS, true);
                if ($dry) {
                    $this->line("[dry] {$eventId} from {$line} to {$phone}: {$message}");
                    continue;
                }
                $result = $sms->sendFrom((string) $line, $phone, $message);
                if (!empty($result['success'])) {
                    $sent[] = $phone;
                    $log[$eventId] = $sent;
                    file_put_contents($logPath, json_encode($log));
                } else {
                    Log::info("SendEventCheckinTexts: send failed to {$phone} for {$eventId}: " . ($result['msg'] ?? ''));
                }
                usleep(300000);
            }
            $this->info("{$eventName}: " . count($targets) . ' guests with a phone, ' . count($sent) . ' texted');
        }
        return 0;
    }

    private function fetchRsvps(string $eventName, string $eventId): ?array
    {
        $params = ['limit' => 10000, 'eventName' => $eventName, 'eventId' => $eventId];
        $resp = $this->websiteApi('GET', '/erp/rsvps?' . http_build_query($params));
        if ($resp === null) {
            return null;
        }
        $rsvps = $resp['data'] ?? $resp['rsvps'] ?? [];
        return is_array($rsvps) ? $rsvps : [];
    }

    // --- Bridge plumbing, mirrored from EventsController (protected there,
    // and request-scoped) so this command has no controller dependency. ---

    private function erpApiKey(): string
    {
        $key = trim((string) config('constants.erp_api_key'));
        if ($key === '') $key = trim((string) env('ERP_API_KEY', ''));
        if ($key === '') $key = $this->envFromDisk('ERP_API_KEY');
        if ($key === '') $key = $this->bridgeKeyFromStore();
        return $key;
    }

    private function bridgeKeyFromStore(): string
    {
        $path = storage_path('app/events-bridge.json');
        if (!is_file($path)) return '';
        try {
            $j = json_decode((string) file_get_contents($path), true);
            return is_array($j) ? trim((string) ($j['erpApiKey'] ?? '')) : '';
        } catch (\Throwable $e) {
            return '';
        }
    }

    private function bridgeBaseUrl(): string
    {
        $base = trim((string) config('constants.nivessa_api'));
        if ($base === '') $base = trim((string) env('NIVESSA_API', ''));
        if ($base === '') $base = $this->envFromDisk('NIVESSA_API');
        return rtrim($base !== '' ? $base : 'https://nivessa.com/api/v1', '/');
    }

    private function envFromDisk(string $name): string
    {
        try {
            $path = base_path('.env');
            if (!is_readable($path)) return '';
            foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
                if (strpos(ltrim($line), $name . '=') === 0) {
                    return trim(trim(substr(ltrim($line), strlen($name) + 1)), "\"'");
                }
            }
        } catch (\Throwable $e) {
            // fall through
        }
        return '';
    }

    private function websiteApi(string $method, string $path, array $body = null): ?array
    {
        $key = $this->erpApiKey();
        if ($key === '') return null;
        try {
            $ch = curl_init($this->bridgeBaseUrl() . $path);
            $headers = ['Accept: application/json', 'x-erp-key: ' . $key];
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => 15,
                CURLOPT_CUSTOMREQUEST  => $method,
            ]);
            if ($body !== null) {
                $headers[] = 'Content-Type: application/json';
                curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
            }
            curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
            $raw = curl_exec($ch);
            $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            if ($raw === false || $code < 200 || $code >= 300) return null;
            $decoded = json_decode((string) $raw, true);
            return is_array($decoded) ? $decoded : null;
        } catch (\Throwable $e) {
            return null;
        }
    }
}
