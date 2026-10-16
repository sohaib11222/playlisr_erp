<?php

namespace App\Http\Controllers\WebsiteAdmin;

use App\Http\Controllers\Controller;
use App\Services\WebsiteBridge;
use Illuminate\Http\Request;

/**
 * Website sync and import tools, moved from nivessa.com /admin/dashboard,
 * /admin/sync-dashboard, /admin/auto-sync, /admin/pos-image-refresh,
 * /admin/pos-artist-refresh, /admin/bulk-discogs-import,
 * /admin/background-import, /admin/import-status and /admin/abandoned-carts
 * (Sarah, 2026-10-15).
 *
 * The job pages poll, so they render a shell and call api() with small
 * fetch()es. api() only forwards the exact website endpoints those pages
 * use (ENDPOINTS); "/_api/<x>" paths are the website's /api/<x> tool
 * mounts (server routes/erp.routes.js admin-proxy).
 */
class WebsiteToolsController extends Controller
{
    // [method, path regex (no query string), timeout seconds, admin only]
    const ENDPOINTS = [
        ['GET', '#^/_api/auto-sync/(status|jobs|artist-stats|test-discogs)$#', 30, false],
        ['GET', '#^/_api/auto-sync/(audit|orphans)$#', 90, false],
        ['POST', '#^/_api/auto-sync/(immediate|refresh-pos-images|discogs-stock-sync)$#', 30, false],
        ['POST', '#^/_api/auto-sync/(start|stop|toggle|release-rematch|update-discogs-token)$#', 30, true],
        ['POST', '#^/_api/auto-sync/reconcile-orphans$#', 180, true],
        ['GET', '#^/_api/product-import/status$#', 45, false],
        ['POST', '#^/_api/product-import/import$#', 600, true],
        ['DELETE', '#^/_api/product-import/clear$#', 180, true],
        ['GET', '#^/_api/import/background/(status|history)$#', 30, false],
        ['POST', '#^/_api/import/background/(start|clean)$#', 30, true],
        ['DELETE', '#^/_api/import/background/cancel/[A-Za-z0-9:_-]+$#', 30, true],
        ['GET', '#^/_api/pos-image-refresh/(status|upgrade-status|upgrade-stats)$#', 30, false],
        ['GET', '#^/_api/pos-image-refresh/stats$#', 90, false],
        ['POST', '#^/_api/pos-image-refresh/(start|cancel|upgrade-start|upgrade-cancel)$#', 30, false],
        ['POST', '#^/_api/pos-image-refresh/(single|fix-artist-photos)$#', 120, false],
        ['GET', '#^/_api/pos-artist-refresh/(stats|status)$#', 60, false],
        ['POST', '#^/_api/pos-artist-refresh/(start|cancel)$#', 30, false],
        ['POST', '#^/_api/pos-artist-refresh/single$#', 120, false],
        ['GET', '#^/_api/synced-products$#', 20, false],
        ['GET', '#^/product/pos-live-catalogue-total$#', 20, false],
        ['GET', '#^/product/discogs-source-total$#', 30, false],
        ['GET', '#^/product/floor-vinyl-prices/preview$#', 60, false],
        ['POST', '#^/product/floor-vinyl-prices/(apply|undo)$#', 180, true],
        ['POST', '#^/product/full-sync/trigger$#', 30, true],
        ['POST', '#^/product/(recategorize-audio-gear|resync-audio-gear-from-pos)$#', 300, false],
    ];

    // Dashboard tiles, same order and links as the website's METRICS_CONFIG.
    // 'erp' is the ERP route that replaces the website page, when one exists.
    const METRICS = [
        ['key' => 'requestInStock', 'title' => 'Request In Stock', 'href' => '/admin/request-outofstock', 'icon' => 'fa-box'],
        ['key' => 'artistSubmissions', 'title' => 'Artist Submissions', 'href' => '/admin/artist-submissions', 'icon' => 'fa-music'],
        ['key' => 'storeClaims', 'title' => 'Store Claims', 'href' => '/admin/store-claims', 'icon' => 'fa-shield-alt'],
        ['key' => 'users', 'title' => 'Users', 'href' => '/admin/users', 'icon' => 'fa-users'],
        ['key' => 'newSearchedQueries', 'title' => 'New Searched Queries', 'href' => '/admin/most-searched', 'icon' => 'fa-search'],
        ['key' => 'directorySearches', 'title' => 'Directory Searches', 'href' => '/admin/directory-searches', 'icon' => 'fa-map-marker-alt'],
        ['key' => 'emailSubscribers', 'title' => 'Email Subscribers', 'href' => '/admin/email-subscribers', 'icon' => 'fa-envelope'],
        ['key' => 'contactMessages', 'title' => 'Contact Messages', 'href' => '/admin/contacts', 'icon' => 'fa-comment'],
        ['key' => 'buySellQueries', 'title' => 'Buy/Sell Queries', 'href' => '/admin/buy-sell-queries', 'icon' => 'fa-store'],
        ['key' => 'eventsAdded', 'title' => 'Events Added', 'href' => '/admin/events', 'icon' => 'fa-calendar', 'erp' => 'events.index'],
        ['key' => 'communityEventsAdded', 'title' => 'Community Events Added', 'href' => '/admin/community-events', 'icon' => 'fa-calendar-alt'],
        ['key' => 'eventRsvps', 'title' => 'Event RSVPs', 'href' => '/admin/rsvps', 'icon' => 'fa-ticket-alt'],
        ['key' => 'venueBookings', 'title' => 'Venue Bookings', 'href' => '/admin/bookings', 'icon' => 'fa-building', 'erp' => 'wa.bookings.index'],
        ['key' => 'storeRequests', 'title' => 'Store Requests', 'href' => '/admin/store-requests', 'icon' => 'fa-location-arrow'],
        ['key' => 'askNivessaChats', 'title' => 'Ask Nivessa Chats', 'href' => '/admin/conversations', 'icon' => 'fa-robot'],
    ];

    const CART_STALE_OPTIONS = ['30' => '30 minutes', '60' => '1 hour', '180' => '3 hours', '1440' => '1 day'];

    const CART_PAGE_SIZE = 25;

    protected function guard()
    {
        if (!auth()->user()->can('product.create') && !auth()->user()->can('sell.create')) {
            abort(403, 'Unauthorized action.');
        }
    }

    protected function isAdmin(): bool
    {
        return auth()->user()->hasRole('Admin#' . session('business.id'));
    }

    // ------------------------------------------------------------ pages

    public function dashboard(Request $request)
    {
        $this->guard();

        $end = $this->validDate($request->input('end')) ?: date('Y-m-d');
        $start = $this->validDate($request->input('start')) ?: date('Y-m-d', strtotime($end . ' -30 days'));
        if ($request->filled('days')) {
            $days = max(1, min(366, (int) $request->input('days')));
            $end = date('Y-m-d');
            $start = date('Y-m-d', strtotime("-{$days} days"));
        }

        $resp = WebsiteBridge::admin('GET', '/admin/dashboard/summary?' . http_build_query(['startDate' => $start, 'endDate' => $end]), null, 25);
        $data = $resp['data'] ?? [];
        $metrics = is_array($data['metrics'] ?? null) ? $data['metrics'] : [];
        $total = (int) ($data['total'] ?? 0);

        $rows = [];
        foreach (self::METRICS as $m) {
            $m['value'] = (int) ($metrics[$m['key']] ?? 0);
            $m['url'] = (!empty($m['erp']) && \Route::has($m['erp'])) ? route($m['erp']) : 'https://nivessa.com' . $m['href'];
            $m['external'] = empty($m['erp']) || !\Route::has($m['erp']);
            $rows[] = $m;
        }
        $ranked = $rows;
        usort($ranked, function ($a, $b) {
            return $b['value'] <=> $a['value'];
        });
        $rank = [];
        foreach ($ranked as $i => $r) {
            $rank[$r['key']] = $i + 1;
        }
        $avg = count($rows) ? $total / count($rows) : 0;
        $v = function ($k) use ($metrics) {
            return (int) ($metrics[$k] ?? 0);
        };
        $searches = $v('newSearchedQueries') + $v('directorySearches');
        $leads = $v('emailSubscribers') + $v('contactMessages') + $v('buySellQueries') + $v('storeClaims') + $v('artistSubmissions') + $v('requestInStock');
        $actions = $v('eventRsvps') + $v('venueBookings');
        $funnel = [
            ['stage' => 'Search Signals', 'value' => $searches, 'conversion' => 100],
            ['stage' => 'Leads', 'value' => $leads, 'conversion' => $searches > 0 ? round($leads / $searches * 100, 1) : 0],
            ['stage' => 'Actions', 'value' => $actions, 'conversion' => $leads > 0 ? round($actions / $leads * 100, 1) : 0],
        ];
        $channels = [
            ['channel' => 'Search Traffic', 'value' => $searches],
            ['channel' => 'Direct Inquiries', 'value' => $v('contactMessages') + $v('buySellQueries')],
            ['channel' => 'Subscriber Growth', 'value' => $v('emailSubscribers')],
            ['channel' => 'Claims & Submissions', 'value' => $v('storeClaims') + $v('artistSubmissions') + $v('requestInStock')],
            ['channel' => 'Event Demand', 'value' => $actions],
        ];
        usort($channels, function ($a, $b) {
            return $b['value'] <=> $a['value'];
        });

        return view('website_admin.tools.dashboard', [
            'ok' => !empty($resp['success']),
            'error' => empty($resp['success']) ? ($resp['message'] ?? 'Could not load the dashboard summary.') : null,
            'start' => $start,
            'end' => $end,
            'total' => $total,
            'rows' => $rows,
            'ranked' => $ranked,
            'rank' => $rank,
            'avg' => $avg,
            'funnel' => $funnel,
            'channels' => $channels,
        ]);
    }

    public function syncDashboard()
    {
        $this->guard();
        return view('website_admin.tools.sync_dashboard');
    }

    public function autoSync()
    {
        $this->guard();
        return view('website_admin.tools.auto_sync', ['isAdmin' => $this->isAdmin()]);
    }

    public function posImageRefresh()
    {
        $this->guard();
        return view('website_admin.tools.pos_image_refresh');
    }

    public function posArtistRefresh()
    {
        $this->guard();
        return view('website_admin.tools.pos_artist_refresh');
    }

    public function bulkDiscogsImport()
    {
        $this->guard();
        return view('website_admin.tools.bulk_discogs_import', ['isAdmin' => $this->isAdmin()]);
    }

    public function backgroundImport()
    {
        $this->guard();
        return view('website_admin.tools.background_import', ['isAdmin' => $this->isAdmin()]);
    }

    public function importStatus()
    {
        $this->guard();
        return view('website_admin.tools.import_status', ['isAdmin' => $this->isAdmin()]);
    }

    public function abandonedCarts(Request $request)
    {
        $this->guard();

        $tab = in_array($request->input('tab'), ['live', 'bookings'], true) ? $request->input('tab') : 'checkouts';
        if ($tab === 'bookings') {
            $rows = \App\Http\Controllers\WebsiteAdmin\WebsiteBookingsController::unpaidRequests($error);
            return view('website_admin.tools.abandoned_carts', [
                'tab' => 'bookings', 'stale' => '30', 'staleOptions' => self::CART_STALE_OPTIONS,
                'error' => $error, 'bookingRows' => $rows,
                'rows' => [], 'allCount' => 0, 'summary' => ['count' => 0, 'totalValue' => 0],
                'page' => 1, 'totalPages' => 1, 'timeField' => 'createdAt', 'updatedAt' => now(),
            ]);
        }
        $stale = (string) $request->input('stale', '30');
        if (!isset(self::CART_STALE_OPTIONS[$stale])) {
            $stale = '30';
        }
        $path = ($tab === 'live' ? '/admin/abandoned-carts/live' : '/admin/abandoned-carts') . '?staleMinutes=' . $stale;
        $resp = WebsiteBridge::admin('GET', $path, null, 30);
        $rows = (!empty($resp['success']) && is_array($resp['data'] ?? null)) ? array_values($resp['data']) : [];
        $summary = is_array($resp['summary'] ?? null) ? $resp['summary'] : ['count' => 0, 'totalValue' => 0];

        $totalPages = max(1, (int) ceil(count($rows) / self::CART_PAGE_SIZE));
        $page = max(1, min($totalPages, (int) $request->input('page', 1)));

        return view('website_admin.tools.abandoned_carts', [
            'tab' => $tab,
            'stale' => $stale,
            'staleOptions' => self::CART_STALE_OPTIONS,
            'error' => empty($resp['success']) ? ($resp['message'] ?? 'Failed to load.') : null,
            'rows' => array_slice($rows, ($page - 1) * self::CART_PAGE_SIZE, self::CART_PAGE_SIZE),
            'allCount' => count($rows),
            'summary' => $summary,
            'page' => $page,
            'totalPages' => $totalPages,
            'timeField' => $tab === 'live' ? 'lastActivityAt' : 'createdAt',
            'updatedAt' => now(),
        ]);
    }

    // ------------------------------------------------------------ JSON

    /** Forwards one allowlisted website call. Body: {method, path, query?, body?}. */
    public function api(Request $request)
    {
        $this->guard();

        $method = strtoupper((string) $request->input('method', 'GET'));
        $path = (string) $request->input('path', '');
        $match = null;
        foreach (self::ENDPOINTS as $e) {
            if ($e[0] === $method && preg_match($e[1], $path)) {
                $match = $e;
                break;
            }
        }
        if (!$match) {
            return response()->json(['success' => false, 'message' => 'That website action is not available here.'], 404);
        }
        if ($match[3] && !$this->isAdmin()) {
            return response()->json(['success' => false, 'message' => 'Only an admin can do this.'], 403);
        }

        $query = $request->input('query');
        if (is_array($query) && $query) {
            $path .= '?' . $this->buildQuery($query);
        }
        $body = $request->input('body');
        if (!is_array($body)) {
            $body = in_array($method, ['POST', 'PUT', 'PATCH', 'DELETE'], true) ? [] : null;
        }

        @set_time_limit($match[2] + 30);
        $resp = WebsiteBridge::admin($method, $path, $body, $match[2]);
        return response()->json($resp);
    }

    /**
     * Bulk Discogs import streams NDJSON progress lines; pass them through
     * as they arrive. Closing the page (Cancel) stops the stream, same as
     * aborting the fetch on the website.
     */
    public function bulkDiscogsStream(Request $request)
    {
        $this->guard();
        if (!$this->isAdmin()) {
            return response()->json(['status' => 'error', 'error' => 'Only an admin can run a bulk import.'], 403);
        }

        $target = max(1, min(5000, (int) $request->input('targetProducts', 500)));
        $startPage = trim((string) $request->input('startPage', 'auto'));
        $startPage = ($startPage === '' || strtolower($startPage) === 'auto') ? 'auto' : max(1, (int) $startPage);

        // WebsiteBridge only returns whole JSON bodies; borrow its key and
        // base URL for a streaming curl.
        $bridge = new class extends WebsiteBridge {
            public static function streamTarget(): array
            {
                return [static::apiKey(), static::baseUrl()];
            }
        };
        list($key, $base) = $bridge::streamTarget();
        $actor = WebsiteBridge::actor();

        return response()->stream(function () use ($key, $base, $actor, $target, $startPage) {
            @set_time_limit(0);
            while (ob_get_level() > 0) {
                @ob_end_flush();
            }
            $line = function (array $row) {
                echo json_encode($row) . "\n";
                flush();
            };
            if ($key === '') {
                $line(['status' => 'error', 'error' => 'No ERP bridge key configured.']);
                return;
            }
            $ch = curl_init($base . '/erp/admin-proxy/_api/product-import/bulk-discogs');
            curl_setopt_array($ch, [
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => json_encode(['targetProducts' => $target, 'startPage' => $startPage]),
                CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'x-erp-key: ' . $key, 'x-erp-user: ' . $actor],
                CURLOPT_TIMEOUT => 3 * 3600,
                CURLOPT_WRITEFUNCTION => function ($ch, $chunk) {
                    if (connection_aborted()) {
                        return 0;
                    }
                    echo $chunk;
                    flush();
                    return strlen($chunk);
                },
            ]);
            $ok = curl_exec($ch);
            $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $err = curl_error($ch);
            curl_close($ch);
            if ($ok === false && !connection_aborted()) {
                $line(['status' => 'error', 'error' => 'Could not reach the website' . ($err !== '' ? " ({$err})" : '') . '.']);
            } elseif ($code >= 400) {
                $line(['status' => 'error', 'error' => "Website returned HTTP {$code}."]);
            }
        }, 200, [
            'Content-Type' => 'application/x-ndjson',
            'Cache-Control' => 'no-cache',
            'X-Accel-Buffering' => 'no',
        ]);
    }

    // ------------------------------------------------------------ helpers

    /** Repeats array keys (categories=A&categories=B) so values with commas survive. */
    protected function buildQuery(array $query): string
    {
        $parts = [];
        foreach ($query as $k => $v) {
            if ($v === null || $v === '') {
                continue;
            }
            foreach ((array) $v as $item) {
                if (is_bool($item)) {
                    $item = $item ? 'true' : 'false';
                }
                if (is_scalar($item)) {
                    $parts[] = rawurlencode((string) $k) . '=' . rawurlencode((string) $item);
                }
            }
        }
        return implode('&', $parts);
    }

    protected function validDate($value)
    {
        $value = (string) $value;
        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) ? $value : null;
    }
}
