<?php

namespace App\Http\Controllers\WebsiteAdmin;

use Illuminate\Http\Request;

/**
 * What people search for in the nivessa.com store directories, moved from
 * the website's /admin/directory-searches (Sarah, 2026-10-15).
 */
class DirectorySearchesController extends DirectoryBaseController
{
    const RECENT_LIMIT = 25;

    public function index(Request $request)
    {
        $this->guard();
        $type = in_array($request->input('type'), ['all', 'keyword', 'location', 'with-location'], true) ? $request->input('type') : 'all';
        $days = in_array((string) $request->input('days'), ['7', '30', '90', '365'], true) ? (string) $request->input('days') : '30';
        $page = max(1, (int) $request->input('page', 1));

        $typeParams = [];
        if ($type === 'with-location') {
            $typeParams['withLocation'] = 'true';
        } elseif ($type !== 'all') {
            $typeParams['type'] = $type;
        }

        $top = self::call('GET', '/directory-search-log/top-queries?' . http_build_query(['limit' => 50, 'days' => $days] + $typeParams));
        $recent = self::call('GET', '/directory-search-log/recent?' . http_build_query(['page' => $page, 'limit' => self::RECENT_LIMIT] + $typeParams));
        $stats = self::call('GET', '/directory-search-log/stats');

        $statsData = !empty($stats['success']) && is_array($stats['data'] ?? null) ? $stats['data'] : null;
        $byType = [];
        foreach ((array) ($statsData['byType'] ?? []) as $t) {
            $byType[(string) ($t['_id'] ?? '')] = (int) ($t['count'] ?? 0);
        }
        $recentTotal = (int) ($recent['pagination']['total'] ?? ($recent['total'] ?? 0));

        return view('website_admin.directory.searches', [
            'type'        => $type,
            'days'        => $days,
            'page'        => $page,
            'top'         => self::rows($top),
            'topError'    => empty($top['success']) ? ($top['message'] ?? 'Could not load top searches.') : null,
            'recent'      => self::rows($recent),
            'recentError' => empty($recent['success']) ? ($recent['message'] ?? 'Could not load recent searches.') : null,
            'recentPages' => (int) ceil($recentTotal / self::RECENT_LIMIT),
            'stats'       => $statsData,
            'byType'      => $byType,
            'loadedAt'    => now()->setTimezone('America/Los_Angeles')->format('M j, Y g:ia'),
        ]);
    }
}
