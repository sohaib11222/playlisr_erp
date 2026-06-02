<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

// Listing (barcoding) commissions owed to staff.
//
// Mirrors the EXACT commission employees already see on the Employee
// Leaderboard (ReportController::barcodingCommissionByUser): a person earns
// 2% of the sale value (quantity × unit_price_inc_tax) of every item THEY
// listed/barcoded (products.created_by) that has since SOLD on a final sell,
// as long as the product was listed on/after the 2026-05-15 rollout and isn't
// in an excluded category (sealed/new stock, gear, apparel, etc.). Keeping the
// formula identical here means this payables view agrees with what each
// employee was shown they earned.
//
// "Paid" is tracked here, not in the DB (Sarah doesn't run migrations): each
// payout snapshots the exact sell-line ids it covered to
// storage/app/listing-commission-payouts.json. Owed = qualifying sold lines not
// in any payout, so marking paid never double-counts and a payout can be undone.
class ListingCommissionController extends Controller
{
    const PAYOUTS_FILE = 'listing-commission-payouts.json';
    const DEFAULT_FROM = '2026-05-15';
    const RATE = 0.02; // flat 2%, matches barcodingCommissionByUser

    // Category exclusions copied verbatim from barcodingCommissionByUser so the
    // owed numbers match the leaderboard exactly.
    private $excludedCategoryPatterns = ['%sealed%', '%new vinyl%', '%new cd%', '%new cassette%'];
    private $excludedCategoryNames = [
        'audio gear', 'record players', 'record player',
        'trading cards', 'apparel', 'clothing', 'video games',
        'gift items', 'toys', 'accessories & novelties',
        'acessories & novelties', 'pictures & posters',
    ];

    // Owners / back-office + departed non-floor accounts that don't get paid
    // listing commission (mirrors the Employee Leaderboard roster). Owner
    // accounts are also the creator-of-record for bulk/imported listings, so
    // leaving them in inflates counts (e.g. Jon showed 48k listed items).
    // 'henry' left the company (Sarah 2026-06-02). Excluded by first name.
    private $excludedOwnerFirstNames = ['jon', 'jonathan', 'sarah', 'sohaib', 'fatteen', 'henry'];

    // Fatteen's ERP account is named "Nerdy Solutions", so the first-name list
    // above misses it. Also drop any account whose full name contains these.
    private $excludedNameContains = ['nerdy'];

    public function index(Request $request)
    {
        $from = $this->normalizeFrom($request->input('from'));
        $businessId = $request->session()->get('user.business_id');

        $paid = $this->loadPayouts();
        $paidLineIds = $this->paidLineIds($paid);

        $lines = $this->ownedSoldLines($businessId, $from, $paidLineIds);
        $listedCounts = $this->listedCountByUser($businessId, $from);

        // Group the unpaid sold lines by lister.
        $people = [];
        foreach ($lines as $row) {
            $uid = $row->user_id;
            if (!isset($people[$uid])) {
                $people[$uid] = (object) [
                    'user_id'      => $uid,
                    'name'         => $this->personName($row),
                    'listed_count' => (int) ($listedCounts[$uid] ?? 0),
                    'count'        => 0,
                    'sale_total'   => 0.0,
                    'owed'         => 0.0,
                ];
            }
            $people[$uid]->count++;
            $people[$uid]->sale_total += (float) $row->sale_amount;
            $people[$uid]->owed += (float) $row->sale_amount * self::RATE;
        }
        $people = collect($people)->sortByDesc('owed')->values();

        $history = collect($paid)->sortByDesc('marked_at')->values();

        return view('admin.listing_commissions', [
            'from'        => $from,
            'rate_pct'    => self::RATE * 100,
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
            $amount += (float) $row->sale_amount * self::RATE;
            $lineIds[] = (int) $row->line_id;
        }

        $paid[] = [
            'id'         => bin2hex(random_bytes(8)),
            'user_id'    => $userId,
            'name'       => $this->personName($lines->first()),
            'count'      => count($lineIds),
            'amount'     => round($amount, 2),
            'line_ids'   => $lineIds,
            'from_date'  => $from,
            'to_date'    => now()->toDateString(),
            'marked_by'  => $request->session()->get('user.id'),
            'marked_at'  => now()->toDateTimeString(),
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
    // listed on/after $from by the lister (products.created_by), excluding
    // sealed/new stock + non-vinyl categories, with the realized sale value and
    // excluding lines already covered by a payout. Filters mirror
    // ReportController::barcodingCommissionByUser exactly.
    private function ownedSoldLines($businessId, $from, array $paidLineIds)
    {
        $start = $from . ' 00:00:00';
        $end = now()->toDateTimeString();

        $q = DB::table('transaction_sell_lines as tsl')
            ->join('transactions as t', 'tsl.transaction_id', '=', 't.id')
            ->join('products as p', 'tsl.product_id', '=', 'p.id')
            ->leftJoin('categories as c', 'p.category_id', '=', 'c.id')
            ->leftJoin('categories as sc', 'p.sub_category_id', '=', 'sc.id')
            ->join('users as u', 'u.id', '=', 'p.created_by')
            ->where('t.business_id', $businessId)
            ->where('t.type', 'sell')
            ->where('t.status', 'final')
            ->whereNull('t.import_source')
            ->whereBetween('t.transaction_date', [$start, $end])
            ->whereNotNull('p.created_by')
            ->where('p.created_at', '>=', $start)
            ->where(function ($qq) {
                foreach ($this->excludedCategoryPatterns as $pat) {
                    $qq->where(DB::raw('LOWER(c.name)'), 'NOT LIKE', $pat)
                       ->where(DB::raw('LOWER(COALESCE(sc.name, \'\'))'), 'NOT LIKE', $pat);
                }
                $qq->whereNotIn(DB::raw('LOWER(TRIM(c.name))'), $this->excludedCategoryNames)
                   ->whereNotIn(DB::raw('LOWER(TRIM(COALESCE(sc.name, \'\')))'), $this->excludedCategoryNames);
            });
        $this->excludeOwners($q);

        $rows = $q->select(
                'tsl.id as line_id',
                'p.created_by as user_id',
                'u.first_name',
                'u.last_name',
                'u.surname',
                DB::raw('(tsl.quantity * tsl.unit_price_inc_tax) as sale_amount')
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

    // Items each person LISTED on/after $from (regardless of whether they've
    // sold), keyed by user_id. Same product + category filters as the
    // commission query so "listed" and "sold" describe the same eligible
    // universe; just no transaction join.
    private function listedCountByUser($businessId, $from)
    {
        $start = $from . ' 00:00:00';

        $q = DB::table('products as p')
            ->join('users as u', 'u.id', '=', 'p.created_by')
            ->leftJoin('categories as c', 'p.category_id', '=', 'c.id')
            ->leftJoin('categories as sc', 'p.sub_category_id', '=', 'sc.id')
            ->where('p.business_id', $businessId)
            ->whereNotNull('p.created_by')
            ->where('p.created_at', '>=', $start)
            ->where(function ($qq) {
                foreach ($this->excludedCategoryPatterns as $pat) {
                    $qq->where(DB::raw('LOWER(c.name)'), 'NOT LIKE', $pat)
                       ->where(DB::raw('LOWER(COALESCE(sc.name, \'\'))'), 'NOT LIKE', $pat);
                }
                $qq->whereNotIn(DB::raw('LOWER(TRIM(c.name))'), $this->excludedCategoryNames)
                   ->whereNotIn(DB::raw('LOWER(TRIM(COALESCE(sc.name, \'\')))'), $this->excludedCategoryNames);
            });
        $this->excludeOwners($q);

        return $q->selectRaw('p.created_by as user_id, COUNT(*) as listed_count')
            ->groupBy('p.created_by')
            ->pluck('listed_count', 'user_id')
            ->toArray();
    }

    // Drop owner/back-office accounts from a query that has joined `users as u`.
    private function excludeOwners($q)
    {
        $q->whereNotIn(DB::raw('LOWER(u.first_name)'), $this->excludedOwnerFirstNames);
        foreach ($this->excludedNameContains as $needle) {
            $q->whereRaw(
                "LOWER(TRIM(CONCAT(COALESCE(u.first_name,''),' ',COALESCE(u.last_name,''),' ',COALESCE(u.surname,'')))) NOT LIKE ?",
                ['%' . $needle . '%']
            );
        }
        return $q;
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
