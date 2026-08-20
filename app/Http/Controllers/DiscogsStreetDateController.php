<?php

namespace App\Http\Controllers;

use App\Services\DiscogsStreetDateBackfillService;
use Illuminate\Http\Request;

/**
 * Admin UI for backfilling street/release dates from Discogs onto products
 * that already carry a discogs_release_id but have no street date set.
 * Never overwrites a date staff typed in by hand — only fills blanks.
 */
class DiscogsStreetDateController extends Controller
{
    public function index(Request $request)
    {
        $business_id = $request->session()->get('user.business_id');
        $svc = new DiscogsStreetDateBackfillService();
        return view('admin.discogs_street_dates', [
            'remaining' => $svc->countEligible($business_id),
        ]);
    }

    public function run(Request $request)
    {
        @set_time_limit(0);
        @ini_set('memory_limit', '512M');

        $business_id = $request->session()->get('user.business_id');
        $limit = (int) $request->input('limit', 150);
        if ($limit < 1 || $limit > 300) {
            $limit = 150;
        }
        $commit = filter_var($request->input('commit', false), FILTER_VALIDATE_BOOLEAN);

        $svc = new DiscogsStreetDateBackfillService();
        $result = $svc->run($business_id, $limit, $commit);
        if (empty($result['ok'])) {
            return response()->json(['success' => false, 'msg' => $result['error'] ?? 'Failed.'], 422);
        }

        $result['remaining'] = $svc->countEligible($business_id);
        $result['success'] = true;
        return response()->json($result);
    }
}
