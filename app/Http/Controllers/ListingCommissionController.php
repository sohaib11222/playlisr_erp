<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

// Listing commissions owed to staff who list items.
//
// A person earns commission when an item THEY listed actually SELLS. So this
// looks at sold lines (transaction_sell_lines on final sells) whose product was
// listed on/after the start date by a commission agent (users.is_cmmsn_agnt=1),
// and owes that lister cmmsn_percent% of the realized sale amount
// (unit_price_inc_tax × quantity, net of returns).
//
// "Paid" is tracked here, not in the DB (Sarah doesn't run migrations): each
// payout snapshots the exact sell-line ids it covered to
// storage/app/listing-commission-payouts.json. Owed = qualifying sold lines not
// in any payout, so marking paid never double-counts and a payout can be undone.
class ListingCommissionController extends Controller
{
    const PAYOUTS_FILE = 'listing-commission-payouts.json';
    const DEFAULT_FROM = '2026-05-15';

    public function index(Request $request)
    {
        $from = $this->normalizeFrom($request->input('from'));
        $businessId = $request->session()->get('user.business_id');

        $paid = $this->loadPayouts();
        $paidLineIds = $this->paidLineIds($paid);

        $lines = $this->ownedSoldLines($businessId, $from, $paidLineIds);

        // Group the unpaid sold lines by lister.
        $people = [];
        foreach ($lines as $row) {
            $uid = $row->user_id;
            if (!isset($people[$uid])) {
                $people[$uid] = (object) [
                    'user_id'       => $uid,
                    'name'          => $this->personName($row),
                    'cmmsn_percent' => (float) $row->cmmsn_percent,
                    'count'         => 0,
                    'sale_total'    => 0.0,
                    'owed'          => 0.0,
                ];
            }
            $people[$uid]->count++;
            $people[$uid]->sale_total += (float) $row->sale_amount;
            $people[$uid]->owed += (float) $row->sale_amount * (float) $row->cmmsn_percent / 100;
        }
        $people = collect($people)->sortByDesc('owed')->values();

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
        $paidLineIds = $this->paidLineIds($paid);
        $lines = $this->ownedSoldLines($businessId, $from, $paidLineIds)
            ->where('user_id', $userId)
            ->values();

        if ($lines->isEmpty()) {
            return redirect($this->backUrl($from))
                ->with('status', ['success' => 0, 'msg' => 'Nothing outstanding for that person.']);
        }

        $amount = 0.0;
        $lineIds = [];
        foreach ($lines as $row) {
            $amount += (float) $row->sale_amount * (float) $row->cmmsn_percent / 100;
            $lineIds[] = (int) $row->line_id;
        }

        $paid[] = [
            'id'            => bin2hex(random_bytes(8)),
            'user_id'       => $userId,
            'name'          => $this->personName($lines->first()),
            'cmmsn_percent' => (float) $lines->first()->cmmsn_percent,
            'count'         => count($lineIds),
            'amount'        => round($amount, 2),
            'line_ids'      => $lineIds,
            'from_date'     => $from,
            'to_date'       => now()->toDateString(),
            'marked_by'     => $request->session()->get('user.id'),
            'marked_at'     => now()->toDateTimeString(),
        ];

        $this->savePayouts($paid);

        return redirect($this->backUrl($from))->with('status', [
            'success' => 1,
            'msg'     => 'Marked ' . count($lineIds) . ' sold item(s) paid — $' . number_format($amount, 2) . '.',
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
            ->with('status', ['success' => 1, 'msg' => 'Payout undone — those sales are owed again.']);
    }

    // Unpaid sold lines: one row per item sold (final sell) whose product was
    // listed since $from by a commission agent, with the realized sale amount
    // (net of returns), excluding lines already covered by a payout.
    private function ownedSoldLines($businessId, $from, array $paidLineIds)
    {
        $rows = DB::table('transaction_sell_lines as tsl')
            ->join('transactions as t', 't.id', '=', 'tsl.transaction_id')
            ->join('products as p', 'p.id', '=', 'tsl.product_id')
            ->join('users as u', 'u.id', '=', 'p.created_by')
            ->where('t.business_id', $businessId)
            ->where('t.type', 'sell')
            ->where('t.status', 'final')
            ->where('u.is_cmmsn_agnt', 1)
            ->where('u.cmmsn_percent', '>', 0)
            ->where('p.created_at', '>=', $from . ' 00:00:00')
            ->select(
                'tsl.id as line_id',
                'p.created_by as user_id',
                'u.cmmsn_percent',
                'u.first_name',
                'u.last_name',
                'u.surname',
                DB::raw('(tsl.unit_price_inc_tax * (tsl.quantity - COALESCE(tsl.quantity_returned, 0))) as sale_amount')
            )
            ->get();

        if (!empty($paidLineIds)) {
            $paidSet = array_flip($paidLineIds);
            $rows = $rows->reject(function ($r) use ($paidSet) {
                return isset($paidSet[$r->line_id]);
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

    private function paidLineIds(array $paid)
    {
        $ids = [];
        foreach ($paid as $p) {
            foreach (($p['line_ids'] ?? []) as $lid) {
                $ids[] = (int) $lid;
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
