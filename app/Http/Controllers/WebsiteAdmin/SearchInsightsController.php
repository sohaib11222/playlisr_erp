<?php

namespace App\Http\Controllers\WebsiteAdmin;

use App\Http\Controllers\Controller;
use App\Services\WebsiteBridge;
use Illuminate\Http\Request;

/**
 * nivessa.com search and demand reports, moved from /admin/most-searched,
 * /admin/bst-search-queries and /admin/sealed-velocity (Sarah, 2026-10-15).
 */
class SearchInsightsController extends Controller
{
    use WaContentHelpers;

    const PAGE_SIZES = [500, 1000, 1500, 2500, 5000, 10000];

    public function mostSearched(Request $request)
    {
        $this->guardStaff();
        $page = max(1, (int) $request->input('page', 1));
        $limit = (int) $request->input('limit', 500);
        if (!in_array($limit, self::PAGE_SIZES, true)) {
            $limit = 500;
        }
        $q = WebsiteBridge::admin('GET', '/search-log/top-queries?page=' . $page . '&limit=' . $limit, null, 45);
        $artists = WebsiteBridge::admin('GET', '/search-log/top-artist-queries', null, 45);
        $pg = $this->pick($q, 'pagination', []) ?: [];
        $queries = $this->listFrom($q);
        $artistList = $this->listFrom($artists);
        [$stockLocations, $stockFor] = $this->stockMatcher();
        foreach ($queries as &$row) {
            $row['_stock'] = $stockFor((string) ($row['_id'] ?? ''));
        }
        unset($row);
        foreach ($artistList as &$row) {
            $row['_stock'] = $stockFor((string) ($row['query'] ?? ''));
        }
        unset($row);
        return view('website_admin.content.most_searched', [
            'stockLocations' => $stockLocations,
            'bridgeError' => empty($q['success']) ? ($q['message'] ?? 'Could not load search data.') : null,
            'queries' => $queries,
            'artists' => $artistList,
            'artistError' => empty($artists['success']) ? ($artists['message'] ?? 'Could not load artist data.') : null,
            'page' => (int) ($pg['page'] ?? $page),
            'limit' => (int) ($pg['limit'] ?? $limit),
            'total' => (int) ($pg['total'] ?? 0),
            'totalPages' => max(1, (int) ($pg['totalPages'] ?? 1)),
            'pageSizes' => self::PAGE_SIZES,
            'loadedAt' => now()->setTimezone('America/Los_Angeles')->format('M j, Y g:i A'),
        ]);
    }

    /** Words dropped from a search before matching: format words and filler. */
    const STOCK_SKIP_WORDS = ['the', 'a', 'an', 'and', 'of', 'vinyl', 'vinyls', 'record', 'records', 'lp', 'lps', 'cd', 'cds', 'album', 'albums'];

    /**
     * In-stock units per location for each search term (Sarah 2026-11-12:
     * Pico / Hollywood / Discogs columns on Most Searched). A product matches
     * when every word of the search (minus format/filler words) is a word in
     * its artist or name. Returns [[location_id => name], fn(query) => [location_id => units] | null].
     */
    protected function stockMatcher()
    {
        $business_id = (int) session('business.id');
        $names = \DB::table('business_locations')->where('business_id', $business_id)->pluck('name', 'id')->all();

        $words = [];   // word => [product index => true]
        $qty = [];     // product index => [location_id => units]
        $pidx = [];
        $stockLocs = [];
        $rows = \DB::table('variation_location_details as vld')
            ->join('products as p', 'p.id', '=', 'vld.product_id')
            ->where('p.business_id', $business_id)
            ->where('p.is_inactive', 0)
            ->where('vld.qty_available', '>', 0)
            ->groupBy('p.id', 'vld.location_id')
            ->selectRaw('p.id, MAX(p.artist) as artist, MAX(p.name) as name, vld.location_id, SUM(vld.qty_available) as qty')
            ->cursor();
        foreach ($rows as $r) {
            if (!isset($pidx[$r->id])) {
                $i = count($pidx);
                $pidx[$r->id] = $i;
                foreach ($this->stockWords($r->artist . ' ' . $r->name) as $w) {
                    $words[$w][$i] = true;
                }
            }
            $i = $pidx[$r->id];
            $loc = (int) $r->location_id;
            $qty[$i][$loc] = ($qty[$i][$loc] ?? 0) + (float) $r->qty;
            $stockLocs[$loc] = true;
        }

        // Pico, Hollywood, then the rest (Discogs etc.) by name.
        $locations = [];
        foreach ($names as $id => $name) {
            if (isset($stockLocs[(int) $id])) {
                $locations[(int) $id] = (string) $name;
            }
        }
        uksort($locations, function ($a, $b) use ($locations) {
            $rank = function ($n) {
                $n = mb_strtolower($n);
                return strpos($n, 'pico') !== false ? 0 : (strpos($n, 'hollywood') !== false ? 1 : 2);
            };
            return [$rank($locations[$a]), $locations[$a]] <=> [$rank($locations[$b]), $locations[$b]];
        });

        $skip = array_flip(self::STOCK_SKIP_WORDS);
        $stockFor = function ($query) use ($words, $qty, $skip, $locations) {
            $terms = array_values(array_filter(array_unique($this->stockWords($query)), function ($w) use ($skip) {
                return !isset($skip[$w]);
            }));
            if (!$terms) {
                return null;
            }
            $lists = [];
            foreach ($terms as $t) {
                if (empty($words[$t])) {
                    return array_fill_keys(array_keys($locations), 0);
                }
                $lists[] = $words[$t];
            }
            usort($lists, function ($a, $b) {
                return count($a) <=> count($b);
            });
            $hits = array_shift($lists);
            if ($lists) {
                $keep = [];
                foreach ($hits as $i => $_) {
                    foreach ($lists as $l) {
                        if (!isset($l[$i])) {
                            continue 2;
                        }
                    }
                    $keep[$i] = true;
                }
                $hits = $keep;
            }
            $out = array_fill_keys(array_keys($locations), 0);
            foreach ($hits as $i => $_) {
                foreach ($qty[$i] as $loc => $n) {
                    if (isset($out[$loc])) {
                        $out[$loc] += $n;
                    }
                }
            }
            return $out;
        };
        return [$locations, $stockFor];
    }

    /** Lowercase ASCII words of a string. */
    protected function stockWords($s)
    {
        $s = (string) $s;
        if (preg_match('/[^\x00-\x7F]/', $s)) {
            $s = \Illuminate\Support\Str::ascii($s);
        }
        $s = strtolower($s);
        $s = str_replace(["'", '’'], '', $s);
        return preg_split('/[^a-z0-9]+/', $s, -1, PREG_SPLIT_NO_EMPTY);
    }

    public function boost(Request $request)
    {
        $this->guardStaff();
        $query = (string) $request->input('query', '');
        $resp = WebsiteBridge::admin('POST', '/search-log/prioritize-artist', ['query' => $query, 'priority' => 10]);
        return $this->bridgeBack($resp, '"' . $query . '" is boosted in auto-suggest.', 'Failed to prioritize.');
    }

    public function unboost(Request $request)
    {
        $this->guardStaff();
        $query = (string) $request->input('query', '');
        $resp = WebsiteBridge::admin('POST', '/search-log/deprioritize-artist', ['query' => $query]);
        return $this->bridgeBack($resp, 'Boost removed from "' . $query . '".', 'Failed to remove the boost.');
    }

    public function bstQueries(Request $request)
    {
        $this->guardStaff();
        $page = max(1, (int) $request->input('page', 1));
        $limit = 25;
        $resp = WebsiteBridge::admin('GET', '/admin/buy-sell-trade-searches?page=' . $page . '&limit=' . $limit);
        $rows = [];
        foreach (['results', 'items'] as $k) {
            if (isset($resp['data'][$k]) && is_array($resp['data'][$k])) {
                $rows = $resp['data'][$k];
                break;
            }
        }
        if (!$rows) {
            $rows = $this->listFrom($resp, ['data', 'results', 'items']);
        }
        $total = $resp['pagination']['total'] ?? ($resp['data']['pagination']['total'] ?? ($resp['total'] ?? ($resp['data']['total'] ?? count($rows))));

        // Top queries on this page, like the website's client-side tally.
        $counts = [];
        foreach ($rows as $r) {
            $q = mb_strtolower(trim((string) ($r['query'] ?? '')));
            if ($q === '') {
                continue;
            }
            $t = isset($r['createdAt']) ? strtotime($r['createdAt']) : 0;
            if (!isset($counts[$q])) {
                $counts[$q] = ['query' => $q, 'count' => 0, 'last' => 0];
            }
            $counts[$q]['count']++;
            $counts[$q]['last'] = max($counts[$q]['last'], (int) $t);
        }
        usort($counts, function ($a, $b) {
            return $b['count'] <=> $a['count'];
        });

        return view('website_admin.content.bst_search_queries', [
            'bridgeError' => empty($resp['success']) ? ($resp['message'] ?? 'Failed to load search queries.') : null,
            'rows' => $rows,
            'top' => array_slice($counts, 0, 15),
            'page' => $page,
            'total' => (int) $total,
            'totalPages' => max(1, (int) ceil(((int) $total) / $limit)),
            'loadedAt' => now()->setTimezone('America/Los_Angeles')->format('M j, Y g:i A'),
        ]);
    }

    public function sealedVelocity(Request $request)
    {
        $this->guardStaff();
        $f = [
            'format' => in_array($request->input('format'), ['both', 'vinyl', 'cd'], true) ? $request->input('format') : 'both',
            'from' => (string) $request->input('from', ''),
            'to' => (string) $request->input('to', ''),
            'genre' => (string) $request->input('genre', ''),
            'label' => (string) $request->input('label', ''),
            'artist' => trim((string) $request->input('artist', '')),
            'minSales' => max(1, (int) $request->input('minSales', 1)),
        ];
        $skip = max(0, (int) $request->input('skip', 0));
        $limit = 100;
        $params = array_filter($f, function ($v) {
            return $v !== '' && $v !== null;
        });
        $params['limit'] = $limit;
        $params['skip'] = $skip;
        $report = WebsiteBridge::admin('GET', '/admin/sealed-velocity/report?' . http_build_query($params), null, 60);
        $opts = WebsiteBridge::admin('GET', '/admin/sealed-velocity/filters', null, 30);
        $top = WebsiteBridge::admin('GET', '/search-log/top-queries?page=1&limit=25', null, 30);

        $rows = $this->pick($report, 'rows', []) ?: [];
        $sales = 0;
        $sumAvg = 0;
        foreach ($rows as $r) {
            $sales += (int) ($r['salesCount'] ?? 0);
            $sumAvg += (float) ($r['avgDaysToSell'] ?? 0);
        }
        return view('website_admin.content.sealed_velocity', [
            'bridgeError' => empty($report['success']) ? ($report['message'] ?? 'Could not load the report.') : null,
            'filters' => $f,
            'rows' => $rows,
            'total' => (int) $this->pick($report, 'total', 0),
            'skip' => $skip,
            'limit' => $limit,
            'genres' => $this->pick($opts, 'genres', []) ?: [],
            'labels' => $this->pick($opts, 'labels', []) ?: [],
            'summary' => [
                'titles' => count($rows),
                'avg' => count($rows) ? round($sumAvg / count($rows), 1) : null,
                'sales' => $sales,
            ],
            'topQueries' => array_slice($this->listFrom($top), 0, 25),
        ]);
    }
}
