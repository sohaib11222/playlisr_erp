<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

/**
 * Events / Listening Parties — ERP is the source of truth.
 *
 * All event detail (listening parties, concerts, etc.), the per-event
 * listening-party PREP CHECKLIST ("task list") and the prep details
 * (event host, playback link, giveaway-box tracking + location) live in a
 * JSON sidecar in storage/, NOT a DB column. Sarah doesn't run migrations
 * on the ERP box (they've broken prod before), so this mirrors the
 * consignment / cloverManualMatch JSON pattern.
 *
 * The public website (nivessa.com) READS this data via the no-auth
 * /events-feed.json endpoint (kept off the /api/ path because nginx
 * hijacks /api/*). Authoring/editing happens here in the ERP.
 *
 * JSON shape (storage/app/events-{business_id}.json):
 *   {
 *     "items": {
 *       "<id>": {
 *         "id": "...", "name": "...", "eventType": "listening_party",
 *         "genre": null, "artistSoundsLike": "", "date": "2026-07-01",
 *         "time": "19:00", "endTime": null, "description": "...",
 *         "image": "https://nivessa.com/imageNiv/...jpg",
 *         "location": ["hollywood"], "locationDetail": "stage",
 *         "preorderEnabled": false, "preorderTitle": "", "preorderPrice": null,
 *         "preorderPickupDate": null, "preorderNote": "",
 *         "prepChecklist": { "<itemId>": {done, note, updatedBy, updatedAt} },
 *         "prepDetails": { eventHost, eventLink, boxTracking, boxLocation, updatedBy, updatedAt },
 *         "createdBy": "...", "lastModifiedBy": "...",
 *         "createdAt": "...", "updatedAt": "...", "source": "erp|website"
 *       }
 *     }
 *   }
 */
class EventsController extends Controller
{
    /** Where to pull existing events from on a one-time / refresh import. */
    const WEBSITE_FEED = 'https://nivessa.com/api/v1/events/allEvents';

    /**
     * Event types — mirrors the nivessa.com Event model enum so the website
     * keeps rendering imported + ERP-authored events identically.
     */
    public static function eventTypes(): array
    {
        return [
            'listening_party'            => 'Listening Party',
            'general'                    => 'General',
            'concert'                    => 'Concert',
            'poetry'                     => 'Poetry',
            'karaoke'                    => 'Karaoke',
            'live_performance'           => 'Live Performance',
            'dj_set'                     => 'DJ Set',
            'album_release_event'        => 'Album Release Event',
            'open_decks_community_djs'   => 'Open Decks / Community DJs',
            'open_mic'                   => 'Open Mic',
            'showcase_multi_artist'      => 'Showcase (Multi-Artist)',
            'band_night'                 => 'Band Night',
            'acoustic_session'          => 'Acoustic Session',
            'producer_set_beat_showcase' => 'Producer Set / Beat Showcase',
            'live_show'                  => 'Live Show',
            'meetup'                     => 'Meetup',
            'other'                      => 'Other',
        ];
    }

    /** Genre enum — mirrors the website model (null = unset). */
    public static function genres(): array
    {
        return [
            'hip_hop_rap'                 => 'Hip-Hop / Rap',
            'rnb_soul'                    => 'R&B / Soul',
            'indie_alternative'           => 'Indie / Alternative',
            'rock_punk'                   => 'Rock / Punk',
            'electronic_house_techno'     => 'Electronic / House / Techno',
            'jazz_blues'                  => 'Jazz / Blues',
            'latin'                       => 'Latin',
            'world_music'                 => 'World Music',
            'funk_disco'                  => 'Funk / Disco',
            'pop'                         => 'Pop',
        ];
    }

    /**
     * Canonical listening-party prep checklist ("task list"). Kept in sync
     * with the website's lib/listeningPartyPrep.js — the `id` values are the
     * stable keys stored per event; NEVER rename an id once live or saved
     * progress orphans. dueOffsetDays = days BEFORE the event the item is due.
     */
    public static function prepItems(): array
    {
        return [
            ['id' => 'both_stores_approved',     'label' => 'Confirm both stores are approved to participate.',                              'due' => 14],
            ['id' => 'social_post_scheduled',    'label' => 'Post the event on social media when Carrie sends the assets.',                  'due' => 7],
            ['id' => 'box_tracked_confirmed',    'label' => 'Confirm giveaway box(es) have arrived.',                                        'due' => 5],
            ['id' => 'box_photo_received',       'label' => 'Request a photo of the giveaway box stored in the employee room.',              'due' => 4],
            ['id' => 'enough_staff',             'label' => 'Review RSVPs and confirm adequate staffing is scheduled.',                      'due' => 3],
            ['id' => 'rules_confirmed_with_host','label' => 'Review all event rules with the designated employee.',                          'due' => 2],
            ['id' => 'link_shared_with_host',    'label' => 'Share the playback link with the person hosting.',                             'due' => 1],
            ['id' => 'link_confirmed_working',   'label' => 'Host tests the playback link at least 1 hour before the event.',                'due' => 0],
        ];
    }

    // --------------------------------------------------------------------
    // JSON sidecar I/O (same pattern as ConsignmentController)
    // --------------------------------------------------------------------

    protected static function path(int $business_id): string
    {
        return storage_path('app/events-' . $business_id . '.json');
    }

    /** Full store; missing/corrupt file returns the empty shape. */
    public static function load(int $business_id): array
    {
        $path = self::path($business_id);
        if (!is_file($path)) {
            return ['items' => []];
        }
        try {
            $json = json_decode((string) file_get_contents($path), true);
            if (!is_array($json) || !isset($json['items']) || !is_array($json['items'])) {
                return ['items' => []];
            }
            return $json;
        } catch (\Throwable $e) {
            return ['items' => []];
        }
    }

    public static function save(int $business_id, array $data): void
    {
        $path = self::path($business_id);
        $dir = dirname($path);
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        $tmp = $path . '.tmp';
        file_put_contents($tmp, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        @rename($tmp, $path);
    }

    /** Snapshot the current store before any mutating action (undo support). */
    protected static function snapshot(int $business_id, array $data, string $action): void
    {
        $snapDir = storage_path('app/admin-snapshots');
        if (!is_dir($snapDir)) {
            @mkdir($snapDir, 0775, true);
        }
        @file_put_contents(
            $snapDir . '/events-' . $business_id . '-' . date('Ymd-His') . '.json',
            json_encode(['action' => $action, 'timestamp' => date('Y-m-d H:i:s'),
                'user_id' => auth()->id(), 'business_id' => $business_id,
                'rows' => $data], // BEFORE state, restored verbatim on undo
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
        );
    }

    protected function businessId(Request $request): int
    {
        return (int) $request->session()->get('user.business_id');
    }

    protected function actorName(): string
    {
        $u = auth()->user();
        return trim(($u->first_name ?? '') . ' ' . ($u->last_name ?? '')) ?: ($u->username ?? 'staff');
    }

    // --------------------------------------------------------------------
    // Screens
    // --------------------------------------------------------------------

    public function index(Request $request)
    {
        if (!auth()->user()->can('product.create')) {
            abort(403, 'Unauthorized action.');
        }

        $business_id = $this->businessId($request);
        $items = self::load($business_id)['items'];

        // Sort by date+time. Split into upcoming vs past relative to today.
        $today = date('Y-m-d');
        $rows = array_values($items);
        usort($rows, function ($a, $b) {
            return strcmp(($a['date'] ?? '') . ($a['time'] ?? ''), ($b['date'] ?? '') . ($b['time'] ?? ''));
        });

        $upcoming = array_values(array_filter($rows, fn($e) => ($e['date'] ?? '') >= $today));
        $past = array_reverse(array_values(array_filter($rows, fn($e) => ($e['date'] ?? '') < $today)));

        return view('events.index', [
            'upcoming'   => $upcoming,
            'past'       => $past,
            'eventTypes' => self::eventTypes(),
            'genres'     => self::genres(),
            'prepItems'  => self::prepItems(),
        ]);
    }

    public function edit(Request $request, string $id)
    {
        if (!auth()->user()->can('product.create')) {
            abort(403, 'Unauthorized action.');
        }
        $business_id = $this->businessId($request);
        $items = self::load($business_id)['items'];
        if (!isset($items[$id])) {
            return redirect()->route('events.index')->with('error', 'Event not found.');
        }

        return view('events.edit', [
            'event'      => $items[$id],
            'eventTypes' => self::eventTypes(),
            'genres'     => self::genres(),
            'prepItems'  => self::prepItems(),
        ]);
    }

    // --------------------------------------------------------------------
    // Mutations
    // --------------------------------------------------------------------

    protected function validatedFields(Request $request): array
    {
        $request->validate([
            'name'        => 'required|string|max:191',
            'date'        => 'required|date',
            'time'        => 'required|string|max:20',
            'eventType'   => 'required|string|max:60',
            'description' => 'nullable|string|max:8000',
            'endTime'     => 'nullable|string|max:20',
            'genre'       => 'nullable|string|max:60',
            'image'       => 'nullable|string|max:1000',
            'artistSoundsLike' => 'nullable|string|max:191',
            'locationDetail'   => 'nullable|string|max:20',
        ], [
            'name.required' => 'Event name is required.',
            'date.required' => 'Pick an event date.',
            'time.required' => 'Pick a start time.',
        ]);

        $location = (array) $request->input('location', []);
        $location = array_values(array_intersect($location, ['hollywood', 'pico']));

        $genre = $request->input('genre');
        $genre = ($genre === '' || $genre === null) ? null : $genre;

        $locationDetail = $request->input('locationDetail');
        $locationDetail = ($locationDetail === '' || $locationDetail === null) ? null : $locationDetail;

        $endTime = trim((string) $request->input('endTime', ''));

        $preorderEnabled = filter_var($request->input('preorderEnabled'), FILTER_VALIDATE_BOOLEAN);

        return [
            'name'             => trim($request->input('name')),
            'eventType'        => $request->input('eventType'),
            'genre'            => $genre,
            'artistSoundsLike' => trim((string) $request->input('artistSoundsLike', '')),
            'date'             => $request->input('date'),
            'time'             => trim($request->input('time')),
            'endTime'          => $endTime !== '' ? $endTime : null,
            'description'      => trim((string) $request->input('description', '')),
            'image'            => trim((string) $request->input('image', '')) ?: null,
            'location'         => $location,
            'locationDetail'   => $locationDetail,
            'preorderEnabled'  => $preorderEnabled,
            'preorderTitle'    => $preorderEnabled ? trim((string) $request->input('preorderTitle', '')) : '',
            'preorderPrice'    => $preorderEnabled && $request->filled('preorderPrice') ? round((float) $request->input('preorderPrice'), 2) : null,
            'preorderPickupDate' => $preorderEnabled ? ($request->input('preorderPickupDate') ?: null) : null,
            'preorderNote'     => $preorderEnabled ? trim((string) $request->input('preorderNote', '')) : '',
        ];
    }

    public function store(Request $request)
    {
        if (!auth()->user()->can('product.create')) {
            abort(403, 'Unauthorized action.');
        }
        $business_id = $this->businessId($request);
        $fields = $this->validatedFields($request);

        $data = self::load($business_id);
        $id = 'evt_' . date('YmdHis') . '_' . substr(md5(uniqid('', true)), 0, 6);

        $data['items'][$id] = array_merge($fields, [
            'id'            => $id,
            'prepChecklist' => new \stdClass(),
            'prepDetails'   => new \stdClass(),
            'createdBy'     => $this->actorName(),
            'lastModifiedBy' => $this->actorName(),
            'createdAt'     => date('c'),
            'updatedAt'     => date('c'),
            'source'        => 'erp',
        ]);

        self::save($business_id, $data);

        return redirect()->route('events.edit', ['id' => $id])
            ->with('status', 'Event "' . $fields['name'] . '" created.');
    }

    public function update(Request $request, string $id)
    {
        if (!auth()->user()->can('product.create')) {
            abort(403, 'Unauthorized action.');
        }
        $business_id = $this->businessId($request);
        $data = self::load($business_id);
        if (!isset($data['items'][$id])) {
            return redirect()->route('events.index')->with('error', 'Event not found.');
        }

        $fields = $this->validatedFields($request);
        self::snapshot($business_id, $data, 'events-update');

        $data['items'][$id] = array_merge($data['items'][$id], $fields, [
            'id'             => $id,
            'lastModifiedBy' => $this->actorName(),
            'updatedAt'      => date('c'),
        ]);

        self::save($business_id, $data);

        return redirect()->route('events.edit', ['id' => $id])
            ->with('status', 'Saved.');
    }

    public function destroy(Request $request, string $id)
    {
        if (!auth()->user()->can('product.create')) {
            abort(403, 'Unauthorized action.');
        }
        $business_id = $this->businessId($request);
        $data = self::load($business_id);
        if (!isset($data['items'][$id])) {
            return redirect()->route('events.index')->with('error', 'Event not found.');
        }
        $name = $data['items'][$id]['name'] ?? 'event';
        self::snapshot($business_id, $data, 'events-delete');
        unset($data['items'][$id]);
        self::save($business_id, $data);

        return redirect()->route('events.index')->with('status', 'Deleted "' . $name . '".');
    }

    /**
     * Update the prep checklist item state and/or prep details for one event.
     * Merges so a partial update never clobbers other items. Used both by the
     * inline checklist toggles and the host/link/box detail form.
     */
    public function updatePrep(Request $request, string $id)
    {
        if (!auth()->user()->can('product.create')) {
            abort(403, 'Unauthorized action.');
        }
        $business_id = $this->businessId($request);
        $data = self::load($business_id);
        if (!isset($data['items'][$id])) {
            return redirect()->route('events.index')->with('error', 'Event not found.');
        }

        $ev = $data['items'][$id];
        $checklist = (array) ($ev['prepChecklist'] ?? []);
        $details   = (array) ($ev['prepDetails'] ?? []);

        $now = date('c');
        $actor = $this->actorName();

        // Checklist: only known item ids; merge per-item.
        $validIds = array_column(self::prepItems(), 'id');
        $incoming = (array) $request->input('checklist', []);
        foreach ($incoming as $itemId => $val) {
            if (!in_array($itemId, $validIds, true)) {
                continue;
            }
            $prev = (array) ($checklist[$itemId] ?? []);
            $checklist[$itemId] = [
                'done'      => filter_var($val['done'] ?? ($prev['done'] ?? false), FILTER_VALIDATE_BOOLEAN),
                'note'      => mb_substr(trim((string) ($val['note'] ?? ($prev['note'] ?? ''))), 0, 2000),
                'updatedBy' => $actor,
                'updatedAt' => $now,
            ];
        }

        // Details: only the four known keys.
        $incomingDetails = (array) $request->input('details', []);
        foreach (['eventHost', 'eventLink', 'boxTracking', 'boxLocation'] as $k) {
            if (array_key_exists($k, $incomingDetails)) {
                $details[$k] = mb_substr(trim((string) $incomingDetails[$k]), 0, 2000);
            }
        }
        if (!empty($incomingDetails)) {
            $details['updatedBy'] = $actor;
            $details['updatedAt'] = $now;
        }

        $data['items'][$id]['prepChecklist'] = $checklist ?: new \stdClass();
        $data['items'][$id]['prepDetails']   = $details ?: new \stdClass();
        $data['items'][$id]['updatedAt']     = $now;

        self::save($business_id, $data);

        return redirect()->route('events.edit', ['id' => $id])->with('status', 'Prep updated.');
    }

    /**
     * One-time / refresh import: pull every event from the live nivessa.com
     * feed into the ERP store so the ERP starts fully populated. Event detail
     * fields are taken from the website; existing local PREP edits are
     * preserved (we never clobber prep progress entered in the ERP).
     */
    public function import(Request $request)
    {
        if (!auth()->user()->can('product.create')) {
            abort(403, 'Unauthorized action.');
        }
        $business_id = $this->businessId($request);

        $raw = $this->httpGet(self::WEBSITE_FEED);
        if ($raw === null) {
            return redirect()->route('events.index')->with('error', 'Could not reach nivessa.com to import events.');
        }
        $decoded = json_decode($raw, true);
        // Feed may be {events:[...]} or a bare array.
        $list = $decoded['events'] ?? $decoded['data'] ?? (is_array($decoded) ? $decoded : []);
        if (!is_array($list) || empty($list)) {
            return redirect()->route('events.index')->with('error', 'No events found in the website feed.');
        }

        $data = self::load($business_id);
        self::snapshot($business_id, $data, 'events-import');

        $imported = 0;
        foreach ($list as $e) {
            if (!is_array($e)) {
                continue;
            }
            $id = (string) ($e['_id'] ?? $e['id'] ?? '');
            if ($id === '') {
                continue;
            }

            $existing = $data['items'][$id] ?? [];

            // Prefer website prep if the local copy has none; otherwise keep
            // local edits (ERP becomes the place people work the checklist).
            $prepChecklist = $existing['prepChecklist'] ?? null;
            if (empty((array) $prepChecklist)) {
                $prepChecklist = $e['prepChecklist'] ?? null;
            }
            $prepDetails = $existing['prepDetails'] ?? null;
            if (empty((array) $prepDetails)) {
                $prepDetails = $e['prepDetails'] ?? null;
            }

            $data['items'][$id] = [
                'id'               => $id,
                'name'             => (string) ($e['name'] ?? ''),
                'eventType'        => (string) ($e['eventType'] ?? 'listening_party'),
                'genre'            => $e['genre'] ?? null,
                'artistSoundsLike' => (string) ($e['artistSoundsLike'] ?? ''),
                'date'             => (string) ($e['date'] ?? ''),
                'time'             => (string) ($e['time'] ?? ''),
                'endTime'          => $e['endTime'] ?? null,
                'description'      => (string) ($e['description'] ?? ''),
                'image'            => $e['image'] ?? null,
                'location'         => array_values(array_intersect((array) ($e['location'] ?? []), ['hollywood', 'pico'])),
                'locationDetail'   => $e['locationDetail'] ?? null,
                'preorderEnabled'  => filter_var($e['preorderEnabled'] ?? false, FILTER_VALIDATE_BOOLEAN),
                'preorderTitle'    => (string) ($e['preorderTitle'] ?? ''),
                'preorderPrice'    => isset($e['preorderPrice']) && $e['preorderPrice'] !== null ? round((float) $e['preorderPrice'], 2) : null,
                'preorderPickupDate' => $e['preorderPickupDate'] ?? null,
                'preorderNote'     => (string) ($e['preorderNote'] ?? ''),
                'prepChecklist'    => $prepChecklist ?: new \stdClass(),
                'prepDetails'      => $prepDetails ?: new \stdClass(),
                'createdBy'        => $existing['createdBy'] ?? ($e['createdBy'] ?? 'website'),
                'lastModifiedBy'   => $existing['lastModifiedBy'] ?? ($e['lastModifiedBy'] ?? 'website'),
                'createdAt'        => $existing['createdAt'] ?? ($e['createdAt'] ?? date('c')),
                'updatedAt'        => date('c'),
                'source'           => $existing['source'] ?? 'website',
            ];
            $imported++;
        }

        self::save($business_id, $data);

        return redirect()->route('events.index')
            ->with('status', "Imported {$imported} event" . ($imported === 1 ? '' : 's') . ' from nivessa.com.');
    }

    /**
     * PUBLIC read endpoint for the website (no auth). Lives OFF the /api/
     * path because nginx hijacks /api/*. Returns all events across the single
     * tenant's sidecar file(s) as a flat array.
     */
    public function publicFeed(Request $request)
    {
        $items = [];
        foreach (glob(storage_path('app/events-*.json')) ?: [] as $file) {
            try {
                $json = json_decode((string) file_get_contents($file), true);
                if (is_array($json) && isset($json['items']) && is_array($json['items'])) {
                    foreach ($json['items'] as $it) {
                        $items[] = $it;
                    }
                }
            } catch (\Throwable $e) {
                // skip unreadable file
            }
        }

        // Newest event date first, mirroring the website's ordering.
        usort($items, fn($a, $b) => strcmp(($b['date'] ?? '') . ($b['time'] ?? ''), ($a['date'] ?? '') . ($a['time'] ?? '')));

        return response()->json(['events' => $items], 200, [
            'Access-Control-Allow-Origin' => '*',
            'Cache-Control' => 'public, max-age=30',
        ], JSON_UNESCAPED_SLASHES);
    }

    /** Tiny curl GET (older Laravel — no Http facade). */
    protected function httpGet(string $url): ?string
    {
        try {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => 20,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_HTTPHEADER     => ['Accept: application/json'],
            ]);
            $body = curl_exec($ch);
            $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            if ($body === false || $code >= 400) {
                return null;
            }
            return (string) $body;
        } catch (\Throwable $e) {
            return null;
        }
    }
}
