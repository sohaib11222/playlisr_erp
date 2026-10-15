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
        return view('website_admin.content.most_searched', [
            'bridgeError' => empty($q['success']) ? ($q['message'] ?? 'Could not load search data.') : null,
            'queries' => $this->listFrom($q),
            'artists' => $this->listFrom($artists),
            'artistError' => empty($artists['success']) ? ($artists['message'] ?? 'Could not load artist data.') : null,
            'page' => (int) ($pg['page'] ?? $page),
            'limit' => (int) ($pg['limit'] ?? $limit),
            'total' => (int) ($pg['total'] ?? 0),
            'totalPages' => max(1, (int) ($pg['totalPages'] ?? 1)),
            'pageSizes' => self::PAGE_SIZES,
            'loadedAt' => now()->setTimezone('America/Los_Angeles')->format('M j, Y g:i A'),
        ]);
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
