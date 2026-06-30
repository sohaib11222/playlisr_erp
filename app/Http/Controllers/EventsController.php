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
            ['id' => 'host_stage_announcement',  'label' => 'Host announces on stage what we have available to order and pick up — all preorders are ready on street date at 10 AM, pickup only (no shipping).', 'due' => 0],
            ['id' => 'host_announce_used_sale',  'label' => 'Host announces 15% off all used products to drive more sales.',                  'due' => 0],
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

        // Default the list to listening parties (the common case); ?type=all
        // shows every event, and ?type=<other> filters to that type.
        $eventTypes = self::eventTypes();
        $filterType = $request->input('type', 'listening_party');
        if ($filterType === 'all') {
            $filterType = null;
        }
        if (!empty($filterType) && isset($eventTypes[$filterType])) {
            $rows = array_values(array_filter($rows, fn($e) => ($e['eventType'] ?? 'listening_party') === $filterType));
        } else {
            $filterType = null;
        }

        $upcoming = array_values(array_filter($rows, fn($e) => ($e['date'] ?? '') >= $today));
        $past = array_reverse(array_values(array_filter($rows, fn($e) => ($e['date'] ?? '') < $today)));

        // Per-event RSVP + vinyl-request (preorder) counts from the website
        // bridge, keyed by event name. Empty maps when the bridge is off or
        // unreachable — the list degrades to "—" in those columns.
        $counts = $this->bridgeCounts();

        return view('events.index', [
            'upcoming'       => $upcoming,
            'past'           => $past,
            'eventTypes'     => $eventTypes,
            'genres'         => self::genres(),
            'prepItems'      => self::prepItems(),
            'filterType'     => $filterType,
            'filterLabel'    => $filterType ? $eventTypes[$filterType] : null,
            'rsvpCounts'     => $counts['rsvps'],
            'vinylCounts'    => $counts['vinyl'],
            'cdCounts'       => $counts['cd'],
            'storeCounts'    => $counts['store'] ?? [],
            'toOrder'        => $this->toOrderList($upcoming, $counts['store'] ?? []),
            'publishedMap'   => $this->publishedMap(),
            'bridgeProbe'    => $this->bridgeProbe(),
            'bridgeKeySet'   => $this->erpApiKey() !== '',
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

        $event = $items[$id];

        // Live RSVP + preorder data from the website bridge (source of those
        // records). Degrades gracefully: $bridge['ready'] is false when the
        // key isn't configured or the website is unreachable.
        $bridge = $this->bridgeData($event['name'] ?? '');

        return view('events.edit', [
            'event'      => $event,
            'eventTypes' => self::eventTypes(),
            'genres'     => self::genres(),
            'prepItems'  => self::prepItems(),
            'bridge'     => $bridge,
        ]);
    }

    /**
     * Overview of ALL preorders — both listening-party reservations (which
     * live on nivessa.com, read via the bridge) and in-store special orders
     * (the ERP `preorders` table). One place to see who reserved what, where
     * they placed it, when, the pickup date, and whether they've paid; each
     * active row has a "Mark picked up" button. Defaults to active preorders
     * sorted by pickup date (soonest first); ?status=all includes picked-up
     * and canceled. Rows are normalized to one shape so both sources render
     * in a single table.
     */
    public function preordersOverview(Request $request)
    {
        if (!auth()->user()->can('product.create')) {
            abort(403, 'Unauthorized action.');
        }

        $business_id = $this->businessId($request);
        $events = self::load($business_id)['items'];

        // eventId => [name, streetDate] for linking each preorder back to its
        // event edit page and filling a pickup date when the snapshot is empty.
        $eventById = [];
        foreach ($events as $eid => $ev) {
            $eventById[(string) $eid] = [
                'name'       => $ev['name'] ?? '',
                'streetDate' => $ev['streetDate'] ?? null,
            ];
        }

        $keySet  = $this->erpApiKey() !== '';
        $showAll = $request->input('status') === 'all';
        $rows = [];
        $reachable = false;

        // ---- Listening-party preorders (website bridge) ----
        if ($keySet) {
            $resp = $this->websiteApi('GET', '/erp/preorders?limit=500');
            if ($resp !== null) {
                $reachable = true;
                foreach ((array) ($resp['data'] ?? $resp['preorders'] ?? []) as $p) {
                    $status = $p['status'] ?? 'pending';
                    $active = in_array($status, ['pending', 'ready'], true);
                    if (!$showAll && !$active) { continue; }
                    $eid = (string) ($p['eventId'] ?? '');
                    $pickup = $p['preorderPickupDate'] ?? null;
                    if (!$pickup && isset($eventById[$eid])) {
                        $pickup = $eventById[$eid]['streetDate'];
                    }
                    $rows[] = [
                        'type'        => 'event',
                        'id'          => (string) ($p['_id'] ?? $p['id'] ?? ''),
                        'eventId'     => isset($eventById[$eid]) ? $eid : null,
                        'name'        => trim(($p['firstName'] ?? '') . ' ' . ($p['lastName'] ?? '')) ?: '—',
                        'email'       => (strpos((string) ($p['email'] ?? ''), '@noemail.nivessa.com') !== false) ? '' : (string) ($p['email'] ?? ''),
                        'phone'       => (string) ($p['phone'] ?? ''),
                        'item'        => $p['preorderTitle'] ?? '—',
                        'price'       => isset($p['preorderPrice']) && $p['preorderPrice'] !== null ? (float) $p['preorderPrice'] : null,
                        'eventName'   => $p['eventName'] ?? 'Listening party',
                        // Stored source (empty = placed at the event). When set,
                        // it's what the overview shows instead of the party name.
                        'source'      => trim((string) ($p['source'] ?? '')),
                        'sourceTag'   => 'Listening party',
                        'placed'      => $p['createdAt'] ?? null,
                        'pickup'      => $pickup,
                        'paid'        => !empty($p['paid']),
                        'paidKnown'   => true,
                        'status'      => $status,
                        'statusLabel' => str_replace('_', ' ', $status),
                        'active'      => $active,
                    ];
                }
            }
        }

        // ---- In-store special orders (ERP `preorders` table) ----
        // Guarded: the table may not exist on a box where the migration never
        // ran, so check first rather than risk a 500 on the whole page.
        if (auth()->user()->can('preorder.view') && \Schema::hasTable('preorders')) {
            $special = \App\Preorder::where('preorders.business_id', $business_id)
                ->leftJoin('contacts', 'preorders.contact_id', '=', 'contacts.id')
                ->leftJoin('products', 'preorders.product_id', '=', 'products.id')
                ->leftJoin('variations', 'preorders.variation_id', '=', 'variations.id')
                ->select(
                    'preorders.*',
                    'contacts.name as customer_name',
                    'contacts.mobile as customer_mobile',
                    'products.name as product_name',
                    'variations.sub_sku'
                )
                ->orderByRaw('COALESCE(preorders.expected_date, preorders.order_date) asc')
                ->get();
            foreach ($special as $s) {
                $active = $s->status === 'pending';
                if (!$showAll && !$active) { continue; }
                // Map the special-order vocabulary onto the shared one.
                $label = $s->status === 'fulfilled' ? 'picked up'
                       : ($s->status === 'cancelled' ? 'canceled' : 'pending');
                $item = $s->product_name ?: '—';
                if ($s->sub_sku) { $item .= ' (' . $s->sub_sku . ')'; }
                if ((float) $s->quantity > 1) { $item .= ' ×' . rtrim(rtrim(number_format((float) $s->quantity, 2), '0'), '.'); }
                $rows[] = [
                    'type'        => 'special',
                    'id'          => (string) $s->id,
                    'eventId'     => null,
                    'name'        => $s->customer_name ?: '—',
                    'email'       => '',
                    'phone'       => (string) ($s->customer_mobile ?? ''),
                    'item'        => $item,
                    'price'       => null,
                    'eventName'   => null,
                    'source'      => '',
                    'sourceTag'   => 'Special order',
                    'placed'      => $s->order_date,
                    'pickup'      => $s->expected_date,
                    'paid'        => null,
                    'paidKnown'   => false,
                    'status'      => $s->status,
                    'statusLabel' => $label,
                    'active'      => $active,
                ];
            }
        }

        // Soonest pickup first; undated pickups sink to the bottom.
        usort($rows, function ($a, $b) {
            $pa = $a['pickup'] ?: '9999-12-31';
            $pb = $b['pickup'] ?: '9999-12-31';
            if ($pa !== $pb) { return strcmp($pa, $pb); }
            return strcmp((string) ($b['placed'] ?? ''), (string) ($a['placed'] ?? ''));
        });

        return view('events.preorders', [
            'preorders' => $rows,
            'keySet'    => $keySet,
            'reachable' => $reachable,
            'showAll'   => $showAll,
        ]);
    }

    /** Return to the overview keeping the Active/All filter the form carried. */
    protected function overviewRedirect(Request $request, string $key, string $msg)
    {
        $params = $request->input('filter') === 'all' ? ['status' => 'all'] : [];
        return redirect()->route('events.preordersOverview', $params)->with($key, $msg);
    }

    /** Mark a listening-party preorder picked up (from the overview page). */
    public function overviewMarkEventPickedUp(Request $request, string $preorderId)
    {
        if (!auth()->user()->can('product.create')) {
            abort(403, 'Unauthorized action.');
        }
        $resp = $this->websiteApi('PATCH', '/erp/preorders/' . rawurlencode($preorderId) . '/status', ['status' => 'picked_up']);
        return $resp === null
            ? $this->overviewRedirect($request, 'error', 'Could not reach the website to update the preorder.')
            : $this->overviewRedirect($request, 'status', 'Preorder marked picked up.');
    }

    /** Mark an in-store special-order preorder picked up (fulfilled). */
    public function overviewMarkSpecialPickedUp(Request $request, int $id)
    {
        if (!auth()->user()->can('preorder.update')) {
            abort(403, 'Unauthorized action.');
        }
        if (\Schema::hasTable('preorders')) {
            $business_id = $this->businessId($request);
            $preorder = \App\Preorder::where('business_id', $business_id)->find($id);
            if ($preorder && $preorder->status === 'pending') {
                $preorder->status = 'fulfilled';
                $preorder->save();
            }
        }
        return $this->overviewRedirect($request, 'status', 'Special order marked picked up.');
    }

    /** Mark a listening-party preorder paid (e.g. card run on Clover). */
    public function overviewMarkEventPaid(Request $request, string $preorderId)
    {
        if (!auth()->user()->can('product.create')) {
            abort(403, 'Unauthorized action.');
        }
        $resp = $this->websiteApi('PATCH', '/erp/preorders/' . rawurlencode($preorderId) . '/paid', ['paid' => true]);
        return $resp === null
            ? $this->overviewRedirect($request, 'error', 'Could not reach the website to mark it paid.')
            : $this->overviewRedirect($request, 'status', 'Preorder marked paid.');
    }

    /** Set/correct where a listening-party preorder came in (empty = at event). */
    public function overviewSetEventSource(Request $request, string $preorderId)
    {
        if (!auth()->user()->can('product.create')) {
            abort(403, 'Unauthorized action.');
        }
        $source = trim((string) $request->input('source', ''));
        $resp = $this->websiteApi('PATCH', '/erp/preorders/' . rawurlencode($preorderId) . '/source', ['source' => $source]);
        return $resp === null
            ? $this->overviewRedirect($request, 'error', 'Could not reach the website to set the source.')
            : $this->overviewRedirect($request, 'status', 'Source updated.');
    }

    /**
     * Per-event RSVP count plus vinyl/CD request counts for the index list,
     * keyed by exact event name. One stats call total (not per-event). The
     * vinyl/CD numbers come from the listening-party RSVP "buying vinyl or
     * CD?" question — "both" counts toward each, mirroring the per-event
     * estimate on the edit page. Empty maps when the bridge is off/unreachable.
     */
    protected function bridgeCounts(): array
    {
        $out = ['rsvps' => [], 'vinyl' => [], 'cd' => [], 'store' => []];
        if ($this->erpApiKey() === '') {
            return $out;
        }

        $statsResp = $this->websiteApi('GET', '/erp/rsvps/stats');
        $statsRows = $statsResp['data'] ?? $statsResp['stats'] ?? [];
        if (is_array($statsRows)) {
            // Key by a normalized (trim + lowercase) event name and SUM, so
            // RSVPs collected under casing variants of the same party
            // ("Madonna Listening Party" + "MADONNA listening party") count
            // together — matching what the event detail page shows.
            foreach ($statsRows as $row) {
                $name = $row['eventName'] ?? null;
                if ($name === null || $name === '') { continue; }
                $k = self::normName($name);
                // People attending (yes + their guests), summed across casing
                // variants of the same party.
                $out['rsvps'][$k] = ($out['rsvps'][$k] ?? 0) + (int) ($row['attendingCount'] ?? $row['totalAttendees'] ?? $row['totalRSVPs'] ?? 0);
                $out['vinyl'][$k] = ($out['vinyl'][$k] ?? 0) + (int) ($row['vinylRequests'] ?? 0);
                $out['cd'][$k]    = ($out['cd'][$k] ?? 0) + (int) ($row['cdRequests'] ?? 0);
                if (!isset($out['store'][$k])) {
                    $out['store'][$k] = ['hollywood' => ['vinyl' => 0, 'cd' => 0, 'attending' => 0], 'pico' => ['vinyl' => 0, 'cd' => 0, 'attending' => 0]];
                }
                $out['store'][$k]['hollywood']['vinyl'] += (int) ($row['hwVinyl'] ?? 0);
                $out['store'][$k]['hollywood']['cd']    += (int) ($row['hwCd'] ?? 0);
                $out['store'][$k]['hollywood']['attending'] += (int) ($row['hwAttending'] ?? 0);
                $out['store'][$k]['pico']['vinyl']      += (int) ($row['picoVinyl'] ?? 0);
                $out['store'][$k]['pico']['cd']         += (int) ($row['picoCd'] ?? 0);
                $out['store'][$k]['pico']['attending']  += (int) ($row['picoAttending'] ?? 0);
            }
        }

        return $out;
    }

    /**
     * Build the "what to order" shortfall list across upcoming events: per
     * store, demand (RSVP buy-interest) minus what's ordered. Non-hosting
     * stores get a baseline (stock a couple standard vinyl). Only returns
     * lines that need action.
     */
    protected function toOrderList(array $upcoming, array $storeDemand): array
    {
        $storeLabels = ['hollywood' => 'Hollywood', 'pico' => 'Pico'];
        $lines = [];
        foreach ($upcoming as $ev) {
            if (($ev['eventType'] ?? '') !== 'listening_party') { continue; }
            $k = self::normName($ev['name'] ?? '');
            $dem = $storeDemand[$k] ?? [];
            $ord = (array) ($ev['ordered'] ?? []);
            $locs = (array) ($ev['location'] ?? []);
            foreach ($storeLabels as $sk => $slabel) {
                $row = (array) ($ord[$sk] ?? []);
                $ordV = (int) ($row['indieVinyl'] ?? 0) + (int) ($row['stdVinyl'] ?? 0) + (int) ($row['deluxeVinyl'] ?? 0);
                $ordC = (int) ($row['stdCd'] ?? 0) + (int) ($row['deluxeCd'] ?? 0);
                $wantV = (int) ($dem[$sk]['vinyl'] ?? 0);
                $wantC = (int) ($dem[$sk]['cd'] ?? 0);
                $hosting = in_array($sk, $locs, true);

                // Only hosting stores need stock; a non-hosting store carries 0
                // of an event title, so there's nothing to order there.
                $needs = [];
                if ($hosting) {
                    if ($wantV > $ordV) { $needs[] = ($wantV - $ordV) . ' vinyl'; }
                    if ($wantC > $ordC) { $needs[] = ($wantC - $ordC) . ' CD'; }
                }
                if ($needs) {
                    $lines[] = [
                        'event' => $ev['name'] ?? '(untitled)',
                        'store' => $slabel,
                        'need'  => implode(', ', $needs),
                    ];
                }
            }
        }
        return $lines;
    }

    /** Normalize an event name for case-insensitive count matching. */
    protected static function normName(?string $s): string
    {
        return mb_strtolower(trim((string) $s));
    }

    /**
     * Map of event id => is-published (live on the website). The published
     * flag lives in the website's Mongo; its public /events/allEvents?all=1
     * feed returns every event (incl. unpublished) with a `published` bool.
     * Empty map on failure (column then shows "—").
     */
    protected function publishedMap(): array
    {
        $map = [];
        $raw = $this->httpGet($this->bridgeBaseUrl() . '/events/allEvents?all=1');
        if ($raw === null) {
            return $map;
        }
        try {
            $j = json_decode($raw, true);
            $list = $j['data'] ?? $j['events'] ?? (is_array($j) ? $j : []);
            if (is_array($list)) {
                foreach ($list as $e) {
                    $id = (string) ($e['id'] ?? $e['_id'] ?? '');
                    if ($id === '') { continue; }
                    $map[$id] = ($e['published'] ?? true) !== false;
                }
            }
        } catch (\Throwable $e) {
            // leave empty
        }
        return $map;
    }

    /**
     * Resolve the shared bridge key. Tries the cached config first, then the
     * live env, then reads .env from disk directly. The last step is what makes
     * this robust to a STALE CONFIG CACHE on the box: if someone added
     * ERP_API_KEY to .env but a `config:cache` build is still in place, both
     * config() and env() return empty — reading the file defeats that without
     * needing any shell command on the server. Cached for the request.
     */
    protected function erpApiKey(): string
    {
        $key = trim((string) config('constants.erp_api_key'));
        if ($key === '') {
            $key = trim((string) env('ERP_API_KEY', ''));
        }
        if ($key === '') {
            $key = $this->envFromDisk('ERP_API_KEY');
        }
        // Last resort: a key set from the ERP admin UI (events page). Lets the
        // bridge be turned on without server access, since the box .env is
        // hand-managed and we never sync it from secrets.
        if ($key === '') {
            $key = $this->bridgeKeyFromStore();
        }
        return $key;
    }

    protected function bridgeKeyStorePath(): string
    {
        return storage_path('app/events-bridge.json');
    }

    /** Read the UI-set bridge key from storage (empty if unset/corrupt). */
    protected function bridgeKeyFromStore(): string
    {
        $path = $this->bridgeKeyStorePath();
        if (!is_file($path)) {
            return '';
        }
        try {
            $j = json_decode((string) file_get_contents($path), true);
            return is_array($j) ? trim((string) ($j['erpApiKey'] ?? '')) : '';
        } catch (\Throwable $e) {
            return '';
        }
    }

    /**
     * Probe the website bridge with the resolved key. Returns the HTTP code and
     * a coarse state so the UI can say exactly what's wrong:
     *   no_key | connected | rejected (bad/mismatched key) | unreachable.
     */
    protected function bridgeProbe(): array
    {
        $key = $this->erpApiKey();
        if ($key === '') {
            return ['state' => 'no_key', 'code' => null];
        }
        try {
            $ch = curl_init($this->bridgeBaseUrl() . '/erp/rsvps/stats');
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => 8,
                CURLOPT_HTTPHEADER     => ['Accept: application/json', 'x-erp-key: ' . $key],
            ]);
            curl_exec($ch);
            $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            if ($code >= 200 && $code < 300) {
                return ['state' => 'connected', 'code' => $code];
            }
            if ($code === 401 || $code === 403) {
                return ['state' => 'rejected', 'code' => $code];
            }
            return ['state' => 'unreachable', 'code' => $code ?: null];
        } catch (\Throwable $e) {
            return ['state' => 'unreachable', 'code' => null];
        }
    }

    /** Resolve the website API base URL the same robust way as the key. */
    protected function bridgeBaseUrl(): string
    {
        $base = trim((string) config('constants.nivessa_api'));
        if ($base === '') {
            $base = trim((string) env('NIVESSA_API', ''));
        }
        if ($base === '') {
            $base = $this->envFromDisk('NIVESSA_API');
        }
        return rtrim($base !== '' ? $base : 'https://nivessa.com/api/v1', '/');
    }

    /** Read a single var straight out of the .env file (cache-proof fallback). */
    protected function envFromDisk(string $name): string
    {
        try {
            $path = base_path('.env');
            if (!is_readable($path)) {
                return '';
            }
            foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
                if (strpos(ltrim($line), $name . '=') === 0) {
                    $val = trim(substr(ltrim($line), strlen($name) + 1));
                    return trim($val, "\"'");
                }
            }
        } catch (\Throwable $e) {
            // fall through
        }
        return '';
    }

    /** Fetch RSVPs (+stats) and preorders for one event from the website. */
    protected function bridgeData(string $eventName): array
    {
        $out = ['ready' => false, 'error' => null, 'rsvps' => [], 'stats' => null, 'preorders' => []];
        if ($eventName === '') {
            return $out;
        }
        if ($this->erpApiKey() === '') {
            $out['error'] = 'not_configured';
            return $out;
        }

        $q = '?eventName=' . rawurlencode($eventName) . '&limit=500';
        $rsvpResp  = $this->websiteApi('GET', '/erp/rsvps' . $q);
        $statsResp = $this->websiteApi('GET', '/erp/rsvps/stats');
        $preResp   = $this->websiteApi('GET', '/erp/preorders' . $q);

        if ($rsvpResp === null && $preResp === null) {
            $out['error'] = 'unreachable';
            return $out;
        }

        $out['ready'] = true;
        $out['rsvps'] = $rsvpResp['data'] ?? $rsvpResp['rsvps'] ?? [];
        $out['preorders'] = $preResp['data'] ?? $preResp['preorders'] ?? [];

        // Stats endpoint returns per-event rows; pick this event's row.
        $statsRows = $statsResp['data'] ?? $statsResp['stats'] ?? [];
        if (is_array($statsRows)) {
            foreach ($statsRows as $row) {
                if (($row['eventName'] ?? null) === $eventName) {
                    $out['stats'] = $row;
                    break;
                }
            }
        }
        return $out;
    }

    /** Add a walk-in RSVP at the event (someone who didn't RSVP in advance). */
    public function rsvpAdd(Request $request, string $id)
    {
        if (!auth()->user()->can('product.create')) {
            abort(403, 'Unauthorized action.');
        }
        $business_id = $this->businessId($request);
        $items = self::load($business_id)['items'];
        if (!isset($items[$id])) {
            return redirect()->route('events.index')->with('error', 'Event not found.');
        }
        $event = $items[$id];

        $request->validate([
            'firstName' => 'required|string|max:100',
            'lastName'  => 'required|string|max:100',
            'email'     => 'required|email|max:191',
            'phone'     => 'nullable|string|max:40',
            'guests'    => 'nullable|integer|min:0|max:50',
            'interestedInPurchase' => 'nullable|in:vinyl,cd,both,not_sure,no',
        ], [
            'firstName.required' => 'First name is required.',
            'lastName.required'  => 'Last name is required.',
            'email.required'     => 'Email is required.',
        ]);

        $locations = (array) ($event['location'] ?? []);
        $payload = [
            'firstName' => trim($request->input('firstName')),
            'lastName'  => trim($request->input('lastName')),
            'email'     => trim($request->input('email')),
            'phone'     => $request->input('phone') ?: null,
            'guests'    => (int) $request->input('guests', 0),
            'attendance' => 'yes',
            'interestedInPurchase' => $request->input('interestedInPurchase') ?: null,
            'eventId'   => $event['id'] ?? null,
            'eventName' => $event['name'] ?? null,
            'eventType' => $event['eventType'] ?? null,
            'eventDate' => $event['date'] ?? null,
            'eventTime' => $event['time'] ?? null,
            'eventLocationKey' => $locations[0] ?? null,
            // The form sends this explicitly (hidden 0 + checkbox 1), default on.
            'checkedIn' => filter_var($request->input('checkedIn'), FILTER_VALIDATE_BOOLEAN),
        ];

        $resp = $this->websiteApi('POST', '/erp/rsvps', $payload);
        if ($resp === null) {
            return redirect()->route('events.edit', ['id' => $id])
                ->with('error', 'Could not reach the website to add the RSVP.');
        }
        return redirect()->route('events.edit', ['id' => $id])
            ->with('status', 'RSVP added for ' . $payload['firstName'] . ' ' . $payload['lastName'] . '.');
    }

    /** Toggle an RSVP's check-in state via the website bridge. */
    public function rsvpCheckIn(Request $request, string $id, string $rsvpId)
    {
        if (!auth()->user()->can('product.create')) {
            abort(403, 'Unauthorized action.');
        }
        $checkedIn = filter_var($request->input('checkedIn'), FILTER_VALIDATE_BOOLEAN);
        $resp = $this->websiteApi('PATCH', '/erp/rsvps/' . rawurlencode($rsvpId) . '/check-in', ['checkedIn' => $checkedIn]);
        $msg = $resp === null ? 'Could not reach the website to update check-in.'
            : ('Check-in ' . ($checkedIn ? 'marked' : 'cleared') . '.');
        return redirect()->route('events.edit', ['id' => $id])
            ->with($resp === null ? 'error' : 'status', $msg);
    }

    /**
     * Add a preorder at the event for a customer who's ordering in person.
     * Posts to the website's PUBLIC create endpoint (the same one the customer
     * form uses) so the record, the internal notify, and the POS draft-sale
     * sync all fire identically — no website-side changes needed.
     */
    public function preorderAdd(Request $request, string $id)
    {
        if (!auth()->user()->can('product.create')) {
            abort(403, 'Unauthorized action.');
        }
        $business_id = $this->businessId($request);
        $items = self::load($business_id)['items'];
        if (!isset($items[$id])) {
            return redirect()->route('events.index')->with('error', 'Event not found.');
        }
        $event = $items[$id];

        // No preorderEnabled gate here on purpose: staff add preorders
        // after-the-fact (someone DMs once the party's over, or for an event
        // that never had public preorders turned on). The key-gated bridge
        // create endpoint accepts a free-typed item title + price for those.

        $request->validate([
            'firstName'    => 'required|string|max:100',
            'lastName'     => 'required|string|max:100',
            'email'        => 'nullable|email|max:191',
            'phone'        => 'required|string|max:40',
            'productTitle' => 'nullable|string|max:191',
            'price'        => 'nullable|numeric|min:0',
            'source'       => 'nullable|string|max:100',
            'notes'        => 'nullable|string|max:1000',
        ], [
            'firstName.required' => 'First name is required.',
            'lastName.required'  => 'Last name is required.',
            'phone.required'     => 'Phone is required.',
        ]);

        // If the release has more than one configured version, a version must
        // be chosen so the preorder lands against the right line in "Versions
        // ordered". Events without configured versions take a free-typed item.
        $versions = array_values((array) ($event['preorderProducts'] ?? []));
        $productTitle = trim((string) $request->input('productTitle', ''));
        if (count($versions) > 1 && $productTitle === '') {
            return redirect()->route('events.edit', ['id' => $id])
                ->with('error', 'Choose which version this preorder is for.');
        }
        if (empty($versions) && $productTitle === '') {
            return redirect()->route('events.edit', ['id' => $id])
                ->with('error', 'Enter what the customer is preordering.');
        }

        // Email is optional for in-person preorders. The website's create
        // endpoint requires one, so synthesize an obvious placeholder when the
        // customer doesn't give an email — keyed on their phone so it's stable
        // and easy to spot/filter later (@noemail.nivessa.com).
        $phone = trim($request->input('phone'));
        $email = trim((string) $request->input('email', ''));
        if ($email === '') {
            $digits = preg_replace('/\D/', '', $phone);
            $email = 'walkin.' . ($digits !== '' ? $digits : 'nophone') . '@noemail.nivessa.com';
        }

        $priceRaw = $request->input('price');
        $payload = [
            'eventId'      => $event['id'] ?? null,
            'firstName'    => trim($request->input('firstName')),
            'lastName'     => trim($request->input('lastName')),
            'email'        => $email,
            'phone'        => $phone,
            'notes'        => $request->input('notes') ?: null,
            'productTitle' => $productTitle !== '' ? $productTitle : null,
            // Manual price for after-the-fact / no-configured-version events.
            // Ignored when the chosen version already carries a price.
            'price'        => ($priceRaw === null || $priceRaw === '') ? null : (float) $priceRaw,
            // Where it came in (empty = at the event). Set for DM/phone adds.
            'source'       => trim((string) $request->input('source', '')) ?: null,
        ];

        // Key-gated ERP bridge create (/api/v1/erp/preorders) — staff-only, so
        // it skips the preorderEnabled gate the public form enforces and lets
        // us pass a typed item title + price for after-the-fact preorders.
        $resp = $this->websiteApi('POST', '/erp/preorders', $payload);
        if ($resp === null || empty($resp['success'])) {
            $why = is_array($resp) ? ($resp['message'] ?? '') : '';
            return redirect()->route('events.edit', ['id' => $id])
                ->with('error', 'Could not add the preorder' . ($why !== '' ? ': ' . $why : '.') . '');
        }

        // Mark paid only when the card was actually run (walk-ins paid at the
        // event). After-the-fact preorders — e.g. an Instagram DM once the
        // party's over — stay unpaid; they pay at pickup. Best-effort: a
        // failure here doesn't undo the preorder, which already saved.
        $markPaid = filter_var($request->input('markPaid'), FILTER_VALIDATE_BOOLEAN);
        $newId = $resp['data']['_id'] ?? $resp['data']['id'] ?? null;
        if ($markPaid && $newId) {
            $this->websiteApi('PATCH', '/erp/preorders/' . rawurlencode($newId) . '/paid', ['paid' => true]);
        }

        return redirect()->route('events.edit', ['id' => $id])
            ->with('status', 'Preorder added for ' . $payload['firstName'] . ' ' . $payload['lastName']
                . ($markPaid ? ' (paid at event).' : ' (unpaid — pays at pickup).'));
    }

    /** Change a preorder's status via the website bridge (fires pickup email/SMS). */
    public function preorderStatus(Request $request, string $id, string $preorderId)
    {
        if (!auth()->user()->can('product.create')) {
            abort(403, 'Unauthorized action.');
        }
        $status = (string) $request->input('status');
        if (!in_array($status, ['pending', 'ready', 'picked_up', 'canceled'], true)) {
            return redirect()->route('events.edit', ['id' => $id])->with('error', 'Invalid preorder status.');
        }
        $resp = $this->websiteApi('PATCH', '/erp/preorders/' . rawurlencode($preorderId) . '/status', ['status' => $status]);
        $msg = $resp === null ? 'Could not reach the website to update the preorder.'
            : ('Preorder marked ' . str_replace('_', ' ', $status) . '.');
        return redirect()->route('events.edit', ['id' => $id])
            ->with($resp === null ? 'error' : 'status', $msg);
    }

    /**
     * Call the website bridge (/api/v1/erp/*) with the shared key. Returns the
     * decoded JSON array on success, or null on failure (unreachable / non-2xx).
     */
    protected function websiteApi(string $method, string $path, array $body = null): ?array
    {
        $base = $this->bridgeBaseUrl();
        $key  = $this->erpApiKey();
        if ($key === '') {
            return null;
        }
        try {
            $ch = curl_init($base . $path);
            $headers = ['Accept: application/json', 'x-erp-key: ' . $key];
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => 8,
                CURLOPT_CUSTOMREQUEST  => $method,
            ]);
            if ($body !== null) {
                $headers[] = 'Content-Type: application/json';
                curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
            }
            curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
            $raw  = curl_exec($ch);
            $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            if ($raw === false || $code < 200 || $code >= 300) {
                return null;
            }
            $decoded = json_decode((string) $raw, true);
            return is_array($decoded) ? $decoded : null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    // --------------------------------------------------------------------
    // Mutations
    // --------------------------------------------------------------------

    /** Stores we can order to, and the SKU lines tracked per store. */
    public static function orderStores(): array
    {
        return ['hollywood' => 'Hollywood', 'pico' => 'Pico'];
    }
    public static function orderSkus(): array
    {
        return [
            'indieVinyl'  => 'Indie vinyl',
            'stdVinyl'    => 'Standard vinyl',
            'deluxeVinyl' => 'Deluxe vinyl',
            'cassette'    => 'Cassette',
            'stdCd'       => 'Standard CD',
            'deluxeCd'    => 'Deluxe CD',
        ];
    }

    /** Normalize the per-store ordered matrix from request/stored input. */
    public static function cleanOrdered(array $in): array
    {
        $out = [];
        foreach (array_keys(self::orderStores()) as $store) {
            $row = (array) ($in[$store] ?? []);
            $clean = [];
            foreach (array_keys(self::orderSkus()) as $sku) {
                $v = $row[$sku] ?? null;
                $clean[$sku] = ($v === '' || $v === null) ? null : max(0, (int) $v);
            }
            $out[$store] = $clean;
        }
        return $out;
    }

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
            'streetDate'       => 'nullable|date',
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

        // Per-store "what we ordered": { hollywood|pico => { indieVinyl, stdVinyl,
        // deluxeVinyl, stdCd, deluxeCd } }. Blank -> null so empty stays empty.
        $ordered = self::cleanOrdered((array) $request->input('ordered', []));

        // Preorder products: a list of { title, price } the customer chooses
        // from. preorderTitle/preorderPrice are kept = the first product for
        // back-compat with the single-product customer flow.
        $preorderProducts = [];
        $validFormats = array_keys(self::orderSkus());
        foreach ((array) $request->input('preorderProducts', []) as $row) {
            $title = trim((string) ($row['title'] ?? ''));
            if ($title === '') { continue; }
            $price = (isset($row['price']) && $row['price'] !== '') ? round((float) $row['price'], 2) : null;
            $fmt = (string) ($row['format'] ?? '');
            $fmt = in_array($fmt, $validFormats, true) ? $fmt : null;
            $preorderProducts[] = ['title' => $title, 'price' => $price, 'format' => $fmt];
        }

        return [
            'name'             => trim($request->input('name')),
            'eventType'        => $request->input('eventType'),
            'genre'            => $genre,
            'artistSoundsLike' => trim((string) $request->input('artistSoundsLike', '')),
            'date'             => $request->input('date'),
            'time'             => trim($request->input('time')),
            'endTime'          => $endTime !== '' ? $endTime : null,
            'streetDate'       => $request->input('streetDate') ?: null,
            'ordered'          => $ordered,
            'description'      => trim((string) $request->input('description', '')),
            'image'            => trim((string) $request->input('image', '')) ?: null,
            'location'         => $location,
            'locationDetail'   => $locationDetail,
            'preorderEnabled'  => $preorderEnabled,
            'preorderProducts' => $preorderEnabled ? $preorderProducts : [],
            'preorderTitle'    => $preorderEnabled ? (string) ($preorderProducts[0]['title'] ?? '') : '',
            'preorderPrice'    => $preorderEnabled ? ($preorderProducts[0]['price'] ?? null) : null,
            // Pickup date is always the event's street date.
            'preorderPickupDate' => $preorderEnabled ? ($request->input('streetDate') ?: null) : null,
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

    /**
     * Save (or clear) the bridge key from the events admin UI, then probe the
     * website so the user gets an immediate connected/rejected verdict. Stored
     * in storage/app (writable, survives deploys) since the box .env is
     * hand-managed and we never sync it from secrets.
     */
    public function bridgeKeySave(Request $request)
    {
        if (!auth()->user()->can('product.create')) {
            abort(403, 'Unauthorized action.');
        }
        $request->validate(['erp_api_key' => 'nullable|string|max:255']);
        $key = trim((string) $request->input('erp_api_key', ''));
        // Tolerate pasting the whole .env line ("ERP_API_KEY=...") or surrounding
        // quotes — store just the value so it matches the website's key.
        if (stripos($key, 'ERP_API_KEY=') === 0) {
            $key = substr($key, strlen('ERP_API_KEY='));
        }
        $key = trim($key, " \t\"'");
        $path = $this->bridgeKeyStorePath();
        $dir = dirname($path);
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }

        if ($key === '') {
            @unlink($path);
            return redirect()->back()->with('status', 'Bridge key cleared.');
        }

        $tmp = $path . '.tmp';
        file_put_contents($tmp, json_encode(
            ['erpApiKey' => $key, 'updatedBy' => $this->actorName(), 'updatedAt' => date('c')],
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
        ));
        @rename($tmp, $path);

        $probe = $this->bridgeProbe();
        if ($probe['state'] === 'connected') {
            return redirect()->back()
                ->with('status', 'Bridge connected — RSVPs and preorders now load inside the ERP.');
        }
        if ($probe['state'] === 'rejected') {
            return redirect()->back()
                ->with('error', 'Key saved, but the website rejected it (HTTP ' . ($probe['code'] ?? '?') . '). It must match the website\'s ERP_API_KEY exactly.');
        }
        return redirect()->back()
            ->with('error', 'Key saved, but the website was unreachable' . ($probe['code'] ? ' (HTTP ' . $probe['code'] . ')' : '') . '. Check the NIVESSA_API URL.');
    }

    /**
     * One-time load of what we ordered, parsed from the 6/23 Hollywood + Pico
     * distributor sheets, into each event's per-store ordered matrix. Matches
     * events by name keyword. Snapshotted + undoable. Idempotent (re-running
     * sets the same values).
     */
    /** What we ordered from the 6/23 sheets: keyword => [ store => [ sku => qty ] ]. */
    public static function orderSeedMap(): array
    {
        return [
            'madonna'    => ['hollywood' => ['indieVinyl' => 20, 'deluxeVinyl' => 8, 'stdVinyl' => 2, 'deluxeCd' => 2, 'stdCd' => 4], 'pico' => ['indieVinyl' => 2, 'deluxeVinyl' => 1, 'stdCd' => 2]],
            'gracie'     => ['hollywood' => ['indieVinyl' => 20, 'stdVinyl' => 5, 'stdCd' => 5], 'pico' => ['indieVinyl' => 10, 'stdVinyl' => 2, 'stdCd' => 3]],
            'heated'     => ['hollywood' => ['stdVinyl' => 11, 'stdCd' => 5], 'pico' => ['stdVinyl' => 11, 'stdCd' => 6]],
            'jack white' => ['hollywood' => ['indieVinyl' => 8, 'stdVinyl' => 2, 'cassette' => 1, 'stdCd' => 1], 'pico' => ['indieVinyl' => 4, 'stdVinyl' => 2, 'cassette' => 1, 'stdCd' => 1]],
        ];
    }

    /** Street dates per event (from the distributor sheets), keyword => Y-m-d. */
    public static function streetDateSeedMap(): array
    {
        return [
            'madonna'    => '2026-07-03',
            'heated'     => '2026-07-10',
            'jack white' => '2026-07-10',
            'gracie'     => '2026-07-17',
        ];
    }

    /**
     * Preorder products per event = the versions we ordered (name + format).
     * Prices left null on purpose — those are retail and get set by hand, not
     * guessed from the distributor cost sheet.
     */
    public static function preorderProductsSeed(): array
    {
        return [
            'madonna' => [
                ['title' => 'Confessions II — White Vinyl / Alt Cover (Indie)', 'format' => 'indieVinyl', 'price' => 30],
                ['title' => 'Confessions II — Translucent Pink 2LP (Deluxe)', 'format' => 'deluxeVinyl', 'price' => 45],
                ['title' => 'Confessions II — Translucent Red 2LP', 'format' => 'stdVinyl', 'price' => 30],
                ['title' => 'Confessions II — CD', 'format' => 'stdCd', 'price' => 15],
                ['title' => 'Confessions II — Premium 2CD / Photobook', 'format' => 'deluxeCd', 'price' => 35],
            ],
            'heated' => [
                ['title' => 'Heated Rivalry (OST) — 2LP', 'format' => 'stdVinyl', 'price' => 57],
                ['title' => 'Heated Rivalry (OST) — 2CD', 'format' => 'stdCd', 'price' => 27],
            ],
            'jack white' => [
                ['title' => 'Frozen Charlotte — Solid Blue Vinyl (Indie)', 'format' => 'indieVinyl', 'price' => 27],
                ['title' => 'Frozen Charlotte — Standard LP', 'format' => 'stdVinyl', 'price' => 26],
                ['title' => 'Frozen Charlotte — Cassette', 'format' => 'cassette', 'price' => 13],
                ['title' => 'Frozen Charlotte — CD', 'format' => 'stdCd', 'price' => 15],
            ],
            'gracie' => [
                ['title' => 'Daughter From Hell — Cobalt 2LP (Indie)', 'format' => 'indieVinyl', 'price' => 49],
                ['title' => 'Daughter From Hell — Standard 2LP', 'format' => 'stdVinyl', 'price' => 49],
                ['title' => 'Daughter From Hell — CD', 'format' => 'stdCd', 'price' => 19],
            ],
        ];
    }

    /** Apply the order + street-date seed to one business's store; returns events updated. */
    public static function applyOrderSeed(int $business_id): int
    {
        $data = self::load($business_id);
        $seed = self::orderSeedMap();
        $streets = self::streetDateSeedMap();
        $preorderSeed = self::preorderProductsSeed();
        $matched = 0;
        $snapshotted = false;
        foreach ($data['items'] as $id => $ev) {
            $name = mb_strtolower((string) ($ev['name'] ?? ''));
            foreach ($seed as $kw => $stores) {
                if (mb_strpos($name, $kw) === false) {
                    continue;
                }
                if (!$snapshotted) {
                    self::snapshot($business_id, $data, 'events-seed-orders');
                    $snapshotted = true;
                }
                $data['items'][$id]['ordered'] = self::cleanOrdered($stores);
                if (!empty($streets[$kw])) {
                    $data['items'][$id]['streetDate'] = $streets[$kw];
                }
                // Seed the version list for ERP reference ONLY when none exists
                // yet. Never touch preorderEnabled or overwrite an existing
                // products list — preorders are Sarah's to control in the ERP
                // now, and this runs on every deploy (it must not revert her).
                if (isset($preorderSeed[$kw]) && empty((array) ($data['items'][$id]['preorderProducts'] ?? []))) {
                    $data['items'][$id]['preorderProducts'] = $preorderSeed[$kw];
                }
                $data['items'][$id]['updatedAt'] = date('c');
                $matched++;
                break;
            }
        }
        if ($matched) {
            self::save($business_id, $data);
        }
        return $matched;
    }

    public function seedOrders(Request $request)
    {
        if (!auth()->user()->can('product.create')) {
            abort(403, 'Unauthorized action.');
        }
        $matched = self::applyOrderSeed($this->businessId($request));
        return redirect()->route('events.index')
            ->with('status', "Loaded distributor orders into {$matched} event(s). Undo from Admin Action History.");
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
     * One-time tidy: drop the sub-location detail (e.g. "Stage") from every
     * listening-party event so they read just "Hollywood" / "Pico". Snapshots
     * first so it's undoable from Admin Action History.
     */
    public function tidyLocations(Request $request)
    {
        if (!auth()->user()->can('product.create')) {
            abort(403, 'Unauthorized action.');
        }
        $business_id = $this->businessId($request);
        $data = self::load($business_id);

        $targets = [];
        foreach ($data['items'] as $id => $ev) {
            if (($ev['eventType'] ?? '') === 'listening_party' && !empty($ev['locationDetail'])) {
                $targets[] = $id;
            }
        }
        if (empty($targets)) {
            return redirect()->route('events.index')->with('status', 'No listening parties had a sub-location to clear.');
        }

        self::snapshot($business_id, $data, 'events-tidy-locations');
        $now = date('c');
        foreach ($targets as $id) {
            $data['items'][$id]['locationDetail'] = null;
            $data['items'][$id]['updatedAt'] = $now;
        }
        self::save($business_id, $data);

        $n = count($targets);
        return redirect()->route('events.index')
            ->with('status', "Cleared the sub-location on {$n} listening part" . ($n === 1 ? 'y' : 'ies') . '. Undo from Admin Action History.');
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

        // Details: only the known keys. eventHostHollywood/eventHostPico hold
        // the per-store event lead when a party runs at both stores.
        $incomingDetails = (array) $request->input('details', []);
        foreach (['eventHost', 'eventHostHollywood', 'eventHostPico', 'eventLink', 'boxTracking', 'boxLocation'] as $k) {
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
                // ERP-only fields; the website feed doesn't carry them, so keep
                // anything entered here rather than wiping it on import.
                'streetDate'       => $existing['streetDate'] ?? ($e['streetDate'] ?? null),
                'ordered'          => self::cleanOrdered((array) ($existing['ordered'] ?? $e['ordered'] ?? [])),
                'description'      => (string) ($e['description'] ?? ''),
                'image'            => $e['image'] ?? null,
                'location'         => array_values(array_intersect((array) ($e['location'] ?? []), ['hollywood', 'pico'])),
                'locationDetail'   => $e['locationDetail'] ?? null,
                // Preorder settings are ERP-owned now (ERP is the source of
                // truth). For an event that already exists here, KEEP the local
                // values so a re-import never reverts a preorder Sarah enabled.
                'preorderEnabled'  => array_key_exists('preorderEnabled', $existing)
                    ? filter_var($existing['preorderEnabled'], FILTER_VALIDATE_BOOLEAN)
                    : filter_var($e['preorderEnabled'] ?? false, FILTER_VALIDATE_BOOLEAN),
                'preorderProducts' => array_values((array) ($existing['preorderProducts'] ?? $e['preorderProducts'] ?? [])),
                'preorderTitle'    => array_key_exists('preorderTitle', $existing) ? (string) $existing['preorderTitle'] : (string) ($e['preorderTitle'] ?? ''),
                'preorderPrice'    => array_key_exists('preorderPrice', $existing)
                    ? ($existing['preorderPrice'] !== null ? round((float) $existing['preorderPrice'], 2) : null)
                    : (isset($e['preorderPrice']) && $e['preorderPrice'] !== null ? round((float) $e['preorderPrice'], 2) : null),
                'preorderPickupDate' => array_key_exists('preorderPickupDate', $existing) ? $existing['preorderPickupDate'] : ($e['preorderPickupDate'] ?? null),
                'preorderNote'     => array_key_exists('preorderNote', $existing) ? (string) $existing['preorderNote'] : (string) ($e['preorderNote'] ?? ''),
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
