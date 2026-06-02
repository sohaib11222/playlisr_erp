<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

// Listing commissions owed to staff who list items.
//
// There is no commission table — commission is derived live: for every product
// a commission agent (users.is_cmmsn_agnt=1) created since the start date,
// owed = users.cmmsn_percent% of that item's sell price (variations.sell_price_inc_tax).
//
// "Paid" is tracked here, not in the DB (Sarah doesn't run migrations): each
// payout snapshots the exact product ids it covered to
// storage/app/listing-commission-payouts.json. Owed = listings since the start
// date that aren't in any payout, so marking paid never double-counts and a
// payout can be undone.
class ListingCommissionController extends Controller
{
    const PAYOUTS_FILE = 'listing-commission-payouts.json';
    const DEFAULT_FROM = '2026-05-15';

    public function index(Request $request)
    {
        $from = $this->normalizeFrom($request->input('from'));
        $businessId = $request->session()->get('user.business_id');

        $paid = $this->loadPayouts();
        $paidProductIds = $this->paidProductIds($paid);

        $listings = $this->ownedListings($businessId, $from, $paidProductIds);

        // Group the unpaid listings by lister.
        $people = [];
        foreach ($listings as $row) {
            $uid = $row->user_id;
            if (!isset($people[$uid])) {
                $people[$uid] = (object) [
                    'user_id'      => $uid,
                    'name'         => $this->personName($row),
                    'cmmsn_percent' => (float) $row->cmmsn_percent,
                    'count'        => 0,
                    'sell_total'   => 0.0,
                    'owed'         => 0.0,
                ];
            }
            $people[$uid]->count++;
            $people[$uid]->sell_total += (float) $row->sell_price;
            $people[$uid]->owed += (float) $row->sell_price * (float) $row->cmmsn_percent / 100;
        }
        $people = collect($people)->sortByDesc('owed')->values();

        // Paid history (most recent first).
        $history = collect($paid)->sortByDesc('marked_at')->values();

        return view('admin.listing_commissions', [
            'from'        => $from,
            'people'      => $people,
            'history'     => $history,
            'total_owed'  => $people->sum('owed'),
            'total_paid'  => $history->sum('amount'),
        ]);
    }

    public function markPaid(Request $request)
    {
        $from = $this->normalizeFrom($request->input('from'));
        $userId = (int) $request->input('user_id');
        $businessId = $request->session()->get('user.business_id');

        if ($userId <= 0) {
            return redirect($this->backUrl($from))
                ->with('status', ['success' => 0, 'msg' => 'Missing person.']);
        }

        $paid = $this->loadPayouts();
        $paidProductIds = $this->paidProductIds($paid);
        $listings = $this->ownedListings($businessId, $from, $paidProductIds)
            ->where('user_id', $userId)
            ->values();

        if ($listings->isEmpty()) {
            return redirect($this->backUrl($from))
                ->with('status', ['success' => 0, 'msg' => 'Nothing outstanding for that person.']);
        }

        $amount = 0.0;
        $productIds = [];
        foreach ($listings as $row) {
            $amount += (float) $row->sell_price * (float) $row->cmmsn_percent / 100;
            $productIds[] = (int) $row->id;
        }

        $paid[] = [
            'id'            => bin2hex(random_bytes(8)),
            'user_id'       => $userId,
            'name'          => $this->personName($listings->first()),
            'cmmsn_percent' => (float) $listings->first()->cmmsn_percent,
            'count'         => count($productIds),
            'amount'        => round($amount, 2),
            'product_ids'   => $productIds,
            'from_date'     => $from,
            'to_date'       => now()->toDateString(),
            'marked_by'     => $request->session()->get('user.id'),
            'marked_at'     => now()->toDateTimeString(),
        ];

        $this->savePayouts($paid);

        return redirect($this->backUrl($from))->with('status', [
            'success' => 1,
            'msg'     => 'Marked ' . count($productIds) . ' listing(s) paid — $' . number_format($amount, 2) . '.',
        ]);
    }

    public function undoPayout(Request $request)
    {
        $from = $this->normalizeFrom($request->input('from'));
        $id = preg_replace('/[^a-f0-9]/', '', (string) $request->input('id'));

        $paid = $this->loadPayouts();
        $before = count($paid);
        $paid = array_values(array_filter($paid, function ($p) use ($id) {
            return ($p['id'] ?? '') !== $id;
        }));

        if (count($paid) === $before) {
            return redirect($this->backUrl($from))
                ->with('status', ['success' => 0, 'msg' => 'Payout not found.']);
        }

        $this->savePayouts($paid);

        return redirect($this->backUrl($from))
            ->with('status', ['success' => 1, 'msg' => 'Payout undone — those listings are owed again.']);
    }

    // Unpaid product-level rows: one per product a commission agent listed since
    // $from, with that product's summed sell price, excluding already-paid ids.
    private function ownedListings($businessId, $from, array $paidProductIds)
    {
        $rows = DB::table('products as p')
            ->join('users as u', 'u.id', '=', 'p.created_by')
            ->leftJoin('variations as v', 'v.product_id', '=', 'p.id')
            ->where('p.business_id', $businessId)
            ->where('u.is_cmmsn_agnt', 1)
            ->where('u.cmmsn_percent', '>', 0)
            ->where('p.created_at', '>=', $from . ' 00:00:00')
            ->whereNull('p.deleted_at')
            ->groupBy('p.id', 'p.name', 'p.created_by', 'p.created_at', 'u.cmmsn_percent', 'u.first_name', 'u.last_name', 'u.surname')
            ->select(
                'p.id',
                'p.name',
                'p.created_by as user_id',
                'p.created_at',
                'u.cmmsn_percent',
                'u.first_name',
                'u.last_name',
                'u.surname',
                DB::raw('COALESCE(SUM(v.sell_price_inc_tax), 0) as sell_price')
            )
            ->get();

        if (!empty($paidProductIds)) {
            $paidSet = array_flip($paidProductIds);
            $rows = $rows->reject(function ($r) use ($paidSet) {
                return isset($paidSet[$r->id]);
            })->values();
        }

        return $rows;
    }

    private function personName($row)
    {
        $name = trim(($row->first_name ?? '') . ' ' . ($row->last_name ?? ''));
        if ($name === '') {
            $name = trim($row->surname ?? '') ?: ('User #' . $row->user_id);
        }
        return $name;
    }

    private function paidProductIds(array $paid)
    {
        $ids = [];
        foreach ($paid as $p) {
            foreach (($p['product_ids'] ?? []) as $pid) {
                $ids[] = (int) $pid;
            }
        }
        return $ids;
    }

    private function loadPayouts()
    {
        if (!Storage::disk('local')->exists(self::PAYOUTS_FILE)) {
            return [];
        }
        $data = json_decode(Storage::disk('local')->get(self::PAYOUTS_FILE), true);
        return is_array($data) ? $data : [];
    }

    private function savePayouts(array $paid)
    {
        Storage::disk('local')->put(self::PAYOUTS_FILE, json_encode(array_values($paid), JSON_PRETTY_PRINT));
    }

    private function normalizeFrom($input)
    {
        $input = is_string($input) ? trim($input) : '';
        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $input) ? $input : self::DEFAULT_FROM;
    }

    private function backUrl($from)
    {
        return '/admin/listing-commissions?from=' . urlencode($from);
    }
}
