<?php

namespace App\Http\Controllers;

use App\BusinessLocation;
use App\Contact;
use App\Services\DistributorInvoiceImport;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

// Distributor invoice importers: AMS (/admin/ams-invoice-import) and Alliance
// (/admin/alliance-invoice-import).
//
// Each distributor emails/portals a PDF invoice per shipment. A manager drops
// the PDF in; the browser parses it with pdf.js (no server-side PDF dependency),
// POSTs the lines here, we match each UPC to a product, propose a new product
// for every UPC we don't carry yet, flag lines already logged on another
// purchase, the manager reviews, and we create one purchase (status=ordered)
// holding every ticked line so the store can receive everything in the box.
//
// Non-destructive: an ordered purchase does NOT bump stock and does not touch
// existing products' costs. New products and the purchase are snapshotted to
// admin-snapshots/ for one-click undo at /admin/admin-action-history, and each
// invoice number is recorded in a sidecar so it can't be imported twice by
// accident. Shared logic lives in App\Services\DistributorInvoiceImport.
class AmsInvoiceImportController extends Controller
{
    private function canAccess()
    {
        // Anyone who can add a purchase or a purchase order can import — this is
        // the import entry point for the purchases desk (Insha), not just PO admins.
        return auth()->user()->can('purchase_order.create')
            || auth()->user()->can('purchase.create');
    }

    public function index()
    {
        return $this->showPage('ams');
    }

    public function preview(Request $request)
    {
        return $this->runPreview($request, 'ams');
    }

    public function apply(Request $request)
    {
        return $this->runApply($request, 'ams');
    }

    public function allianceIndex()
    {
        return $this->showPage('alliance');
    }

    public function alliancePreview(Request $request)
    {
        return $this->runPreview($request, 'alliance');
    }

    public function allianceApply(Request $request)
    {
        return $this->runApply($request, 'alliance');
    }

    public function redeyePreview(Request $request)
    {
        return $this->runPreview($request, 'redeye');
    }

    public function redeyeApply(Request $request)
    {
        return $this->runApply($request, 'redeye');
    }

    // Daily Chrome task posts the invoices it read on the Redeye portal.
    public function redeyeAuto(Request $request)
    {
        if (!$this->canAccess()) {
            return response()->json(['success' => false, 'msg' => 'Unauthorized.'], 403);
        }
        $invoices = json_decode((string) $request->input('invoices', '[]'), true);
        if (!is_array($invoices)) {
            return response()->json(['success' => false, 'msg' => 'invoices must be a JSON array.'], 422);
        }
        $report = (new \App\Services\DistributorInvoiceIntake())->importRedeye($invoices, filter_var($request->input('dry', false), FILTER_VALIDATE_BOOLEAN));
        return response()->json(['success' => true, 'report' => $report]);
    }

    private function showPage($distributor)
    {
        if (!$this->canAccess()) {
            abort(403, 'Unauthorized action.');
        }

        $business_id = request()->session()->get('user.business_id');
        $bl = BusinessLocation::forDropdown($business_id, false, true);
        $locations = $bl['locations'];
        $suppliers = Contact::suppliersDropdown($business_id, false);
        $default_supplier_id = DistributorInvoiceImport::defaultSupplierId($business_id, $distributor);
        $categories = DistributorInvoiceImport::categoryIds($business_id); // name => id
        $cfg = DistributorInvoiceImport::config($distributor);
        $base = '/admin/' . $distributor . '-invoice-import';

        // Account # => location id, so the page can pick the store from the PDF.
        $account_locations = [];
        foreach (array_keys($cfg['accounts']) as $acct) {
            $account_locations[$acct] = DistributorInvoiceImport::locationForAccount($business_id, $distributor, $acct);
        }

        return view('admin.distributor_invoice_import')
            ->with(compact('locations', 'suppliers', 'default_supplier_id', 'categories', 'distributor', 'cfg', 'base', 'account_locations'));
    }

    private function runPreview(Request $request, $distributor)
    {
        if (!$this->canAccess()) {
            return response()->json(['success' => false, 'msg' => 'Unauthorized.'], 403);
        }
        $cfg = DistributorInvoiceImport::config($distributor);
        $business_id = $request->session()->get('user.business_id');
        $items = $request->input('items', []);
        $header = $request->input('header', []);

        if (!is_array($items) || count($items) === 0) {
            return response()->json([
                'success' => false,
                'msg' => 'No line items were parsed from the PDF. Is this an ' . $cfg['label'] . ' invoice?',
            ], 422);
        }

        $lines = array_map(function ($it) {
            return [
                'upc' => $it['upc'] ?? '',
                'description' => $it['description'] ?? '',
                'format' => $it['prodType'] ?? ($it['format'] ?? ''),
                'qty' => $it['qty'] ?? 0,
                'unit_price' => $it['unitPrice'] ?? ($it['unit_price'] ?? 0),
                'line_total' => $it['lineTotal'] ?? ($it['line_total'] ?? 0),
            ];
        }, $items);

        $out = DistributorInvoiceImport::preview(
            $business_id, $lines,
            (int) $request->input('supplier_id'), (int) $request->input('location_id'),
            $header['date'] ?? '',
            DistributorInvoiceImport::invoiceKey($distributor, $header['invoice'] ?? ''), $distributor
        );

        $matched = count(array_filter($out, function ($l) { return $l['matched']; }));

        $invoice = DistributorInvoiceImport::invoiceKey($distributor, $header['invoice'] ?? '');
        $alreadyImported = null;
        if ($invoice !== '' && Storage::disk('local')->exists(DistributorInvoiceImport::sidecarPath($distributor, $invoice))) {
            $prev = json_decode(Storage::disk('local')->get(DistributorInvoiceImport::sidecarPath($distributor, $invoice)), true);
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

    private function runApply(Request $request, $distributor)
    {
        if (!$this->canAccess()) {
            return response()->json(['success' => false, 'msg' => 'Unauthorized.'], 403);
        }
        $cfg = DistributorInvoiceImport::config($distributor);
        $business_id = $request->session()->get('user.business_id');
        $user_id = $request->session()->get('user.id');

        $location_id = (int) $request->input('location_id');
        $contact_id = (int) $request->input('supplier_id');
        $invoice = DistributorInvoiceImport::invoiceKey($distributor, $request->input('invoice', ''));
        $vendor_ref = preg_replace('/[^0-9A-Za-z]/', '', (string) $request->input('ams_ref', ''));
        $invoice_date = $request->input('invoice_date'); // YYYY-MM-DD
        $lines = (array) $request->input('lines', []);
        $force = filter_var($request->input('force', false), FILTER_VALIDATE_BOOLEAN);

        if ($location_id <= 0 || $contact_id <= 0) {
            return response()->json(['success' => false, 'msg' => 'Pick a store and a supplier first.'], 422);
        }
        if (!\App\BusinessLocation::where('business_id', $business_id)->where('id', $location_id)->exists()
            || !Contact::where('business_id', $business_id)->where('id', $contact_id)->exists()) {
            return response()->json(['success' => false, 'msg' => 'Unknown store or supplier.'], 422);
        }
        if ($invoice === '') {
            return response()->json(['success' => false, 'msg' => 'The invoice number was not read from the PDF.'], 422);
        }

        if (!$force && Storage::disk('local')->exists(DistributorInvoiceImport::sidecarPath($distributor, $invoice))) {
            $prev = json_decode(Storage::disk('local')->get(DistributorInvoiceImport::sidecarPath($distributor, $invoice)), true);
            return response()->json([
                'success' => false,
                'already_imported' => true,
                'msg' => "Invoice {$invoice} was already imported as purchase #" . ($prev['transaction_id'] ?? '?') . '. Tick "import anyway" to create it again.',
            ], 409);
        }

        $notes = $cfg['label'] . ' invoice ' . $invoice . ($vendor_ref ? (($distributor === 'ams' ? ' / AMS REF ' : ' / PO ') . $vendor_ref) : '') . ', imported from PDF';

        try {
            $res = DistributorInvoiceImport::apply($distributor, $business_id, $user_id, $location_id, $contact_id, $invoice, $invoice_date, $notes, $lines);
        } catch (\RuntimeException $e) {
            return response()->json(['success' => false, 'msg' => $e->getMessage() . ' Nothing was saved.'], 422);
        } catch (\Throwable $e) {
            \Log::emergency($cfg['label'] . ' invoice import failed: ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
            return response()->json(['success' => false, 'msg' => 'Import failed, nothing was saved: ' . $e->getMessage()], 500);
        }

        $tx = $res['transaction'];
        $newCount = count($res['created_products']);
        return response()->json([
            'success' => true,
            'transaction_id' => $tx->id,
            'lines' => $res['lines'],
            'total' => $res['total'],
            'new_products' => $newCount,
            'view_url' => action('PurchaseController@show', [$tx->id]),
            'msg' => 'Created purchase #' . $tx->id . ' with ' . $res['lines'] . ' line(s), total $' . number_format($res['total'], 2)
                . ($newCount ? ', including ' . $newCount . ' new product(s)' : '') . '.',
        ]);
    }
}
