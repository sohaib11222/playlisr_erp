<?php

namespace App\Http\Controllers;

use App\BusinessLocation;
use App\Contact;
use App\PurchaseLine;
use App\Transaction;
use App\Utils\ProductUtil;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

// AMS invoice importer.
//
// AMS (All Media Supply) emails us a PDF invoice for every distributor order.
// Someone keys those line items into a Purchase Order by hand. This page lets
// a manager drop the PDF in; the browser parses it with pdf.js (no server-side
// PDF dependency to install), POSTs the parsed lines here, we match each UPC to
// an existing product by sub_sku, the manager reviews, and we create one
// purchase_order transaction ("what's coming") with a purchase line per matched
// item.
//
// Deliberately NON-destructive: it only inserts a PO + lines. It does NOT bump
// stock (a PO isn't a receive) and does NOT touch product costs. Unmatched UPCs
// are reported, never auto-created. The created PO is snapshotted to
// admin-snapshots/ so it's one-click undoable at /admin/admin-action-history,
// and each invoice number is recorded in a sidecar so the same invoice can't be
// imported twice by accident.
class AmsInvoiceImportController extends Controller
{
    protected $productUtil;

    public function __construct(ProductUtil $productUtil)
    {
        $this->productUtil = $productUtil;
    }

    private function canAccess()
    {
        // Anyone who can add a purchase or a purchase order can import — this is
        // the import entry point for the purchases desk (Insha), not just PO admins.
        return auth()->user()->can('purchase_order.create')
            || auth()->user()->can('purchase.create');
    }

    public function index()
    {
        if (!$this->canAccess()) {
            abort(403, 'Unauthorized action.');
        }

        $business_id = request()->session()->get('user.business_id');

        $bl = BusinessLocation::forDropdown($business_id, false, true);
        $locations = $bl['locations'];

        $suppliers = Contact::suppliersDropdown($business_id, false);

        // Default the supplier to AMS / All Media Supply if we have it.
        $default_supplier_id = null;
        $ams = Contact::where('business_id', $business_id)
            ->where('type', '!=', 'customer')
            ->where(function ($q) {
                $q->where('name', 'like', '%AMS%')
                  ->orWhere('name', 'like', '%All Media Supply%')
                  ->orWhere('supplier_business_name', 'like', '%AMS%')
                  ->orWhere('supplier_business_name', 'like', '%All Media Supply%');
            })
            ->first();
        if ($ams) {
            $default_supplier_id = $ams->id;
        }

        return view('admin.ams_invoice_import')
            ->with(compact('locations', 'suppliers', 'default_supplier_id'));
    }

    // Candidate sub_sku forms for an AMS UPC. AMS prints 13-digit EANs with a
    // leading zero; our catalog may hold the same code with or without that
    // zero, or as the bare 12-digit UPC-A. Try them all.
    private function upcCandidates($upc)
    {
        $upc = preg_replace('/\D/', '', (string) $upc);
        if ($upc === '') return [];
        $c = [$upc, ltrim($upc, '0')];
        if (strlen($upc) === 13 && $upc[0] === '0') {
            $c[] = substr($upc, 1); // 12-digit UPC-A
        }
        if (strlen($upc) === 12) {
            $c[] = '0' . $upc; // 13-digit EAN
        }
        return array_values(array_unique(array_filter($c, function ($x) { return $x !== ''; })));
    }

    public function preview(Request $request)
    {
        if (!$this->canAccess()) {
            return response()->json(['success' => false, 'msg' => 'Unauthorized.'], 403);
        }

        $business_id = $request->session()->get('user.business_id');
        $items = $request->input('items', []);
        $header = $request->input('header', []);

        if (!is_array($items) || count($items) === 0) {
            return response()->json([
                'success' => false,
                'msg' => 'No line items were parsed from the PDF. Is this an AMS invoice?',
            ], 422);
        }

        // One query for every candidate UPC across the whole invoice.
        $allCandidates = [];
        foreach ($items as $it) {
            foreach ($this->upcCandidates($it['upc'] ?? '') as $cand) {
                $allCandidates[$cand] = true;
            }
        }
        $allCandidates = array_keys($allCandidates);

        $lookup = [];
        if (!empty($allCandidates)) {
            $rows = DB::table('variations as v')
                ->join('products as p', 'v.product_id', '=', 'p.id')
                ->where('p.business_id', $business_id)
                ->whereIn('v.sub_sku', $allCandidates)
                ->get([
                    'v.id as variation_id',
                    'v.product_id',
                    'v.sub_sku',
                    'v.default_purchase_price',
                    'p.name as product_name',
                    'p.type as product_type',
                ]);
            foreach ($rows as $r) {
                // First match wins per sub_sku (a UPC should be unique anyway).
                if (!isset($lookup[$r->sub_sku])) {
                    $lookup[$r->sub_sku] = $r;
                }
            }
        }

        $out = [];
        $matched = 0;
        foreach ($items as $it) {
            $upc = preg_replace('/\D/', '', (string) ($it['upc'] ?? ''));
            $hit = null;
            foreach ($this->upcCandidates($upc) as $cand) {
                if (isset($lookup[$cand])) { $hit = $lookup[$cand]; break; }
            }
            $line = [
                'upc' => $upc,
                'description' => $it['description'] ?? '',
                'qty' => (int) ($it['qty'] ?? 0),
                'unit_price' => round((float) ($it['unitPrice'] ?? $it['unit_price'] ?? 0), 2),
                'line_total' => round((float) ($it['lineTotal'] ?? $it['line_total'] ?? 0), 2),
                'matched' => false,
                'variation_id' => null,
                'product_id' => null,
                'product_name' => null,
                'current_cost' => null,
            ];
            if ($hit) {
                $matched++;
                $line['matched'] = true;
                $line['variation_id'] = (int) $hit->variation_id;
                $line['product_id'] = (int) $hit->product_id;
                $line['product_name'] = $hit->product_name;
                $line['current_cost'] = $hit->default_purchase_price !== null
                    ? round((float) $hit->default_purchase_price, 2) : null;
            }
            $out[] = $line;
        }

        // Already-imported guard (sidecar keyed on invoice number).
        $invoice = preg_replace('/[^0-9]/', '', (string) ($header['invoice'] ?? ''));
        $alreadyImported = null;
        if ($invoice !== '' && Storage::disk('local')->exists("ams-imports/{$invoice}.json")) {
            $prev = json_decode(Storage::disk('local')->get("ams-imports/{$invoice}.json"), true);
            $alreadyImported = [
                'transaction_id' => $prev['transaction_id'] ?? null,
                'applied_at' => $prev['applied_at'] ?? null,
            ];
        }

        return response()->json([
            'success' => true,
            'lines' => $out,
            'matched' => $matched,
            'unmatched' => count($out) - $matched,
            'already_imported' => $alreadyImported,
        ]);
    }

    public function apply(Request $request)
    {
        if (!$this->canAccess()) {
            return response()->json(['success' => false, 'msg' => 'Unauthorized.'], 403);
        }

        $business_id = $request->session()->get('user.business_id');
        $user_id = $request->session()->get('user.id');

        $location_id = (int) $request->input('location_id');
        $contact_id = (int) $request->input('supplier_id');
        $invoice = preg_replace('/[^0-9]/', '', (string) $request->input('invoice', ''));
        $ams_ref = preg_replace('/[^0-9]/', '', (string) $request->input('ams_ref', ''));
        $invoice_date = $request->input('invoice_date'); // YYYY-MM-DD
        $lines = $request->input('lines', []);
        $force = (bool) $request->input('force', false);

        if ($location_id <= 0 || $contact_id <= 0) {
            return response()->json(['success' => false, 'msg' => 'Pick a store and a supplier first.'], 422);
        }
        // Only matched lines (those carrying a variation_id) become PO lines.
        $lines = array_values(array_filter((array) $lines, function ($l) {
            return !empty($l['variation_id']) && (int) ($l['qty'] ?? 0) > 0;
        }));
        if (count($lines) === 0) {
            return response()->json(['success' => false, 'msg' => 'No matched items to import.'], 422);
        }

        if ($invoice !== '' && !$force && Storage::disk('local')->exists("ams-imports/{$invoice}.json")) {
            $prev = json_decode(Storage::disk('local')->get("ams-imports/{$invoice}.json"), true);
            return response()->json([
                'success' => false,
                'already_imported' => true,
                'msg' => "Invoice {$invoice} was already imported as PO #" . ($prev['transaction_id'] ?? '?') . '. Tick "import anyway" to create it again.',
            ], 409);
        }

        // Confirm every variation belongs to this business (don't trust the
        // ids posted from the browser).
        $variation_ids = array_map(function ($l) { return (int) $l['variation_id']; }, $lines);
        $valid = DB::table('variations as v')
            ->join('products as p', 'v.product_id', '=', 'p.id')
            ->where('p.business_id', $business_id)
            ->whereIn('v.id', $variation_ids)
            ->pluck('v.product_id', 'v.id'); // variation_id => product_id
        $clean = [];
        foreach ($lines as $l) {
            $vid = (int) $l['variation_id'];
            if (!isset($valid[$vid])) continue;
            $clean[] = [
                'variation_id' => $vid,
                'product_id' => (int) $valid[$vid],
                'qty' => (float) $l['qty'],
                'unit_price' => round((float) ($l['unit_price'] ?? 0), 4),
            ];
        }
        if (count($clean) === 0) {
            return response()->json(['success' => false, 'msg' => 'Matched items did not validate against the catalog.'], 422);
        }

        $total = 0.0;
        foreach ($clean as $l) { $total += $l['qty'] * $l['unit_price']; }
        $total = round($total, 2);

        $tx_date = $this->normalizeDate($invoice_date);
        $ref_no = $invoice !== '' ? ('AMS-' . $invoice) : null;
        $notes = 'AMS invoice ' . ($invoice ?: '(n/a)') . ($ams_ref ? (' / AMS REF ' . $ams_ref) : '') . ' — imported from PDF';

        DB::beginTransaction();
        try {
            $ref_count = $this->productUtil->setAndGetReferenceCount('purchase');
            if (empty($ref_no)) {
                $ref_no = $this->productUtil->generateReferenceNumber('purchase', $ref_count);
            }

            // type=purchase so it lands on the Purchases list (where the desk
            // works), status=ordered so it does NOT receive stock — this just
            // logs the invoice; inventory is received separately.
            $transaction = Transaction::create([
                'business_id' => $business_id,
                'location_id' => $location_id,
                'type' => 'purchase',
                'status' => 'ordered',
                'payment_status' => 'due',
                'contact_id' => $contact_id,
                'ref_no' => $ref_no,
                'transaction_date' => $tx_date,
                'total_before_tax' => $total,
                'tax_amount' => 0,
                'discount_amount' => 0,
                'discount_type' => 'fixed',
                'final_total' => $total,
                'additional_notes' => $notes,
                'created_by' => $user_id,
            ]);

            foreach ($clean as $l) {
                $pl = new PurchaseLine();
                $pl->transaction_id = $transaction->id;
                $pl->product_id = $l['product_id'];
                $pl->variation_id = $l['variation_id'];
                $pl->quantity = $l['qty'];
                $pl->purchase_price = $l['unit_price'];
                $pl->purchase_price_inc_tax = $l['unit_price'];
                $pl->pp_without_discount = $l['unit_price'];
                $pl->item_tax = 0;
                $pl->tax_id = null;
                $pl->save();
            }

            DB::commit();
        } catch (\Exception $e) {
            DB::rollBack();
            \Log::emergency('AMS invoice import failed: ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
            return response()->json(['success' => false, 'msg' => 'Import failed, nothing was saved: ' . $e->getMessage()], 500);
        }

        // Snapshot for one-click undo, and record the invoice so it can't be
        // double-imported. now() drives the sortable snapshot filename.
        $ts = now()->format('Ymd_His');
        $stamp = now()->toDateTimeString();
        Storage::disk('local')->put("admin-snapshots/ams-invoice-import_{$invoice}_{$ts}.json", json_encode([
            'action' => 'ams-invoice-import',
            'timestamp' => $stamp,
            'direction' => "AMS invoice {$invoice} → purchase #{$transaction->id} (" . count($clean) . ' lines)',
            'business_id' => $business_id,
            'invoice' => $invoice,
            'transaction_id' => $transaction->id,
        ], JSON_PRETTY_PRINT));

        if ($invoice !== '') {
            Storage::disk('local')->put("ams-imports/{$invoice}.json", json_encode([
                'invoice' => $invoice,
                'ams_ref' => $ams_ref,
                'transaction_id' => $transaction->id,
                'line_count' => count($clean),
                'total' => $total,
                'applied_at' => $stamp,
                'applied_by' => $user_id,
            ], JSON_PRETTY_PRINT));
        }

        return response()->json([
            'success' => true,
            'transaction_id' => $transaction->id,
            'lines' => count($clean),
            'total' => $total,
            'view_url' => action('PurchaseController@show', [$transaction->id]),
            'msg' => 'Created purchase #' . $transaction->id . ' with ' . count($clean) . ' line(s), total $' . number_format($total, 2) . '.',
        ]);
    }

    private function normalizeDate($d)
    {
        $d = trim((string) $d);
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $d)) {
            return $d . ' 00:00:00';
        }
        return now()->toDateTimeString();
    }
}
