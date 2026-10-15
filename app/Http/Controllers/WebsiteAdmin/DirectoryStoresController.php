<?php

namespace App\Http\Controllers\WebsiteAdmin;

use App\Services\WebsiteBridge;
use Carbon\Carbon;
use Illuminate\Http\Request;

/**
 * Record store directory profiles + per-store events, moved from the
 * website's /admin/directory-stores (Sarah, 2026-10-15). Same handlers:
 * /admin/record-stores/stores[/:id[/events[/:eventId]]].
 */
class DirectoryStoresController extends DirectoryBaseController
{
    const STATUSES = ['published', 'approved', 'pending', 'draft', 'rejected'];
    const SORTS = [
        'updatedAt'    => 'Last updated',
        'createdAt'    => 'Created',
        'store_name'   => 'Store name',
        'country'      => 'Country',
        'state'        => 'State',
        'city'         => 'City',
        'status'       => 'Status',
        'submitted_at' => 'Submitted at',
        'reviewed_at'  => 'Reviewed at',
    ];
    const TEXT_FIELDS = [
        'store_name', 'primary_category', 'country', 'state', 'city', 'address', 'postal_code',
        'phone', 'email', 'website_url', 'map_url', 'opening_hours', 'description',
        'meta_title', 'meta_description', 'image_url', 'instagram_url', 'facebook_url', 'twitter_url',
    ];

    public function index(Request $request)
    {
        $this->guard();

        $f = [
            'page'             => max(1, (int) $request->input('page', 1)),
            'limit'            => in_array((int) $request->input('limit'), [20, 50, 100, 250], true) ? (int) $request->input('limit') : 50,
            'q'                => trim((string) $request->input('q', '')),
            'status'           => (string) $request->input('status', 'all'),
            'country'          => trim((string) $request->input('country', '')),
            'city'             => trim((string) $request->input('city', '')),
            'primary_category' => trim((string) $request->input('primary_category', '')),
            'sortBy'           => array_key_exists($request->input('sortBy'), self::SORTS) ? $request->input('sortBy') : 'updatedAt',
            'sortOrder'        => $request->input('sortOrder') === 'asc' ? 'asc' : 'desc',
        ];

        $resp = self::call('GET', '/admin/record-stores/stores?' . http_build_query($f));
        $stores = self::rows($resp);
        $pagination = (array) ($resp['pagination'] ?? []) + [
            'page' => $f['page'], 'limit' => $f['limit'], 'total' => count($stores),
            'totalPages' => 1, 'hasNextPage' => false, 'hasPrevPage' => $f['page'] > 1,
        ];

        // Option lists are public endpoints; a failure just leaves the datalists empty.
        $countries = self::optionList(self::call('GET', '/record-stores/countries'));
        $categories = self::optionList(self::call('GET', '/record-stores/categories'));

        $isNew = $request->input('mode') === 'new';
        $selectedId = $isNew ? '' : (string) $request->input('store', '');
        if (!$isNew && $selectedId === '' && !empty($stores[0]['_id'])) {
            $selectedId = (string) $stores[0]['_id'];
        }

        $store = null;
        $events = [];
        $eventsError = null;
        if ($selectedId !== '') {
            // The list payload is slim (description/socials often missing), so load the full record.
            $detail = self::call('GET', '/admin/record-stores/stores/' . rawurlencode($selectedId));
            $store = !empty($detail['success']) && is_array($detail['data'] ?? null) ? $detail['data'] : null;
            if (!$store) {
                foreach ($stores as $s) {
                    if ((string) ($s['_id'] ?? '') === $selectedId) {
                        $store = $s;
                    }
                }
            }
            if ($store) {
                $ev = self::call('GET', '/admin/record-stores/stores/' . rawurlencode($selectedId) . '/events');
                if (!empty($ev['success'])) {
                    $events = self::rows($ev);
                } else {
                    $eventsError = $ev['message'] ?? 'Could not load events.';
                }
            }
        }

        $states = [];
        $cities = [];
        if ($store && trim((string) ($store['country'] ?? '')) !== '') {
            $states = self::optionList(self::call('GET', '/record-stores/' . rawurlencode(trim($store['country'])) . '/states'));
            if (trim((string) ($store['state'] ?? '')) !== '') {
                $cities = self::optionList(self::call('GET', '/record-stores/' . rawurlencode(trim($store['country'])) . '/' . rawurlencode(trim($store['state'])) . '/cities'));
            }
        }

        $editEventId = (string) $request->input('event', '');
        $editEvent = null;
        foreach ($events as $e) {
            if ($editEventId !== '' && (string) ($e['_id'] ?? '') === $editEventId) {
                $editEvent = $e;
            }
        }

        return view('website_admin.directory.stores', [
            'stores'      => $stores,
            'listError'   => empty($resp['success']) ? ($resp['message'] ?? 'Could not load stores.') : null,
            'pagination'  => $pagination,
            'f'           => $f,
            'sorts'       => self::SORTS,
            'statuses'    => self::STATUSES,
            'countries'   => $countries,
            'categories'  => $categories,
            'states'      => $states,
            'cities'      => $cities,
            'isNew'       => $isNew,
            'selectedId'  => $store ? $selectedId : '',
            'store'       => $store,
            'isLegacy'    => ($store['source'] ?? '') === 'legacy',
            'events'      => $events,
            'eventsError' => $eventsError,
            'editEvent'   => $editEvent,
        ]);
    }

    /** Datalist options for the profile form when country/state change. */
    public function options(Request $request)
    {
        $this->guard();
        $country = trim((string) $request->input('country', ''));
        $state = trim((string) $request->input('state', ''));
        if ($country === '') {
            return response()->json(['states' => [], 'cities' => []]);
        }
        $out = ['states' => self::optionList(self::call('GET', '/record-stores/' . rawurlencode($country) . '/states')), 'cities' => []];
        if ($state !== '') {
            $out['cities'] = self::optionList(self::call('GET', '/record-stores/' . rawurlencode($country) . '/' . rawurlencode($state) . '/cities'));
        }
        return response()->json($out);
    }

    public function save(Request $request)
    {
        $this->guard();
        $id = trim((string) $request->input('id', ''));

        $fields = [];
        foreach (self::TEXT_FIELDS as $k) {
            $fields[$k] = trim((string) $request->input($k, ''));
        }
        $fields['status'] = in_array($request->input('status'), self::STATUSES, true) ? $request->input('status') : 'published';
        $fields['is_active'] = $request->boolean('is_active') ? 'true' : 'false';
        // Arrays go up JSON-encoded; the website's normalizeArray parses them.
        $fields['categories'] = self::csv($request->input('categories', ''));
        $fields['gallery_images'] = self::csv($request->input('gallery_images', ''));

        $files = ['image' => $request->file('image')];
        $resp = $id === ''
            ? WebsiteBridge::adminUpload('POST', '/admin/record-stores/stores', $fields, $files)
            : WebsiteBridge::adminUpload('PUT', '/admin/record-stores/stores/' . rawurlencode($id), $fields, $files);

        if (empty($resp['success'])) {
            return back()->withInput()->with('error', $resp['message'] ?? 'The website did not save the store.');
        }
        $newId = (string) ($resp['data']['_id'] ?? $id);
        return redirect()->route('wa.directory.stores', array_filter(['store' => $newId] + $this->keepFilters($request)))
            ->with('status', $id === '' ? 'Directory store created.' : 'Directory store saved.');
    }

    public function destroy(Request $request, string $id)
    {
        $this->guard();
        $resp = self::call('DELETE', '/admin/record-stores/stores/' . rawurlencode($id));
        if (empty($resp['success'])) {
            return back()->with('error', $resp['message'] ?? 'The website did not delete the store.');
        }
        return redirect()->route('wa.directory.stores', $this->keepFilters($request))->with('status', 'Store deleted, with its events.');
    }

    public function eventSave(Request $request, string $id)
    {
        $this->guard();
        $eventId = trim((string) $request->input('event_id', ''));
        $title = trim((string) $request->input('title', ''));
        $start = self::toIso($request->input('start_at'));
        if ($title === '' || $start === '') {
            return back()->withInput()->with('error', 'Event title and start date are required.');
        }
        $fields = [
            'title'       => $title,
            'description' => trim((string) $request->input('description', '')),
            'image_url'   => trim((string) $request->input('image_url', '')),
            'event_url'   => trim((string) $request->input('event_url', '')),
            'venue_name'  => trim((string) $request->input('venue_name', '')),
            'start_at'    => $start,
            'end_at'      => self::toIso($request->input('end_at')),
            'is_active'   => $request->boolean('is_active') ? 'true' : 'false',
        ];
        $base = '/admin/record-stores/stores/' . rawurlencode($id) . '/events';
        $files = ['image' => $request->file('image')];
        $resp = $eventId === ''
            ? WebsiteBridge::adminUpload('POST', $base, $fields, $files)
            : WebsiteBridge::adminUpload('PUT', $base . '/' . rawurlencode($eventId), $fields, $files);
        if (empty($resp['success'])) {
            return back()->withInput()->with('error', $resp['message'] ?? 'The website did not save the event.');
        }
        return redirect()->route('wa.directory.stores', ['store' => $id] + $this->keepFilters($request))
            ->with('status', $eventId === '' ? 'Event added.' : 'Event updated.');
    }

    public function eventDestroy(Request $request, string $id, string $eventId)
    {
        $this->guard();
        $resp = self::call('DELETE', '/admin/record-stores/stores/' . rawurlencode($id) . '/events/' . rawurlencode($eventId));
        if (empty($resp['success'])) {
            return back()->with('error', $resp['message'] ?? 'The website did not delete the event.');
        }
        return redirect()->route('wa.directory.stores', ['store' => $id] + $this->keepFilters($request))->with('status', 'Event deleted.');
    }

    /** List filters ride along in hidden f[...] inputs so the list stays put after a save. */
    protected function keepFilters(Request $request): array
    {
        $keep = [];
        foreach ((array) $request->input('f', []) as $k => $v) {
            if (in_array($k, ['page', 'limit', 'q', 'status', 'country', 'city', 'primary_category', 'sortBy', 'sortOrder'], true) && $v !== '' && $v !== null) {
                $keep[$k] = $v;
            }
        }
        return $keep;
    }

    protected static function csv($value): array
    {
        return array_values(array_filter(array_map('trim', explode(',', (string) $value)), 'strlen'));
    }

    /** datetime-local (Pacific) to ISO UTC; '' when blank. */
    protected static function toIso($local): string
    {
        $local = trim((string) $local);
        if ($local === '') {
            return '';
        }
        try {
            return Carbon::parse($local, 'America/Los_Angeles')->setTimezone('UTC')->format('Y-m-d\TH:i:s\Z');
        } catch (\Throwable $e) {
            return '';
        }
    }

    protected static function optionList(array $resp): array
    {
        if (empty($resp['success'])) {
            return [];
        }
        $raw = $resp['data'] ?? [];
        // Some option endpoints wrap again ({success, data:[...]}).
        if (is_array($raw) && isset($raw['data']) && is_array($raw['data'])) {
            $raw = $raw['data'];
        }
        $out = [];
        foreach ((array) $raw as $item) {
            if (is_string($item)) {
                $v = trim($item);
            } elseif (is_array($item)) {
                $v = trim((string) ($item['name'] ?? $item['label'] ?? ''));
            } else {
                $v = '';
            }
            if ($v !== '') {
                $out[] = $v;
            }
        }
        return array_values(array_unique($out));
    }
}
