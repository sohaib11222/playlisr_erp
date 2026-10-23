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

        $periodStart = $s['paid_through'] ? Carbon::parse($s['paid_through'])->addDay()->format('Y-m-d') : WS::now()->subDays(60)->format('Y-m-d');
        $lines = WS::soldLines($businessId, $periodStart, WS::now()->format('Y-m-d'));
        $sales = array_sum(array_map(function ($l) { return $l['sold'] * $l['qty']; }, $lines));
        $items = array_sum(array_map(function ($l) { return $l['qty']; }, $lines));
        $shows = WS::hoursBetween($periodStart, WS::now()->format('Y-m-d'));
        $hours = array_sum(array_map(function ($h) { return (float) $h['hours']; }, $shows));

        $stores = DB::table('business_locations')->where('business_id', $businessId)->whereNull('deleted_at')->orderBy('name')->pluck('name', 'id');

        return $this->noStore(response()->view('whatnot_seller.public', [
            'token'        => $token,
            's'            => $s,
            'lines'        => $lines,
            'sales'        => round($sales, 2),
            'pay'          => WS::pay($items, $hours),
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
                    $skipped[] = ($it['title'] ?? 'an item') . ' (already sold or not in stock)';
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
            return $back->with('wn_flash', ['ok' => false, 'msg' => 'Nothing saved: ' . implode(', ', $skipped)]);
        }

        $walkIn = Contact::where('business_id', $businessId)->where('is_default', 1)->first()
            ?: Contact::where('business_id', $businessId)->where('type', 'customer')->first();
        $business = DB::table('business')->where('id', $businessId)->first(['id', 'owner_id', 'accounting_method']);
        $userId = (int) ($s['user_id'] ?: $business->owner_id);
        $saved = 0;
        $savedTotal = 0.0;
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
                    'transaction_date' => WS::now()->format('Y-m-d H:i:s'),
                    'final_total' => $finalTotal,
                    'discount_type' => 'fixed',
                    'discount_amount' => 0,
                    'channel' => 'whatnot',
                    'commission_agent' => $s['user_id'] ?: null,
                    'staff_note' => WS::NOTE_TAG . ': entered by ' . $s['seller_name'] . ' on his Whatnot page',
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
                $skipped[] = count($products) . ' item(s) at one store (error, nothing saved for them)';
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

        $msg = "Saved {$saved} item" . ($saved === 1 ? '' : 's') . ' ($' . number_format($savedTotal, 2) . ').';
        if ($skipped) {
            $msg .= ' Not saved: ' . implode(', ', $skipped) . '.';
        }
        return $back->with('wn_flash', ['ok' => empty($skipped), 'msg' => $msg]);
    }

    public function logHours(Request $request, $token)
    {
        if (!WS::tokenValid($token)) {
            abort(404);
        }
        $back = redirect('/whatnot-seller/' . $token);
        $date = (string) $request->input('date');
        $hours = round((float) $request->input('hours'), 2);
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || $date > WS::now()->format('Y-m-d') || $date < WS::now()->subDays(90)->format('Y-m-d')) {
            return $back->with('wn_flash', ['ok' => false, 'msg' => 'Pick the date of the show.']);
        }
        if ($hours <= 0 || $hours > 16) {
            return $back->with('wn_flash', ['ok' => false, 'msg' => 'Hours should be between 0 and 16.']);
        }
        $rows = WS::hours();
        $rows[] = [
            'id' => bin2hex(random_bytes(6)),
            'date' => $date,
            'hours' => $hours,
            'note' => mb_substr(trim((string) $request->input('note', '')), 0, 120),
            'logged_at' => WS::now()->format('Y-m-d H:i'),
        ];
        WS::saveHours($rows);
        return $back->with('wn_flash', ['ok' => true, 'msg' => 'Logged ' . rtrim(rtrim(number_format($hours, 2), '0'), '.') . ' hours for ' . Carbon::parse($date)->format('m/d/y') . '.']);
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

        $from = $request->input('start_date') ?: WS::now()->subDays(60)->format('Y-m-d');
        $to = $request->input('end_date') ?: WS::now()->format('Y-m-d');
        $lines = WS::soldLines($businessId, $from, $to);

        $total = 0.0; $stickerTotal = 0.0; $soldWithSticker = 0.0; $flagged = 0; $byGolden = 0; $count = 0;
        $under = 0.0; $premium = 0.0;
        $byMonth = [];
        foreach ($lines as $l) {
            $amt = $l['sold'] * $l['qty'];
            $total += $amt;
            $count += $l['qty'];
            if ($l['sticker']) {
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
        $hours = array_sum(array_map(function ($h) { return (float) $h['hours']; }, $shows));

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
            'pay' => WS::pay($count, $hours),
            'shows' => $shows,
            'users' => $users,
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
            'min_pct_of_sticker' => 'required|integer|min:0|max:100',
            'min_days_in_store' => 'required|integer|min:0|max:365',
            'paid_through' => 'nullable|date_format:Y-m-d',
        ]);
        $s = WS::settings();
        $s['business_id'] = (int) $request->session()->get('user.business_id');
        $s['seller_name'] = $data['seller_name'];
        $s['user_id'] = !empty($data['user_id']) ? (int) $data['user_id'] : null;
        $s['per_item'] = (float) $data['per_item'];
        $s['hourly_rate'] = (float) $data['hourly_rate'];
        $s['min_pct_of_sticker'] = (int) $data['min_pct_of_sticker'];
        $s['min_days_in_store'] = (int) $data['min_days_in_store'];
        $s['paid_through'] = $data['paid_through'] ?? null;
        if ($request->input('new_link')) {
            $s['token'] = bin2hex(random_bytes(24));
        }
        WS::saveSettings($s);
        return redirect('/reports/whatnot-seller')->with('status', ['success' => 1, 'msg' => $request->input('new_link') ? 'Saved. Old link no longer works, send him the new one.' : 'Saved.']);
    }

    public function deleteHours(Request $request)
    {
        $this->ensureAccess();
        $id = (string) $request->input('id');
        WS::saveHours(array_values(array_filter(WS::hours(), function ($h) use ($id) { return $h['id'] !== $id; })));
        return redirect()->back()->with('status', ['success' => 1, 'msg' => 'Removed that show.']);
    }
}
