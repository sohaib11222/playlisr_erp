<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

/**
 * Clover charges with no ERP sale during the shift being closed
 * (Sarah 2026-10-08: "how on earth can we get better at this").
 *
 * The close-register modal calls this AFTER it opens (never blocking the
 * modal) and, if anything comes back, asks the cashier to ring those items
 * in before closing. Any error here returns ok=false and the modal behaves
 * exactly as before, so the POS close flow can't break because of it.
 * Same matcher as the daily register check (/pos/recent-feed).
 */
class ShiftCloverCheckController extends Controller
{
    public function check(Request $request)
    {
        try {
            if (!auth()->user()->can('sell.create') && !auth()->user()->can('sell.view')) {
                return response()->json(['ok' => false]);
            }
            $locationId = (int) $request->query('location_id');
            $start = (string) $request->query('start');
            if (!$locationId || !$start) return response()->json(['ok' => false]);
            $tz = 'America/Los_Angeles';
            $startTs = strtotime($start); // same app timezone as paid_at
            $date = \Carbon\Carbon::now($tz)->format('Y-m-d');

            $req = Request::create('/pos/recent-feed', 'GET', ['date' => $date, 'location_id' => $locationId]);
            $req->setLaravelSession($request->session());
            $view = app(\App\Http\Controllers\SellPosController::class)->recentSalesFeed($req);
            $d = $view->getData();

            // Charges that are probably just a sale needing a match on the
            // feed aren't "not rung"; leave those out.
            $paired = [];
            foreach (($d['erp_only_pair_candidates'] ?? []) as $cands) {
                foreach ($cands as $c) { if (!empty($c['cp_db_id'])) $paired[(int) $c['cp_db_id']] = true; }
            }
            $out = [];
            foreach (($d['unclaimed_clover_payments'] ?? collect()) as $cp) {
                $loc = $cp->location_id !== null ? (int) $cp->location_id : null;
                if ($loc !== null && $loc !== 0 && $loc !== $locationId) continue;
                $ts = strtotime((string) $cp->paid_at);
                if ($ts < $startTs) continue;
                if (isset($paired[(int) $cp->id])) continue;
                if ((float) $cp->amount <= 0) continue; // refunds handled by the daily check
                $out[] = [
                    'amount' => round((float) $cp->amount, 2),
                    'time' => \Carbon\Carbon::createFromTimestamp($ts, $tz)->format('g:ia'),
                ];
            }
            return response()->json(['ok' => true, 'charges' => $out]);
        } catch (\Throwable $e) {
            \Log::warning('shift clover check failed: ' . $e->getMessage());
            return response()->json(['ok' => false]);
        }
    }
}
