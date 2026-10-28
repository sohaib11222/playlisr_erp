<?php

namespace App\Http\Controllers;

use DB;
use Illuminate\Http\Request;

/**
 * Transfer receipts (Sarah, 2026-10-26). Every Transfer Items move gets an
 * acceptance ID (TR12345). The sending store prints a slip with that ID as a
 * barcode plus a "goes to" label for each item; the receiving store scans the
 * ID on Receive Transfer, scans the items, and marks the box received. Stock
 * already moved at send time (same ledger path as before), so receiving only
 * records who got it and which items were missing.
 */
class TransferReceiptController extends Controller
{
    protected function guard()
    {
        if (!auth()->user()->can('purchase.create')) {
            abort(403, 'Unauthorized action.');
        }
    }

    /** Called by BulkTransferController right after the stock moved. */
    public static function createFor(int $businessId, $sellTransfer, int $from, int $to, float $itemCount, int $userId): array
    {
        do {
            $code = 'TR' . random_int(10000, 99999);
        } while (DB::table('transfer_receipts')->where('code', $code)->exists());
        $id = DB::table('transfer_receipts')->insertGetId([
            'business_id' => $businessId,
            'transaction_id' => $sellTransfer->id,
            'code' => $code,
            'from_location_id' => $from,
            'to_location_id' => $to,
            'item_count' => $itemCount,
            'status' => 'in_transit',
            'sent_by' => $userId,
            'sent_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        return ['id' => $id, 'code' => $code];
    }

    protected function findReceipt(int $businessId, $idOrCode)
    {
        $q = DB::table('transfer_receipts as r')
            ->leftJoin('business_locations as l1', 'l1.id', '=', 'r.from_location_id')
            ->leftJoin('business_locations as l2', 'l2.id', '=', 'r.to_location_id')
            ->leftJoin('users as us', 'us.id', '=', 'r.sent_by')
            ->leftJoin('users as ur', 'ur.id', '=', 'r.received_by')
            ->leftJoin('transactions as t', 't.id', '=', 'r.transaction_id')
            ->where('r.business_id', $businessId)
            ->select('r.*', 'l1.name as from_name', 'l2.name as to_name', 't.ref_no',
                DB::raw("TRIM(CONCAT(COALESCE(us.first_name,''),' ',COALESCE(us.last_name,''))) as sent_by_name"),
                DB::raw("TRIM(CONCAT(COALESCE(ur.first_name,''),' ',COALESCE(ur.last_name,''))) as received_by_name"));
        $code = strtoupper(trim((string) $idOrCode));
        if (preg_match('/^TR\d+$/', $code)) {
            $q->where('r.code', $code);
        } else {
            $q->where('r.id', (int) $idOrCode);
        }
        $r = $q->first();
        if ($r) {
            $r->from_name = ucfirst((string) $r->from_name);
            $r->to_name = ucfirst((string) $r->to_name);
        }
        return $r;
    }

    protected function items($receipt): array
    {
        return DB::table('transaction_sell_lines as sl')
            ->join('products as p', 'p.id', '=', 'sl.product_id')
            ->join('variations as v', 'v.id', '=', 'sl.variation_id')
            ->where('sl.transaction_id', $receipt->transaction_id)
            ->orderBy('p.name')
            ->get(['sl.product_id', 'sl.variation_id', 'sl.quantity', 'p.name', 'v.sub_sku', 'v.sell_price_inc_tax as price',
                DB::raw('COALESCE(p.listed_at, p.created_at) as listed_at')])
            ->map(function ($r) {
                return [
                    'product_id' => (int) $r->product_id,
                    'variation_id' => (int) $r->variation_id,
                    'qty' => (float) $r->quantity,
                    'name' => $r->name,
                    'sku' => (string) $r->sub_sku,
                    'price' => (float) $r->price,
                    'listed' => $r->listed_at ? \Carbon::parse($r->listed_at)->format('m/d/y') : '',
                ];
            })->all();
    }

    /** Packing slip: acceptance ID barcode + item list, goes in the box. */
    public function slip(Request $request, $id)
    {
        $this->guard();
        $receipt = $this->findReceipt((int) $request->session()->get('user.business_id'), $id);
        abort_if(!$receipt, 404);
        $items = $this->items($receipt);
        return view('bulk_transfer.slip', compact('receipt', 'items'));
    }

    /** One "goes to" label per copy, so a mixed pile can be sorted by store. */
    public function labels(Request $request, $id)
    {
        $this->guard();
        $receipt = $this->findReceipt((int) $request->session()->get('user.business_id'), $id);
        abort_if(!$receipt, 404);
        $labels = [];
        foreach ($this->items($receipt) as $it) {
            for ($i = 0; $i < max(1, (int) round($it['qty'])); $i++) {
                $labels[] = $it;
            }
        }
        return view('bulk_transfer.labels', compact('receipt', 'labels'));
    }

    /** Receiving store: scan the acceptance ID, then the items. */
    public function receive(Request $request)
    {
        $this->guard();
        $business_id = (int) $request->session()->get('user.business_id');
        $receipt = null;
        $items = [];
        $notFound = false;
        if ($request->filled('code')) {
            $receipt = $this->findReceipt($business_id, $request->input('code'));
            $notFound = !$receipt;
            if ($receipt) {
                $items = $this->items($receipt);
            }
        }
        $dutyLocation = (int) $request->session()->get('pos_duty_location_id', 0)
            ?: (int) $request->session()->get('user.business_location_id', 0);
        $inTransit = $this->list($business_id, 'in_transit', 50);
        $missingQty = $receipt ? $this->missingQty($receipt) : [];
        return view('bulk_transfer.receive', compact('receipt', 'items', 'notFound', 'dutyLocation', 'inTransit', 'missingQty'));
    }

    /** product_id => qty still missing on a received box. */
    protected function missingQty($receipt): array
    {
        $out = [];
        foreach ($receipt->missing_json ? (array) json_decode($receipt->missing_json, true) : [] as $m) {
            $out[(int) $m['product_id']] = ($out[(int) $m['product_id']] ?? 0) + (float) $m['qty'];
        }
        return $out;
    }

    /** Box was marked received with items missing, then some turned up: scan them to clear them. */
    public function found(Request $request, $id)
    {
        $this->guard();
        $business_id = (int) $request->session()->get('user.business_id');
        $receipt = $this->findReceipt($business_id, $id);
        abort_if(!$receipt, 404);
        if ($receipt->status !== 'received_missing') {
            return redirect('/transfers/receive?code=' . $receipt->code)
                ->with('status', ['success' => 0, 'msg' => 'Nothing is missing on this transfer.']);
        }
        $scanned = (array) $request->input('got', []);
        $was = $this->missingQty($receipt);
        $missing = [];
        $foundQty = 0;
        foreach ($this->items($receipt) as $it) {
            $left = $was[$it['product_id']] ?? 0;
            if ($left <= 0) {
                continue;
            }
            $got = min($left, max(0, (float) ($scanned[$it['variation_id']] ?? 0)));
            $foundQty += $got;
            $was[$it['product_id']] = 0;
            if ($left - $got > 0.0001) {
                $missing[] = ['product_id' => $it['product_id'], 'name' => $it['name'], 'sku' => $it['sku'], 'qty' => $left - $got];
            }
        }
        if ($foundQty <= 0) {
            return redirect('/transfers/receive?code=' . $receipt->code)
                ->with('status', ['success' => 0, 'msg' => 'Nothing scanned, so nothing changed.']);
        }
        $user = auth()->user();
        $line = sprintf('Found %s later (%s, %s).', rtrim(rtrim(number_format($foundQty, 2), '0'), '.'),
            trim($user->first_name . ' ' . $user->last_name), now()->format('m/d/y g:ia'));
        DB::table('transfer_receipts')->where('id', $receipt->id)->where('status', 'received_missing')->update([
            'status' => $missing ? 'received_missing' : 'received',
            'missing_json' => $missing ? json_encode($missing) : null,
            'receive_note' => mb_substr(trim(($receipt->receive_note ? $receipt->receive_note . ' ' : '') . $line), 0, 250),
            'updated_at' => now(),
        ]);
        $left = array_sum(array_column($missing, 'qty'));
        $msg = $missing
            ? sprintf('Transfer %s: marked %s found. %s still missing.', $receipt->code, rtrim(rtrim(number_format($foundQty, 2), '0'), '.'), rtrim(rtrim(number_format($left, 2), '0'), '.'))
            : sprintf('Transfer %s: everything is found. It now shows as Received.', $receipt->code);
        return redirect('/transfers/receive?code=' . $receipt->code)->with('status', ['success' => $missing ? 0 : 1, 'msg' => $msg]);
    }

    public function confirm(Request $request, $id)
    {
        $this->guard();
        $business_id = (int) $request->session()->get('user.business_id');
        $receipt = $this->findReceipt($business_id, $id);
        abort_if(!$receipt, 404);
        if ($receipt->status !== 'in_transit') {
            return redirect('/transfers/receive?code=' . $receipt->code)
                ->with('status', ['success' => 0, 'msg' => 'This transfer was already received by ' . $receipt->received_by_name . '.']);
        }
        $scanned = (array) $request->input('got', []);
        $missing = [];
        foreach ($this->items($receipt) as $it) {
            $got = (float) ($scanned[$it['variation_id']] ?? 0);
            if ($got + 0.0001 < $it['qty']) {
                $missing[] = ['product_id' => $it['product_id'], 'name' => $it['name'], 'sku' => $it['sku'], 'qty' => $it['qty'] - $got];
            }
        }
        DB::table('transfer_receipts')->where('id', $receipt->id)->where('status', 'in_transit')->update([
            'status' => $missing ? 'received_missing' : 'received',
            'received_by' => auth()->id(),
            'received_at' => now(),
            'missing_json' => $missing ? json_encode($missing) : null,
            'receive_note' => mb_substr(trim((string) $request->input('note', '')), 0, 250) ?: null,
            'updated_at' => now(),
        ]);
        $msg = $missing
            ? sprintf('Transfer %s received, but %d item%s missing. A manager can see it on the Transfer Log.', $receipt->code, count($missing), count($missing) === 1 ? ' is' : 's are')
            : sprintf('Transfer %s received. Every item is here.', $receipt->code);
        return redirect('/transfers/receive?code=' . $receipt->code)->with('status', ['success' => $missing ? 0 : 1, 'msg' => $msg]);
    }

    /** Who sent and who received what. */
    public function log(Request $request)
    {
        $this->guard();
        $business_id = (int) $request->session()->get('user.business_id');
        $status = (string) $request->input('status', '');
        $rows = $this->list($business_id, $status, 300);
        return view('bulk_transfer.log', compact('rows', 'status'));
    }

    protected function list(int $business_id, string $status, int $limit): array
    {
        $q = DB::table('transfer_receipts as r')
            ->leftJoin('business_locations as l1', 'l1.id', '=', 'r.from_location_id')
            ->leftJoin('business_locations as l2', 'l2.id', '=', 'r.to_location_id')
            ->leftJoin('users as us', 'us.id', '=', 'r.sent_by')
            ->leftJoin('users as ur', 'ur.id', '=', 'r.received_by')
            ->where('r.business_id', $business_id)
            ->orderBy('r.id', 'desc')
            ->limit($limit)
            ->select('r.*', 'l1.name as from_name', 'l2.name as to_name', 'us.first_name as sent_first', 'us.last_name as sent_last',
                'ur.first_name as rec_first', 'ur.last_name as rec_last');
        if (in_array($status, ['in_transit', 'received', 'received_missing'], true)) {
            $q->where('r.status', $status);
        }
        return $q->get()->map(function ($r) {
            return (object) [
                'id' => $r->id,
                'code' => $r->code,
                'from' => ucfirst((string) $r->from_name),
                'to' => ucfirst((string) $r->to_name),
                'items' => (float) $r->item_count,
                'status' => $r->status,
                'sent_by' => trim($r->sent_first . ' ' . $r->sent_last),
                'sent_at' => \Carbon::parse($r->sent_at)->format('m/d/y g:ia'),
                'received_by' => trim($r->rec_first . ' ' . $r->rec_last),
                'received_at' => $r->received_at ? \Carbon::parse($r->received_at)->format('m/d/y g:ia') : '',
                'missing' => $r->missing_json ? json_decode($r->missing_json, true) : [],
                'note' => $r->receive_note,
            ];
        })->all();
    }
}
