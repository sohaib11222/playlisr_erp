<?php

namespace App\Console\Commands;

use App\Business;
use App\Contact;
use App\Http\Controllers\EventsController;
use App\Utils\ContactUtil;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Pull every event RSVP (and their +guests) into the Customers list, so
 * "who is Bryan Armstrong" is answerable from Contacts > Customers instead
 * of clicking into each event's RSVP table by hand.
 *
 * Source: the website bridge (/erp/rsvps), same endpoint the event edit
 * page reads live — see EventsController::bridgeData(). This command
 * duplicates the minimal key/URL/HTTP plumbing rather than calling into
 * EventsController directly, so it has no dependency on that controller's
 * request-scoped state and can run from the console or the scheduler.
 *
 * Matching (per RSVP/guest), in order:
 *   1. Already imported this exact RSVP/guest before (import_source =
 *      'event_rsvp', import_external_id = "<eventId>:<rsvpId>[:guestN]")
 *      -> skip entirely, just a no-op re-run.
 *   2. Existing contact with the same email (case-insensitive) or the same
 *      10-digit mobile -> LINK: append this event to their rsvp_history
 *      (deduped by eventId) and leave every other field alone. We never
 *      overwrite a real customer's name/email/mobile/import_source from an
 *      RSVP — POS/Clover-sourced data wins.
 *   3. No match -> CREATE a new customer contact (via ContactUtil so it
 *      gets a real CO#### contact_id), tagged import_source='event_rsvp'.
 *
 * Dry-run by default (matches nivessa:import-store-credit convention) —
 * pass --commit to actually write. Not wrapped in one big transaction:
 * each contact write commits on its own, so one bad row can't roll back
 * thousands of good ones — errors are counted and reported, not fatal.
 *
 * Usage:
 *   php artisan events:import-rsvps-as-contacts
 *   php artisan events:import-rsvps-as-contacts --commit
 *   php artisan events:import-rsvps-as-contacts --commit --event=<id>
 */
class ImportEventRsvpsAsContacts extends Command
{
    protected $signature = 'events:import-rsvps-as-contacts
                            {--business= : business_id (defaults to the first business)}
                            {--user= : created_by user id for new contacts (defaults to business owner)}
                            {--commit : Actually write (default: dry-run)}
                            {--event= : Only process this one event id (for testing)}';

    protected $description = 'Import event RSVPs (+guests) into Contacts > Customers, linking existing customers and creating new ones.';

    const IMPORT_SOURCE = 'event_rsvp';

    public function handle()
    {
        $commit = (bool) $this->option('commit');

        $businessId = (int) $this->option('business');
        if ($businessId <= 0) {
            $business = Business::first();
            if (!$business) {
                $this->error('No business found.');
                return 1;
            }
            $businessId = $business->id;
        }

        $userId = (int) $this->option('user');
        if ($userId <= 0) {
            $userId = (int) DB::table('users')
                ->where('business_id', $businessId)
                ->orderBy('id')
                ->value('id');
        }
        if ($userId <= 0) {
            $this->error('Could not resolve a created_by user id — pass --user=<id>.');
            return 1;
        }

        if ($this->erpApiKey() === '') {
            $this->error('No ERP bridge key configured — cannot reach the website RSVP feed.');
            return 1;
        }

        $events = EventsController::load($businessId)['items'] ?? [];
        if (!empty($this->option('event'))) {
            $wantId = (string) $this->option('event');
            $events = array_filter($events, fn ($e) => (string) ($e['id'] ?? '') === $wantId);
        }
        if (empty($events)) {
            $this->warn('No events found.');
            return 0;
        }

        $summary = [
            'events' => 0, 'events_unreachable' => 0, 'people_seen' => 0,
            'skip_dup' => 0, 'skip_no_data' => 0, 'linked' => 0, 'created' => 0, 'errors' => 0,
        ];
        $sample = [];
        $allRows = [];
        $contactUtil = new ContactUtil();

        foreach ($events as $event) {
            $eventId = (string) ($event['id'] ?? '');
            $eventName = (string) ($event['name'] ?? '');
            if ($eventId === '' && $eventName === '') {
                continue;
            }
            $summary['events']++;

            $rsvps = $this->fetchRsvps($eventName, $eventId);
            if ($rsvps === null) {
                $summary['events_unreachable']++;
                $this->warn("  [unreachable] {$eventName}");
                continue;
            }

            $eventMeta = [
                'eventId' => $eventId,
                'eventName' => $eventName,
                'eventDate' => $event['date'] ?? null,
                'eventType' => $event['eventType'] ?? null,
            ];

            foreach ($rsvps as $rsvp) {
                $people = $this->peopleFromRsvp($rsvp);
                foreach ($people as $person) {
                    $summary['people_seen']++;
                    $result = $this->processPerson($businessId, $userId, $eventMeta, $person, $commit, $contactUtil);
                    $summary[$result['status']] = ($summary[$result['status']] ?? 0) + 1;
                    if ($result['status'] !== 'skip_dup' && count($sample) < 12) {
                        $sample[] = $result['line'];
                    }
                    if (!empty($result['row'])) {
                        $allRows[] = $result['row'];
                    }
                }
            }
        }

        $this->line('');
        $this->info($commit ? 'Contacts written.' : 'DRY RUN -- no rows written. Re-run with --commit.');
        $this->line(sprintf(
            'Events: %d (%d unreachable) . People seen: %d . Linked to existing: %d . Created new: %d . Already imported: %d . No usable data: %d . Errors: %d',
            $summary['events'], $summary['events_unreachable'], $summary['people_seen'],
            $summary['linked'], $summary['created'], $summary['skip_dup'], $summary['skip_no_data'], $summary['errors']
        ));
        if (!empty($sample)) {
            $this->info('Sample:');
            foreach ($sample as $line) {
                $this->line('  ' . $line);
            }
        }
        // Machine-readable full row list, one line, for the admin UI's preview
        // table (so Sarah can eyeball all ~3k names/emails for test data or
        // dupes before confirming a commit). Not meant for a human to read raw.
        $this->line('RSVP_IMPORT_ROWS_JSON:' . json_encode($allRows));
        return 0;
    }

    /** One RSVP record -> the primary RSVPer plus each named +guest. */
    private function peopleFromRsvp(array $rsvp): array
    {
        $rsvpId = (string) ($rsvp['_id'] ?? $rsvp['id'] ?? '');
        $people = [[
            'externalKey' => $rsvpId,
            'firstName' => (string) ($rsvp['firstName'] ?? ''),
            'lastName' => (string) ($rsvp['lastName'] ?? ''),
            'email' => (string) ($rsvp['email'] ?? ''),
            'phone' => (string) ($rsvp['phone'] ?? ''),
            'checkedIn' => (bool) ($rsvp['checkedIn'] ?? false),
        ]];
        $guests = $rsvp['additionalGuests'] ?? [];
        if (is_array($guests)) {
            foreach ($guests as $i => $g) {
                if (!is_array($g)) continue;
                $people[] = [
                    'externalKey' => $rsvpId . ':guest' . $i,
                    'firstName' => (string) ($g['firstName'] ?? ''),
                    'lastName' => (string) ($g['lastName'] ?? ''),
                    'email' => (string) ($g['email'] ?? ''),
                    'phone' => '',
                    'checkedIn' => (bool) ($g['checkedIn'] ?? false),
                ];
            }
        }
        return $people;
    }

    private function processPerson(int $businessId, int $userId, array $eventMeta, array $person, bool $commit, ContactUtil $contactUtil): array
    {
        $firstName = trim($person['firstName']);
        $lastName = trim($person['lastName']);
        $fullName = trim($firstName . ' ' . $lastName);
        $email = strtolower(trim($person['email']));
        $phone = $this->parsePhone($person['phone']);

        $rowBase = [
            'name' => $fullName, 'email' => $email, 'phone' => $phone ?: '',
            'event' => $eventMeta['eventName'],
        ];

        if ($fullName === '' && $email === '') {
            return ['status' => 'skip_no_data', 'line' => '(blank RSVP row, skipped)', 'row' => $rowBase + ['status' => 'skip_no_data']];
        }

        $externalId = $eventMeta['eventId'] . ':' . $person['externalKey'];

        $already = DB::table('contacts')
            ->where('business_id', $businessId)
            ->where('import_source', self::IMPORT_SOURCE)
            ->where('import_external_id', $externalId)
            ->exists();
        if ($already) {
            return ['status' => 'skip_dup', 'line' => "{$fullName} (already imported)", 'row' => $rowBase + ['status' => 'skip_dup']];
        }

        $matched = null;
        if ($email !== '') {
            $matched = DB::table('contacts')
                ->where('business_id', $businessId)
                ->whereNull('deleted_at')
                ->whereRaw('LOWER(email) = ?', [$email])
                ->first();
        }
        if (!$matched && $phone) {
            $matched = DB::table('contacts')
                ->where('business_id', $businessId)
                ->whereNull('deleted_at')
                ->where('mobile', $phone)
                ->first();
        }

        if ($matched) {
            if ($commit) {
                $this->appendRsvpHistory($matched->id, $eventMeta, $person['checkedIn']);
                DB::table('contacts')->where('id', $matched->id)->update(['updated_at' => now()]);
            }
            return [
                'status' => 'linked',
                'line' => "{$fullName} -> linked to existing {$matched->contact_id}",
                'row' => $rowBase + ['status' => 'linked', 'matchedContactId' => $matched->contact_id],
            ];
        }

        if (!$commit) {
            return ['status' => 'created', 'line' => "{$fullName} -> would create new contact", 'row' => $rowBase + ['status' => 'created']];
        }

        try {
            $history = json_encode([[
                'eventId' => $eventMeta['eventId'],
                'eventName' => $eventMeta['eventName'],
                'eventDate' => $eventMeta['eventDate'],
                'eventType' => $eventMeta['eventType'],
                'checkedIn' => $person['checkedIn'],
            ]]);
            $input = [
                'business_id' => $businessId,
                'type' => 'customer',
                'name' => $fullName !== '' ? $fullName : $email,
                'first_name' => $firstName ?: null,
                'last_name' => $lastName ?: null,
                'mobile' => $phone ?: '',
                'email' => $email ?: null,
                'created_by' => $userId,
                'import_source' => self::IMPORT_SOURCE,
                'import_external_id' => $externalId,
                'rsvp_history' => $history,
            ];
            $out = $contactUtil->createNewContact($input);
            $contactId = $out['data']->contact_id ?? '?';
            return [
                'status' => 'created',
                'line' => "{$fullName} -> created {$contactId}",
                'row' => $rowBase + ['status' => 'created', 'contactId' => $contactId],
            ];
        } catch (\Throwable $e) {
            $this->error("  error on {$fullName}: " . $e->getMessage());
            return [
                'status' => 'errors',
                'line' => "{$fullName} -> ERROR: " . $e->getMessage(),
                'row' => $rowBase + ['status' => 'errors', 'error' => $e->getMessage()],
            ];
        }
    }

    /** Add this event to a contact's rsvp_history, deduped by eventId. */
    private function appendRsvpHistory(int $contactId, array $eventMeta, bool $checkedIn): void
    {
        $row = DB::table('contacts')->where('id', $contactId)->value('rsvp_history');
        $history = $row ? (json_decode($row, true) ?: []) : [];
        if (!is_array($history)) {
            $history = [];
        }
        foreach ($history as $h) {
            if (($h['eventId'] ?? null) === $eventMeta['eventId']) {
                return; // already recorded
            }
        }
        $history[] = [
            'eventId' => $eventMeta['eventId'],
            'eventName' => $eventMeta['eventName'],
            'eventDate' => $eventMeta['eventDate'],
            'eventType' => $eventMeta['eventType'],
            'checkedIn' => $checkedIn,
        ];
        DB::table('contacts')->where('id', $contactId)->update(['rsvp_history' => json_encode($history)]);
    }

    /** 10-digit US phone from whatever format the website sends, else null. */
    private function parsePhone($raw): ?string
    {
        $raw = trim((string) $raw);
        if ($raw === '') return null;
        $digits = preg_replace('/\D+/', '', $raw);
        if (strlen($digits) === 10) return $digits;
        if (strlen($digits) === 11 && str_starts_with($digits, '1')) return substr($digits, 1);
        return null;
    }

    /** Fetch RSVPs for one event from the website bridge. Null = unreachable. */
    private function fetchRsvps(string $eventName, string $eventId): ?array
    {
        $params = ['limit' => 10000];
        if ($eventName !== '') $params['eventName'] = $eventName;
        if ($eventId !== '') $params['eventId'] = $eventId;
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
