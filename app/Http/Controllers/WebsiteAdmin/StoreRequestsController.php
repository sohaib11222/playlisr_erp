<?php

namespace App\Http\Controllers\WebsiteAdmin;

use Illuminate\Http\Request;

/**
 * "Where should we open next?" poll + write-in store requests from
 * nivessa.com/locations, moved from the website's /admin/store-requests
 * (Sarah, 2026-10-15). Poll votes and write-ins are both StoreRequest
 * rows, so one DELETE removes either.
 */
class StoreRequestsController extends DirectoryBaseController
{
    const LIMIT = 25;

    public function index(Request $request)
    {
        $this->guard();
        $page = max(1, (int) $request->input('page', 1));

        $list = self::call('GET', '/admin/store-requests?' . http_build_query(['page' => $page, 'limit' => self::LIMIT]));
        $tally = self::call('GET', '/store-requests/tally');
        $votes = self::call('GET', '/admin/store-requests/poll/votes');

        $poll = (array) ($tally['data'] ?? []);
        $cities = array_values((array) ($poll['cities'] ?? []));
        $rows = self::rows($list);

        return view('website_admin.directory.requests', [
            'page'       => $page,
            'limit'      => self::LIMIT,
            'rows'       => $rows,
            'total'      => (int) ($list['pagination']['total'] ?? count($rows)),
            'listError'  => empty($list['success']) ? ($list['message'] ?? 'Could not load store requests.') : null,
            'cities'     => $cities,
            'pollTotal'  => (int) ($poll['total'] ?? 0),
            'pollMax'    => array_reduce($cities, fn($m, $c) => max($m, (int) ($c['votes'] ?? 0)), 0),
            'pollError'  => empty($tally['success']) ? ($tally['message'] ?? 'Could not load the poll.') : null,
            'votes'      => self::rows($votes),
            'votesError' => empty($votes['success']) ? ($votes['message'] ?? 'Could not load poll votes.') : null,
            'loadedAt'   => now()->setTimezone('America/Los_Angeles')->format('M j, Y g:ia'),
        ]);
    }

    public function handled(Request $request, string $id)
    {
        $this->guard();
        $handled = $request->boolean('handled');
        return $this->done(
            self::call('PATCH', '/admin/store-requests/' . rawurlencode($id) . '/handled', ['handled' => $handled]),
            $handled ? 'Marked handled.' : 'Marked new.'
        );
    }

    /** Deletes one write-in request or one poll vote. */
    public function destroy(Request $request, string $id)
    {
        $this->guard();
        return $this->done(
            self::call('DELETE', '/admin/store-requests/' . rawurlencode($id)),
            $request->input('kind') === 'vote' ? 'Vote deleted.' : 'Request deleted.'
        );
    }

    /** Per-city "remove a vote": deletes that city's most recent vote. */
    public function removeCityVote(string $slug)
    {
        $this->guard();
        $votes = self::call('GET', '/admin/store-requests/poll/votes');
        if (empty($votes['success'])) {
            return back()->with('error', $votes['message'] ?? 'Could not load poll votes.');
        }
        // The website lists votes newest first.
        foreach (self::rows($votes) as $v) {
            if ((string) ($v['city'] ?? '') === $slug && !empty($v['_id'])) {
                $label = $v['label'] ?? $slug;
                return $this->done(self::call('DELETE', '/admin/store-requests/' . rawurlencode($v['_id'])), "Removed the most recent vote for {$label}.");
            }
        }
        return back()->with('error', 'That city has no votes to remove.');
    }
}
