<?php

namespace App\Http\Controllers;

use App\Contact;
use App\Services\NivessaStockNotifier;
use App\Services\WhatnotSeller as WS;
use App\Utils\BusinessUtil;
use App\Utils\ProductUtil;
use App\Utils\TransactionUtil;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

// Golden's Whatnot page (no login, the secret link is the auth) and the
// owners' Whatnot seller dashboard. See App\Services\WhatnotSeller.
class WhatnotSellerController extends Controller
{
    protected $transactionUtil;
    protected $productUtil;
    protected $businessUtil;

    public function __construct(TransactionUtil $transactionUtil, ProductUtil $productUtil, BusinessUtil $businessUtil)
    {
        $this->transactionUtil = $transactionUtil;
        $this->productUtil = $productUtil;
        $this->businessUtil = $businessUtil;
    }

    // ---------------------------------------------------------------- public

    private function noStore($response)
    {
        return $response->header('Cache-Control', 'no-store, private')->header('X-Robots-Tag', 'noindex');
    }

    public function page(Request $request, $token)
    {
        if (!WS::tokenValid($token)) {
            abort(404);
        }
        $s = WS::settings();
        $businessId = (int) $s['business_id'];

        $periodStart = Carbon::parse($s['paid_thru'])->addDay()->format('Y-m-d');
        $lines = WS::soldLines($businessId, $periodStart, WS::now()->format('Y-m-d'));
        $sales = array_sum(array_map(function ($l) { return $l['sold'] * $l['qty']; }, $lines));
        $items = array_sum(array_map(function ($l) { return $l['qty']; }, $lines));
        $shows = WS::hoursBetween($periodStart, WS::now()->format('Y-m-d'));

        $stores = DB::table('business_locations')->where('business_id', $businessId)->whereNull('deleted_at')->orderBy('name')->pluck('name', 'id');

        return $this->noStore(response()->view('whatnot_seller.public', [
            'token'        => $token,
            's'            => $s,
            'lines'        => $lines,
            'sales'        => round($sales, 2),
            'pay'          => WS::owed($businessId, $periodStart, WS::now()->format('Y-m-d')),
            'shows'        => $shows,
            'period_start' => Carbon::parse($periodStart)->format('m/d/y'),
            'stores'       => $stores,
            'flash'        => session('wn_flash'),
        ]));
    }

    public function search(Request $request, $token)
    {
        if (!WS::tokenValid($token)) {
            abort(404);
        }
        $s = WS::settings();
        $items = [];
        try {
            $items = WS::search((int) $s['business_id'], (string) $request->input('q', ''));
        } catch (\Throwable $e) {
            Log::warning('whatnot-seller search failed: ' . $e->getMessage());
        }
        return $this->noStore(response()->json(['items' => $items]));
    }

    public function sell(Request $request, $token)
    {
        if (!WS::tokenValid($token)) {
            abort(404);
        }
        $s = WS::settings();
        $businessId = (int) $s['business_id'];
        $back = redirect('/whatnot-seller/' . $token);

        $items = json_decode((string) $request->input('items', '[]'), true);
        if (!is_array($items) || empty($items) || count($items) > 100) {
            return $back->with('wn_flash', ['ok' => false, 'msg' => 'Nothing to save. Add the items you sold first.']);
        }

        // A double tap on Save must not ring the same items twice.
        $key = 'wn_sell_' . md5((string) $request->input('submit_id', ''));
        if ($request->input('submit_id') && !Cache::add($key, 1, 600)) {
            return $back->with('wn_flash', ['ok' => true, 'msg' => 'Already saved.']);
        }

        $locations = DB::table('business_locations')->where('business_id', $businessId)->pluck('id')->map(function ($v) { return (int) $v; })->all();
        $defaultLoc = (int) $request->input('store_id');
        if (!in_array($defaultLoc, $locations, true)) {
            $defaultLoc = $locations[0] ?? 0;
        }

        // Group lines by store: stock comes off the store the copy is in.
        $byLoc = [];
        $skipped = [];
        foreach ($items as $it) {
            $price = round((float) ($it['price'] ?? 0), 2);
            if ($price <= 0 || $price > 100000) {
                $skipped[] = ($it['title'] ?? 'an item') . ' (no price)';
                continue;
            }
            $note = mb_substr(trim((string) ($it['note'] ?? '')), 0, 250);
            if (!empty($it['variation_id'])) {
                $v = DB::table('variations as v')
                    ->join('products as p', 'p.id', '=', 'v.product_id')
                    ->join('variation_location_details as vld', 'vld.variation_id', '=', 'v.id')
                    ->where('p.business_id', $businessId)
                    ->where('v.id', (int) $it['variation_id'])
                    ->where('vld.location_id', (int) ($it['location_id'] ?? 0))
                    ->first(['v.id', 'v.product_id', 'p.type', 'p.enable_stock', 'vld.qty_available', 'vld.location_id', 'v.sell_price_inc_tax']);
                if (!$v || (float) $v->qty_available <= 0) {
                    $skipped[] = ($it['title'] ?? 'an item') . ' (already marked sold)';
                    continue;
                }
                $sticker = (float) $v->sell_price_inc_tax;
                $byLoc[(int) $v->location_id][] = [
                    'product_id' => $v->product_id,
                    'variation_id' => $v->id,
                    'product_type' => $v->type ?: 'single',
                    'enable_stock' => (int) $v->enable_stock,
                    'quantity' => 1,
                    'unit_price' => $sticker > 0 ? $sticker : $price,
                    'unit_price_inc_tax' => $price,
                    // Sticker stays as the before-discount price so the
                    // markdown shows on the sale like a POS line discount.
                    'line_discount_type' => $sticker > $price ? 'fixed' : null,
                    'line_discount_amount' => $sticker > $price ? round($sticker - $price, 2) : 0,
                    'item_tax' => 0,
                    'tax_id' => null,
                    'base_unit_multiplier' => 1,
                    'sell_line_note' => $note,
                ];
            } else {
                $title = mb_substr(trim((string) ($it['title'] ?? '')), 0, 190);
                if ($title === '') {
                    $skipped[] = 'an item with no title';
                    continue;
                }
                $loc = in_array((int) ($it['location_id'] ?? 0), $locations, true) ? (int) $it['location_id'] : $defaultLoc;
                $byLoc[$loc][] = [
                    'product_id' => 'manual',
                    'product_name' => $title,
                    'variation_id' => null,
                    'product_type' => 'single',
                    'enable_stock' => 0,
                    'quantity' => 1,
                    'unit_price' => $price,
                    'unit_price_inc_tax' => $price,
                    'item_tax' => 0,
                    'tax_id' => null,
                    'base_unit_multiplier' => 1,
                    'sell_line_note' => trim('Not found in ERP. ' . $note),
                ];
            }
        }

        if (empty($byLoc)) {
            return $back->with('wn_flash', ['ok' => false, 'msg' => 'Couldn\'t save: ' . implode(', ', $skipped)]);
        }

        [$saved, $savedTotal, $errs] = $this->ring($businessId, $byLoc, WS::NOTE_TAG . ': entered by ' . $s['seller_name'] . ' on his Whatnot page');
        $skipped = array_merge($skipped, $errs);

        $msg = "Saved {$saved} item" . ($saved === 1 ? '' : 's') . ' ($' . number_format($savedTotal, 2) . ').';
        if ($skipped) {
            $msg .= ' Couldn\'t save: ' . implode(', ', $skipped) . '.';
        }
        return $back->with('wn_flash', ['ok' => empty($skipped), 'msg' => $msg]);
    }

    /**
     * Ring Whatnot sales into the ERP: one final sale per store, paid as
     * "other" (Whatnot pays us off-register), stock taken off and pushed to
     * the website. $byLoc = [location_id => [product rows]]. Returns
     * [items saved, $ total, error messages].
     */
    private function ring(int $businessId, array $byLoc, string $staffNote, ?string $date = null): array
    {
        $s = WS::settings();
        $walkIn = Contact::where('business_id', $businessId)->where('is_default', 1)->first()
            ?: Contact::where('business_id', $businessId)->where('type', 'customer')->first();
        $business = DB::table('business')->where('id', $businessId)->first(['id', 'owner_id', 'accounting_method']);
        $userId = (int) ($s['user_id'] ?: $business->owner_id);
        $saved = 0;
        $savedTotal = 0.0;
        $errs = [];
        $productIds = [];

        foreach ($byLoc as $locationId => $products) {
            DB::beginTransaction();
            try {
                $invoiceTotal = $this->productUtil->calculateInvoiceTotal($products, null, null, false);
                $finalTotal = round(array_sum(array_map(function ($p) { return $p['unit_price_inc_tax'] * $p['quantity']; }, $products)), 2);
                $invoiceTotal['final_total'] = $finalTotal;
                $invoiceTotal['total_before_tax'] = $finalTotal;
                $invoiceTotal['tax'] = 0;

                $transaction = $this->transactionUtil->createSellTransaction($businessId, [
                    'status' => 'final',
                    'is_quotation' => 0,
                    'location_id' => $locationId,
                    'contact_id' => $walkIn->id,
                    'transaction_date' => $date ?: WS::now()->format('Y-m-d H:i:s'),
                    'final_total' => $finalTotal,
                    'discount_type' => 'fixed',
                    'discount_amount' => 0,
                    'channel' => 'whatnot',
                    'commission_agent' => $s['user_id'] ?: null,
                    'staff_note' => $staffNote,
                    'sale_note' => 'Whatnot sale',
                    'exchange_rate' => 1,
                ], $invoiceTotal, $userId, false);
                $this->transactionUtil->createOrUpdateSellLines($transaction, $products, $locationId, false, null, [], false);
                // Paid off-register by Whatnot, same as the POS Complete button.
                $this->transactionUtil->createOrUpdatePaymentLines($transaction, [[
                    'amount' => $finalTotal, 'method' => 'other', 'paid_on' => $transaction->transaction_date, 'is_return' => 0,
                ]], $businessId, $userId, false);

                foreach ($products as $p) {
                    if ($p['product_id'] === 'manual' || empty($p['enable_stock'])) {
                        continue;
                    }
                    $this->productUtil->decreaseProductQuantity($p['product_id'], $p['variation_id'], $locationId, $p['quantity']);
                    $productIds[] = (int) $p['product_id'];
                }
                $this->transactionUtil->updatePaymentStatus($transaction->id, $transaction->final_total);
                try {
                    $this->transactionUtil->mapPurchaseSell(['id' => $businessId, 'accounting_method' => $business->accounting_method, 'location_id' => $locationId], $transaction->sell_lines, 'purchase');
                } catch (\Throwable $e) {
                    Log::warning('whatnot-seller mapPurchaseSell skipped: ' . $e->getMessage());
                }
                DB::commit();
                $saved += count($products);
                $savedTotal += $finalTotal;
            } catch (\Throwable $e) {
                DB::rollBack();
                Log::error('whatnot-seller sell failed: ' . $e->getFile() . ':' . $e->getLine() . ' ' . $e->getMessage());
                $errs[] = count($products) . ' item(s) at one store (error, nothing saved for them)';
            }
        }

        // Take the copies off nivessa.com right away.
        if ($productIds) {
            try {
                (new NivessaStockNotifier())->push(array_values(array_unique($productIds)));
            } catch (\Throwable $e) {
                Log::info('whatnot-seller stock push failed: ' . $e->getMessage());
            }
        }

        return [$saved, $savedTotal, $errs];
    }

    // ---------------------------------------------------------------- owners

    private function ensureAccess()
    {
        $u = auth()->user();
        $ok = $this->businessUtil->is_admin($u) || in_array(strtolower(trim((string) $u->first_name)), ['zak'], true);
        if (!$ok) {
            abort(403, 'Owners and Zak only.');
        }
    }

    public function dashboard(Request $request)
    {
        $this->ensureAccess();
        $businessId = (int) $request->session()->get('user.business_id');
        $s = WS::settings();

        $owedFrom = Carbon::parse($s['paid_thru'])->addDay()->format('Y-m-d');
        $from = $request->input('start_date') ?: $owedFrom;
        $to = $request->input('end_date') ?: WS::now()->format('Y-m-d');
        $lines = WS::soldLines($businessId, $from, $to);

        $total = 0.0; $stickerTotal = 0.0; $soldWithSticker = 0.0; $flagged = 0; $byGolden = 0; $count = 0;
        $under = 0.0; $premium = 0.0;
        $byMonth = [];
        foreach ($lines as $l) {
            $amt = $l['sold'] * $l['qty'];
            $total += $amt;
            $count += $l['qty'];
            if ($l['sticker'] && $l['real']) {
                $stickerTotal += $l['sticker'] * $l['qty'];
                $soldWithSticker += $amt;
                $diff = ($l['sold'] - $l['sticker']) * $l['qty'];
                if ($diff < -0.009) { $under += -$diff; }
                if ($diff > 0.009) { $premium += $diff; }
            }
            if ($l['flags']) { $flagged++; }
            if ($l['by_golden']) { $byGolden++; }
            $m = substr($l['date_raw'], 0, 7);
            $byMonth[$m] = ($byMonth[$m] ?? 0) + $amt;
        }

        $statements = [];
        foreach (WS::statementTotals($businessId, $from, Carbon::parse($to)->endOfMonth()->format('Y-m-d')) as $st) {
            $statements[$st['month']] = $st['total'];
        }
        $months = array_unique(array_merge(array_keys($byMonth), array_keys($statements)));
        sort($months);
        $monthRows = [];
        foreach ($months as $m) {
            $monthRows[] = [
                'label' => Carbon::parse($m . '-01')->format('M Y'),
                'erp' => round($byMonth[$m] ?? 0, 2),
                'statement' => $statements[$m] ?? null,
            ];
        }

        $shows = WS::hoursBetween($from, $to);

        if (empty($s['token']) || empty($s['business_id'])) {
            $s['token'] = $s['token'] ?: bin2hex(random_bytes(24));
            $s['business_id'] = $businessId;
            WS::saveSettings($s);
        }

        $users = DB::table('users')->where('business_id', $businessId)->whereNull('deleted_at')->orderBy('first_name')
            ->get(['id', DB::raw("TRIM(CONCAT(COALESCE(first_name,''),' ',COALESCE(last_name,''))) as name")]);

        return view('whatnot_seller.dashboard', [
            's' => $s,
            'link' => url('/whatnot-seller/' . $s['token']),
            'from' => $from, 'to' => $to,
            'lines' => $lines,
            'total' => round($total, 2),
            'count' => $count,
            'avg_pct' => $stickerTotal > 0 ? round($soldWithSticker / $stickerTotal * 100) : null,
            'under' => round($under, 2),
            'premium' => round($premium, 2),
            'flagged' => $flagged,
            'by_golden' => $byGolden,
            'month_rows' => $monthRows,
            'pay' => WS::owed($businessId, $from, $to),
            'owed_now' => WS::owed($businessId, $owedFrom, WS::now()->format('Y-m-d')),
            'owed_from' => $owedFrom,
            'shows' => $shows,
            'users' => $users,
            'daily' => WS::dailyCheck($lines, $from, $to),
            'wn_range' => WS::whatnotImportedRange(),
            'sku_rows' => WS::skuMatches($businessId, $from, $to),
            'receipt_rows' => array_values(array_filter(WS::receiptMatches($businessId), function ($r) use ($from, $to) { return $r['date'] >= $from && $r['date'] <= $to; })),
        ]);
    }

    public function saveSettings(Request $request)
    {
        $this->ensureAccess();
        if (!$this->businessUtil->is_admin(auth()->user())) {
            abort(403, 'Owners only.');
        }
        $data = $request->validate([
            'seller_name' => 'required|string|max:60',
            'user_id' => 'nullable|integer',
            'per_item' => 'required|numeric|min:0|max:100',
            'hourly_rate' => 'required|numeric|min:0|max:500',
            'premium_pct' => 'nullable|numeric|min:0|max:1000',
            'premium_per_item' => 'required|numeric|min:0|max:100',
            'premium_start' => 'nullable|date_format:Y-m-d',
            'min_pct_of_sticker' => 'required|integer|min:0|max:100',
            'min_days_in_store' => 'required|integer|min:0|max:365',
            'paid_thru' => 'required|date_format:Y-m-d',
            'last_paid_on' => 'nullable|date_format:Y-m-d',
            'last_paid_amount' => 'nullable|numeric|min:0',
        ]);
        $s = WS::settings();
        $s['business_id'] = (int) $request->session()->get('user.business_id');
        $s['seller_name'] = $data['seller_name'];
        $s['user_id'] = !empty($data['user_id']) ? (int) $data['user_id'] : null;
        $s['per_item'] = (float) $data['per_item'];
        $s['hourly_rate'] = (float) $data['hourly_rate'];
        $s['premium_pct'] = ($data['premium_pct'] ?? '') === '' ? null : (float) $data['premium_pct'];
        $s['premium_per_item'] = (float) $data['premium_per_item'];
        $s['premium_start'] = $data['premium_start'] ?? null;
        $s['min_pct_of_sticker'] = (int) $data['min_pct_of_sticker'];
        $s['min_days_in_store'] = (int) $data['min_days_in_store'];
        $s['paid_thru'] = $data['paid_thru'];
        $s['last_paid_on'] = $data['last_paid_on'] ?? null;
        $s['last_paid_amount'] = isset($data['last_paid_amount']) ? (float) $data['last_paid_amount'] : null;
        if ($request->input('new_link')) {
            $s['token'] = bin2hex(random_bytes(24));
        }
        WS::saveSettings($s);
        return redirect('/reports/whatnot-seller')->with('status', ['success' => 1, 'msg' => $request->input('new_link') ? 'Saved. Old link no longer works, send him the new one.' : 'Saved.']);
    }

    // Pay statement: every item and show in the period, what each pays, the
    // total. Owners open it from the dashboard; Golden from his page.
    private function statementView(int $businessId, string $from, string $to, $backUrl)
    {
        $lines = array_reverse(WS::soldLines($businessId, $from, $to));
        $shows = array_reverse(WS::hoursBetween($from, $to));
        return $this->noStore(response()->view('whatnot_seller.statement', [
            's' => WS::settings(),
            'lines' => $lines,
            'shows' => $shows,
            'pay' => WS::owed($businessId, $from, $to),
            'sales' => round(array_sum(array_map(function ($l) { return $l['sold'] * $l['qty']; }, $lines)), 2),
            'from' => $from, 'to' => $to,
            'back' => $backUrl,
        ]));
    }

    private function statementDates(Request $request): array
    {
        $to = $request->input('end_date') ?: WS::now()->format('Y-m-d');
        $from = $request->input('start_date') ?: Carbon::parse(WS::settings()['paid_thru'])->addDay()->format('Y-m-d');
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $from) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) {
            abort(404);
        }
        return [$from, $to];
    }

    public function statement(Request $request)
    {
        $this->ensureAccess();
        [$from, $to] = $this->statementDates($request);
        return $this->statementView((int) $request->session()->get('user.business_id'), $from, $to, url('/reports/whatnot-seller') . '?' . http_build_query(['start_date' => $from, 'end_date' => $to]));
    }

    public function publicStatement(Request $request, $token)
    {
        if (!WS::tokenValid($token)) {
            abort(404);
        }
        [$from, $to] = $this->statementDates($request);
        return $this->statementView((int) WS::settings()['business_id'], $from, $to, url('/whatnot-seller/' . $token));
    }

    public function importCsv(Request $request)
    {
        $this->ensureAccess();
        $files = $request->file('csv') ?: [];
        if (!is_array($files)) { $files = [$files]; }
        $added = 0; $skipped = 0; $bad = [];
        foreach ($files as $f) {
            try {
                [$a, $k] = WS::importWhatnotCsv($f->getRealPath());
                $added += $a; $skipped += $k;
            } catch (\Throwable $e) {
                $bad[] = $f->getClientOriginalName();
            }
        }
        $msg = "Imported {$added} Whatnot rows" . ($skipped ? ", {$skipped} were already in" : '') . '.' . ($bad ? ' Not a Whatnot order report: ' . implode(', ', $bad) . '.' : '');
        return redirect()->back()->with('status', ['success' => $bad ? 0 : 1, 'msg' => $msg]);
    }

    // Mark Whatnot sales that came in with our SKU but were never rung: one
    // ERP sale each, at the real Whatnot price, dated when the order happened.
    public function importSkuSales(Request $request)
    {
        $this->ensureAccess();
        $businessId = (int) $request->session()->get('user.business_id');
        $all = WS::whatnotOrders();
        $done = 0; $total = 0.0; $errs = [];
        foreach (WS::skuMatches($businessId, '2000-01-01', WS::now()->format('Y-m-d')) as $r) {
            if ($r['status'] !== 'ready') {
                continue;
            }
            $sticker = (float) $r['sticker'];
            $price = (float) $r['price'];
            $row = [
                'product_id' => $r['product_id'], 'variation_id' => $r['variation_id'],
                'product_type' => $r['product_type'], 'enable_stock' => $r['enable_stock'], 'quantity' => 1,
                'unit_price' => $sticker > 0 ? $sticker : $price, 'unit_price_inc_tax' => $price,
                'line_discount_type' => $sticker > $price ? 'fixed' : null,
                'line_discount_amount' => $sticker > $price ? round($sticker - $price, 2) : 0,
                'item_tax' => 0, 'tax_id' => null, 'base_unit_multiplier' => 1,
                'sell_line_note' => 'Whatnot order ' . $r['order'],
            ];
            [$saved, $amt, $e] = $this->ring($businessId, [$r['location_id'] => [$row]], 'WHATNOT-IMPORT: Whatnot order ' . $r['order'], $r['placed'] ?: null);
            if ($saved) {
                $all[$r['key']]['erp_tx'] = true;
                $done += $saved; $total += $amt;
            }
            $errs = array_merge($errs, $e);
        }
        WS::saveWhatnotOrders($all);
        $msg = "Marked {$done} Whatnot sale" . ($done === 1 ? '' : 's') . ' sold in the ERP ($' . number_format($total, 2) . ').' . ($errs ? ' Problems: ' . implode(', ', $errs) : '');
        return redirect()->back()->with('status', ['success' => $errs ? 0 : 1, 'msg' => $msg]);
    }

    public function legacyReport(Request $request)
    {
        return redirect('/reports/whatnot-seller' . ($request->getQueryString() ? '?' . $request->getQueryString() : ''));
    }
}
