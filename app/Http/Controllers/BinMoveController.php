<?php

namespace App\Http\Controllers;

use App\Transaction;
use App\Utils\ProductUtil;
use App\Utils\TransactionUtil;
use DB;
use Illuminate\Http\Request;

/**
 * Move records that sit in Hollywood bins but are stocked at Pico in the ERP
 * over to Hollywood (Sarah, 2026-10-16: Pico has no bins like that). One real
 * stock transfer, so Stock History keeps it. A snapshot of the stock before
 * is saved, and Undo transfers the same qty back.
 */
class BinMoveController extends Controller
{
    protected $productUtil;
    protected $transactionUtil;

    public function __construct(ProductUtil $productUtil, TransactionUtil $transactionUtil)
    {
        $this->productUtil = $productUtil;
        $this->transactionUtil = $transactionUtil;
    }

    protected function guard()
    {
        if (!auth()->user()->can('purchase.create')) {
            abort(403, 'Unauthorized action.');
        }
    }

    public static function canUndo(): bool
    {
        foreach (glob(self::snapDir() . '/bin-move-*.json') ?: [] as $f) {
            $s = json_decode((string) @file_get_contents($f), true);
            if (!empty($s['transfer_id']) && empty($s['undone_by'])) return true;
        }
        return false;
    }

    /**
     * Read-only: for the records in the last move, the first time stock was
     * added (opening stock, purchase, transfer), at which location, by whom.
     * Shows how Hollywood-bin records ended up stocked at Pico.
     */
    public static function lastMoveOrigins(): array
    {
        $files = glob(self::snapDir() . '/bin-move-*.json') ?: [];
        rsort($files);
        if (!$files) return [];
        $s = json_decode((string) @file_get_contents($files[0]), true);
        $ids = array_values(array_unique(array_map(function ($l) { return (int) $l['product_id']; }, $s['lines'] ?? [])));
        if (!$ids) return [];
        $rows = DB::table('purchase_lines as pl')
            ->join('transactions as t', 't.id', '=', 'pl.transaction_id')
            ->leftJoin('business_locations as bl', 'bl.id', '=', 't.location_id')
            ->leftJoin('users as u', 'u.id', '=', 't.created_by')
            ->whereIn('pl.product_id', $ids)
            ->whereIn('t.type', ['opening_stock', 'purchase', 'purchase_transfer'])
            ->orderBy('t.transaction_date')
            ->get(['pl.product_id', 't.type', 't.transaction_date', 't.ref_no', 't.additional_notes', 'bl.name as loc', 'u.first_name', 'u.last_name']);
        $first = [];
        foreach ($rows as $r) {
            if (!isset($first[$r->product_id])) $first[$r->product_id] = $r;
        }
        $products = DB::table('products as p')->leftJoin('users as u', 'u.id', '=', 'p.created_by')
            ->whereIn('p.id', $ids)->get(['p.id', 'p.name', 'p.created_at', 'p.bin_position', 'p.listing_location', 'u.first_name', 'u.last_name']);
        $out = [];
        foreach ($products as $p) {
            $f = $first[$p->id] ?? null;
            $out[] = [
                'id' => (int) $p->id,
                'name' => $p->name,
                'created' => $p->created_at,
                'created_by' => trim(($p->first_name ?? '') . ' ' . ($p->last_name ?? '')),
                'bin' => trim((string) ($p->bin_position ?: $p->listing_location)),
                'how' => $f ? str_replace('_', ' ', $f->type) : 'no stock entry',
                'where' => $f->loc ?? '',
                'by' => $f ? trim(($f->first_name ?? '') . ' ' . ($f->last_name ?? '')) : '',
                'note' => $f ? mb_substr((string) $f->additional_notes, 0, 80) : '',
            ];
        }
        usort($out, function ($a, $b) { return strcmp((string) $a['created'], (string) $b['created']); });
        return $out;
    }

    protected static function snapDir(): string
    {
        $d = storage_path('admin-snapshots');
        if (!is_dir($d)) @mkdir($d, 0775, true);
        return $d;
    }

    protected function locationIds(int $businessId): array
    {
        $locs = DB::table('business_locations')->where('business_id', $businessId)->pluck('name', 'id');
        $pico = $hw = null;
        foreach ($locs as $id => $name) {
            if (stripos($name, 'warehouse') !== false) continue;
            if ($pico === null && stripos($name, 'pico') !== false) $pico = (int) $id;
            if ($hw === null && stripos($name, 'hollywood') !== false) $hw = (int) $id;
        }
        return [$pico, $hw];
    }

    /** Pico stock lines for these ERP product ids. */
    protected function picoLines(array $ids, int $pico): array
    {
        if (!$ids) return [];
        return DB::table('variation_location_details as vld')
            ->join('variations as v', 'v.id', '=', 'vld.variation_id')
            ->join('products as p', 'p.id', '=', 'vld.product_id')
            ->whereIn('vld.product_id', $ids)
            ->where('vld.location_id', $pico)
            ->where('vld.qty_available', '>', 0)
            ->where('p.enable_stock', 1)
            ->select('vld.product_id', 'vld.variation_id', 'vld.qty_available', 'v.default_purchase_price', 'p.name')
            ->get()->map(function ($r) { return (array) $r; })->all();
    }

    /**
     * Transfer lines from one location to another as one completed stock transfer.
     * Public so the bulk Transfer page (BulkTransferController) reuses the same ledger path.
     */
    public function transfer(int $businessId, int $from, int $to, array $lines, string $note, int $userId): Transaction
    {
        $refCount = $this->productUtil->setAndGetReferenceCount('stock_transfer', $businessId);
        $base = [
            'business_id' => $businessId,
            'location_id' => $from,
            'type' => 'sell_transfer',
            'status' => 'final',
            'payment_status' => 'paid',
            'ref_no' => $this->productUtil->generateReferenceNumber('stock_transfer', $refCount, $businessId),
            'transaction_date' => \Carbon::now()->toDateTimeString(),
            'additional_notes' => $note,
            'shipping_charges' => 0,
            'created_by' => $userId,
        ];
        $total = 0;
        $sell = [];
        $purchase = [];
        foreach ($lines as $l) {
            $qty = (float) $l['qty'];
            $price = (float) ($l['price'] ?? 0);
            $total += $qty * $price;
            $sell[] = ['product_id' => $l['product_id'], 'variation_id' => $l['variation_id'], 'quantity' => $qty,
                'item_tax' => 0, 'tax_id' => null, 'unit_price' => $price, 'unit_price_inc_tax' => $price];
            $purchase[] = ['product_id' => $l['product_id'], 'variation_id' => $l['variation_id'], 'quantity' => $qty,
                'item_tax' => 0, 'tax_id' => null, 'purchase_price' => $price, 'purchase_price_inc_tax' => $price];
        }
        $base['final_total'] = $total;
        $base['total_before_tax'] = $total;

        $sellT = Transaction::create($base);
        $p = $base;
        $p['type'] = 'purchase_transfer';
        $p['location_id'] = $to;
        $p['transfer_parent_id'] = $sellT->id;
        $p['status'] = 'received';
        $purchaseT = Transaction::create($p);

        $this->transactionUtil->createOrUpdateSellLines($sellT, $sell, $from, false, null, [], false);
        $purchaseT->purchase_lines()->createMany($purchase);

        foreach ($lines as $l) {
            $this->productUtil->decreaseProductQuantity($l['product_id'], $l['variation_id'], $from, (float) $l['qty']);
            $this->productUtil->updateProductQuantity($to, $l['product_id'], $l['variation_id'], (float) $l['qty'], 0, null, false);
            DB::table('product_locations')->updateOrInsert(['product_id' => $l['product_id'], 'location_id' => $to], []);
        }
        $this->productUtil->adjustStockOverSelling($purchaseT);
        // Cost mapping only; old records often have no purchase lines, which must not block the move.
        try {
            $this->transactionUtil->mapPurchaseSell(
                ['id' => $businessId, 'accounting_method' => session('business.accounting_method') ?: (DB::table('business')->where('id', $businessId)->value('accounting_method') ?: 'fifo'), 'location_id' => $from],
                $sellT->fresh()->sell_lines,
                'purchase'
            );
        } catch (\Throwable $e) {
            \Log::warning('BinMove mapPurchaseSell skipped: ' . $e->getMessage());
        }
        try { $this->transactionUtil->activityLog($sellT, 'added'); } catch (\Throwable $e) {}
        return $sellT;
    }

    /**
     * Move every in-stock record in a Hollywood bin that the ERP has at Pico.
     * Used by the button and the daily bin:move-to-hollywood job.
     * Returns ['moved' => n, 'ref' => ref_no|null, 'error' => string|null].
     */
    public function runMove(int $businessId, int $userId, string $note): array
    {
        [$pico, $hw] = $this->locationIds($businessId);
        if (!$pico || !$hw) return ['moved' => 0, 'ref' => null, 'error' => 'Could not find the Pico and Hollywood locations.'];

        $resp = app(WebsiteOrdersController::class)->bridgeGet('/erp/bin-store/products', 90);
        if (!($resp['success'] ?? false)) return ['moved' => 0, 'ref' => null, 'error' => ($resp['message'] ?? null) ?: 'Could not reach the website.'];
        $ids = array_values(array_unique(array_filter(array_map(function ($r) { return (int) ($r['posProductId'] ?? 0); }, $resp['data'] ?? []))));
        $rows = $this->picoLines($ids, $pico);
        if (!$rows) return ['moved' => 0, 'ref' => null, 'error' => null];
        $lines = array_map(function ($r) {
            return ['product_id' => (int) $r['product_id'], 'variation_id' => (int) $r['variation_id'], 'qty' => (float) $r['qty_available'],
                'price' => (float) ($r['default_purchase_price'] ?? 0), 'name' => $r['name']];
        }, $rows);

        $stamp = date('Ymd-His');
        $snapFile = self::snapDir() . "/bin-move-{$stamp}.json";
        $before = DB::table('variation_location_details')->whereIn('variation_id', array_column($lines, 'variation_id'))
            ->whereIn('location_id', [$pico, $hw])->get(['product_id', 'variation_id', 'location_id', 'qty_available']);
        file_put_contents($snapFile, json_encode(['at' => $stamp, 'by' => $userId, 'from' => $pico, 'to' => $hw,
            'lines' => $lines, 'vld_before' => $before], JSON_PRETTY_PRINT));

        try {
            DB::beginTransaction();
            $t = $this->transfer($businessId, $pico, $hw, $lines, $note, $userId);
            DB::commit();
        } catch (\Throwable $e) {
            DB::rollBack();
            \Log::error('BinMove failed: ' . $e->getMessage() . ' ' . $e->getFile() . ':' . $e->getLine());
            return ['moved' => 0, 'ref' => null, 'error' => 'Move failed, nothing changed: ' . $e->getMessage()];
        }

        $snap = json_decode(file_get_contents($snapFile), true);
        $snap['transfer_id'] = $t->id;
        $snap['ref_no'] = $t->ref_no;
        file_put_contents($snapFile, json_encode($snap, JSON_PRETTY_PRINT));
        return ['moved' => count($lines), 'ref' => $t->ref_no, 'error' => null];
    }

    public function move(Request $request)
    {
        $this->guard();
        $r = $this->runMove((int) $request->session()->get('user.business_id'), (int) auth()->user()->id,
            'Bin Store Check: records in Hollywood bins that were stocked at Pico');
        if ($r['error']) return redirect()->route('website-orders.binCheck')->with('error', $r['error']);
        if (!$r['moved']) return redirect()->route('website-orders.binCheck')->with('status', 'Nothing to move: none of these have Pico stock in the ERP.');
        $n = $r['moved'];
        return redirect()->route('website-orders.binCheck')->with('status', "Moved {$n} record" . ($n === 1 ? '' : 's') . " to Hollywood (stock transfer {$r['ref']}).");
    }

    /** Run the daily bin:move-to-hollywood job now, exactly as the scheduler does. */
    public function runDaily(Request $request)
    {
        $this->guard();
        try {
            \Artisan::call('bin:move-to-hollywood');
            $out = trim(\Artisan::output());
        } catch (\Throwable $e) {
            return redirect()->route('website-orders.binCheck')->with('error', 'Daily check failed: ' . $e->getMessage());
        }
        return redirect()->route('website-orders.binCheck')->with('status', 'Daily check ran: ' . ($out !== '' ? $out : 'no output') . '.');
    }

    /** Undo the last move: transfer the same qty back to Pico. */
    public function undo(Request $request)
    {
        $this->guard();
        $files = glob(self::snapDir() . '/bin-move-*.json') ?: [];
        rsort($files);
        $file = null;
        foreach ($files as $f) {
            $s = json_decode(file_get_contents($f), true);
            if (!empty($s['transfer_id']) && empty($s['undone_by'])) { $file = $f; break; }
        }
        if (!$file) {
            return redirect()->route('website-orders.binCheck')->with('error', 'No move to undo.');
        }
        $s = json_decode(file_get_contents($file), true);
        $businessId = (int) $request->session()->get('user.business_id');
        try {
            DB::beginTransaction();
            $t = $this->transfer($businessId, (int) $s['to'], (int) $s['from'], $s['lines'], 'Undo of Bin Store Check move ' . ($s['ref_no'] ?? ''), (int) auth()->user()->id);
            DB::commit();
        } catch (\Throwable $e) {
            DB::rollBack();
            return redirect()->route('website-orders.binCheck')->with('error', 'Undo failed, nothing changed: ' . $e->getMessage());
        }
        $s['undone_by'] = $t->ref_no;
        file_put_contents($file, json_encode($s, JSON_PRETTY_PRINT));
        return redirect()->route('website-orders.binCheck')->with('status', 'Undone: moved them back to Pico (' . $t->ref_no . ').');
    }
}
