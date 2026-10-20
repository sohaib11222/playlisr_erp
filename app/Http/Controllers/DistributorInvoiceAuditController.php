<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

// Distributor Invoice Audit (/reports/distributor-invoice-audit).
//
// Every distributor invoice from the last N days against the ERP purchase it
// landed on, so nothing in a box arrives without a purchase line to receive.
//
// Invoices come from two places:
//   - the invoice importers' sidecars (ams-imports/, alliance-imports/), and
//   - a registry (distributor-invoices/registry.json) of invoices we know were
//     sent (Alliance e-invoice emails, the AMS portal list, Redeye/Monostereo
//     confirmations). Rows can carry the invoice lines (UPC + qty) and the ids
//     of purchases that already cover them (orders logged before the invoice).
// An invoice with no purchase is a GAP; one whose lines aren't all on its
// purchases is flagged with the missing lines. Distributor purchases with no
// invoice behind them (entered ad hoc at the store) are listed underneath.
class DistributorInvoiceAuditController extends Controller
{
    const REGISTRY = 'distributor-invoices/registry.json';

    // Supplier contacts that are distributors (name match, case-insensitive).
    const DISTRIBUTOR_SUPPLIERS = [
        'ams' => ['AMS', 'All Media Supply'],
        'alliance' => ['Alliance Entertainment'],
        'redeye' => ['Redeye Worldwide'],
        'monostereo' => ['Monostereo Wholesale Vinyl Record', 'Monostereo'],
        'deejay' => ['deejay vinylfuture'],
        'matador' => ['Matador Records', 'Matador Direct'],
        'secretly' => ['secretly distribution'],
        'cobraside' => ['cobraside distribution'],
    ];

    private function canAccess()
    {
        return auth()->user()->can('purchase.view') || auth()->user()->can('purchase.create')
            || auth()->user()->can('purchase_order.create');
    }

    private function loadRegistry()
    {
        if (!Storage::disk('local')->exists(self::REGISTRY)) return [];
        return json_decode(Storage::disk('local')->get(self::REGISTRY), true) ?: [];
    }

    private function supplierIds($business_id)
    {
        $out = [];
        foreach (self::DISTRIBUTOR_SUPPLIERS as $key => $names) {
            $ids = DB::table('contacts')->where('business_id', $business_id)->where('type', '!=', 'customer')
                ->where(function ($q) use ($names) {
                    foreach ($names as $n) $q->orWhere('name', $n)->orWhere('supplier_business_name', $n);
                })->pluck('id')->all();
            foreach ($ids as $id) $out[(int) $id] = $key;
        }
        return $out; // contact id => distributor key
    }

    public function index(Request $request)
    {
        if (!$this->canAccess()) abort(403, 'Unauthorized action.');
        $business_id = $request->session()->get('user.business_id');
        $days = max(1, min(365, (int) $request->input('days', 30)));
        $since = now()->subDays($days)->toDateString();

        $locations = DB::table('business_locations')->where('business_id', $business_id)->pluck('name', 'id')->all();
        $locByName = array_change_key_case(array_flip($locations), CASE_LOWER);
        $suppliers = $this->supplierIds($business_id);

        // 1. Collect invoices: registry + importer sidecars.
        $invoices = [];
        foreach ($this->loadRegistry() as $k => $r) {
            $invoices[$k] = $r + ['source' => $r['source'] ?? 'registry'];
        }
        foreach (['ams' => 'ams-imports', 'alliance' => 'alliance-imports'] as $dist => $dir) {
            foreach (Storage::disk('local')->files($dir) as $f) {
                $j = json_decode(Storage::disk('local')->get($f), true) ?: [];
                $inv = $j['invoice'] ?? basename($f, '.json');
                if (!preg_match('/^[A-Z]*\d{5,}$/', (string) $inv)) continue; // skip test sidecars "1.json"
                $k = $dist . ':' . $inv;
                $invoices[$k] = array_merge([
                    'distributor' => $dist, 'invoice' => $inv, 'date' => $j['invoice_date'] ?? null,
                    'total' => $j['total'] ?? null, 'source' => 'imported',
                ], $invoices[$k] ?? [], ['import_tx' => $j['transaction_id'] ?? null]);
            }
        }

        // Purchases those invoices point at.
        $txIds = [];
        foreach ($invoices as $r) {
            if (!empty($r['import_tx'])) $txIds[] = (int) $r['import_tx'];
            foreach ($r['purchases'] ?? [] as $pid) $txIds[] = (int) $pid;
        }
        $txs = $txIds ? DB::table('transactions')->where('business_id', $business_id)->whereIn('id', array_unique($txIds))
            ->get(['id', 'ref_no', 'status', 'transaction_date', 'final_total', 'location_id', 'contact_id'])->keyBy('id') : collect();

        $rows = [];
        $linkedTx = [];
        foreach ($invoices as $k => $r) {
            $tx = [];
            if (!empty($r['import_tx']) && isset($txs[(int) $r['import_tx']])) $tx[] = $txs[(int) $r['import_tx']];
            foreach ($r['purchases'] ?? [] as $pid) if (isset($txs[(int) $pid])) $tx[] = $txs[(int) $pid];
            // Fall back to the invoice number written on a purchase's ref/notes.
            if (!$tx && !empty($r['invoice'])) {
                $inv = (string) $r['invoice'];
                $found = DB::table('transactions')->where('business_id', $business_id)->where('type', 'purchase')
                    ->where(function ($q) use ($inv) {
                        $q->where('ref_no', 'like', '%' . $inv . '%')->orWhere('additional_notes', 'like', '%' . $inv . '%');
                    })->get(['id', 'ref_no', 'status', 'transaction_date', 'final_total', 'location_id', 'contact_id']);
                foreach ($found as $f) $tx[] = $f;
            }
            $date = $r['date'] ?? null;
            if (!$date && $tx) $date = substr($tx[0]->transaction_date, 0, 10);
            if ($date && $date < $since) continue;

            $tx = collect($tx)->unique('id')->values();
            foreach ($tx as $t) $linkedTx[(int) $t->id] = true;

            // Line check: invoice lines (when the registry has them) vs the
            // lines on its purchases, by UPC (any leading-zero form).
            $missing = [];
            if (!empty($r['lines']) && $tx->count()) {
                $have = [];
                foreach (DB::table('purchase_lines as pl')->join('variations as v', 'v.id', '=', 'pl.variation_id')
                    ->whereIn('pl.transaction_id', $tx->pluck('id')->all())
                    ->get(['v.sub_sku', 'pl.quantity']) as $pl) {
                    $key = ltrim(preg_replace('/\D/', '', (string) $pl->sub_sku), '0');
                    $have[$key] = ($have[$key] ?? 0) + (float) $pl->quantity;
                }
                foreach ($r['lines'] as $l) {
                    $key = ltrim(preg_replace('/\D/', '', (string) ($l['upc'] ?? '')), '0');
                    $need = (float) ($l['qty'] ?? 0);
                    $got = $have[$key] ?? 0;
                    if ($got + 0.001 < $need) {
                        $missing[] = ['upc' => $l['upc'], 'desc' => $l['desc'] ?? '', 'need' => $need, 'got' => $got];
                    }
                    if (isset($have[$key])) $have[$key] = max(0, $have[$key] - $need);
                }
            }

            $erpTotal = round($tx->sum(function ($t) { return (float) $t->final_total; }), 2);
            $invTotal = isset($r['total']) && $r['total'] !== null ? round((float) $r['total'], 2) : null;
            if (!$tx->count()) $flag = 'gap';
            elseif ($missing) $flag = 'missing_lines';
            // Totals only mean something when the invoice has its own purchase;
            // with registered lines the line check above is the real test.
            elseif (empty($r['lines']) && $tx->count() === 1 && $invTotal !== null && abs($erpTotal - $invTotal) > 1.00) $flag = 'total_differs';
            else $flag = 'ok';

            $store = $r['store'] ?? null;
            if (!$store && $tx->count()) $store = $locations[$tx[0]->location_id] ?? null;

            $rows[] = [
                'distributor' => $r['distributor'] ?? explode(':', $k)[0],
                'invoice' => $r['invoice'] ?? $k,
                'date' => $date,
                'store' => $store,
                'total' => $invTotal,
                'source' => $r['source'] ?? '',
                'note' => $r['note'] ?? '',
                'tx' => $tx,
                'erp_total' => $erpTotal,
                'missing' => $missing,
                'flag' => $flag,
            ];
        }
        usort($rows, function ($a, $b) {
            $order = ['gap' => 0, 'missing_lines' => 1, 'total_differs' => 2, 'ok' => 3];
            return [$order[$a['flag']], $b['date']] <=> [$order[$b['flag']], $a['date']];
        });

        // 2. Distributor purchases in the window with no invoice behind them.
        $adhoc = [];
        if ($suppliers) {
            $q = DB::table('transactions as t')
                ->leftJoin('users as u', 'u.id', '=', 't.created_by')
                ->where('t.business_id', $business_id)->where('t.type', 'purchase')
                ->whereIn('t.contact_id', array_keys($suppliers))
                ->where('t.transaction_date', '>=', $since)
                ->orderByDesc('t.transaction_date')
                ->get(['t.id', 't.ref_no', 't.status', 't.transaction_date', 't.final_total', 't.location_id', 't.contact_id',
                       DB::raw("TRIM(CONCAT(COALESCE(u.first_name,''),' ',COALESCE(u.last_name,''))) as by_name")]);
            $counts = DB::table('purchase_lines')->whereIn('transaction_id', $q->pluck('id')->all() ?: [0])
                ->groupBy('transaction_id')->select('transaction_id', DB::raw('COUNT(*) as n'), DB::raw('SUM(quantity) as qty'))
                ->get()->keyBy('transaction_id');
            foreach ($q as $t) {
                if (isset($linkedTx[(int) $t->id])) continue;
                $adhoc[] = [
                    'tx' => $t,
                    'distributor' => $suppliers[(int) $t->contact_id] ?? '',
                    'store' => $locations[$t->location_id] ?? '',
                    'lines' => (int) ($counts[$t->id]->n ?? 0),
                    'qty' => (float) ($counts[$t->id]->qty ?? 0),
                ];
            }
        }

        $summary = [];
        foreach ($rows as $r) {
            $s = &$summary[$r['distributor']];
            $s['invoices'] = ($s['invoices'] ?? 0) + 1;
            $s['gaps'] = ($s['gaps'] ?? 0) + ($r['flag'] === 'gap' ? 1 : 0);
            $s['flagged'] = ($s['flagged'] ?? 0) + (in_array($r['flag'], ['missing_lines', 'total_differs'], true) ? 1 : 0);
            unset($s);
        }
        foreach ($adhoc as $a) {
            $s = &$summary[$a['distributor']];
            $s['adhoc'] = ($s['adhoc'] ?? 0) + 1;
            unset($s);
        }

        return view('report.distributor_invoice_audit')->with(compact('rows', 'adhoc', 'summary', 'days', 'locations'));
    }

    // Add/refresh invoices in the registry. Accepts JSON {rows:[{distributor,
    // invoice, date, store, total, lines:[{upc, qty, desc}], purchases:[ids],
    // note, source}]} or a pasted "distributor,invoice,date,store,total" list.
    // Merge only (never drops an invoice); the previous file is kept as .bak.
    public function register(Request $request)
    {
        if (!(auth()->user()->can('purchase.create') || auth()->user()->can('purchase_order.create'))) {
            abort(403, 'Unauthorized action.');
        }
        $in = $request->input('rows');
        if (!is_array($in)) {
            $in = [];
            foreach (preg_split('/\r?\n/', (string) $request->input('paste', '')) as $line) {
                $c = array_map('trim', str_getcsv($line));
                if (count($c) < 2 || $c[1] === '') continue;
                $in[] = ['distributor' => $c[0], 'invoice' => $c[1], 'date' => $c[2] ?? null, 'store' => $c[3] ?? null,
                         'total' => isset($c[4]) && $c[4] !== '' ? (float) str_replace([',', '$'], '', $c[4]) : null, 'source' => 'pasted'];
            }
        }
        $reg = $this->loadRegistry();
        if (Storage::disk('local')->exists(self::REGISTRY)) {
            Storage::disk('local')->put(self::REGISTRY . '.bak', Storage::disk('local')->get(self::REGISTRY));
        }
        $n = 0;
        foreach ($in as $r) {
            $dist = strtolower(preg_replace('/[^a-z]/i', '', (string) ($r['distributor'] ?? '')));
            $inv = strtoupper(preg_replace('/[^A-Za-z0-9\-]/', '', (string) ($r['invoice'] ?? '')));
            if ($dist === '' || $inv === '') continue;
            $date = (string) ($r['date'] ?? '');
            if ($date !== '' && ($ts = strtotime($date))) $date = date('Y-m-d', $ts); else $date = null;
            $k = $dist . ':' . $inv;
            $row = array_filter([
                'distributor' => $dist,
                'invoice' => $inv,
                'date' => $date,
                'store' => !empty($r['store']) ? strtolower((string) $r['store']) : null,
                'total' => isset($r['total']) && $r['total'] !== null && $r['total'] !== '' ? round((float) $r['total'], 2) : null,
                'lines' => isset($r['lines']) && is_array($r['lines']) ? array_values(array_map(function ($l) {
                    return ['upc' => preg_replace('/\D/', '', (string) ($l['upc'] ?? '')), 'qty' => (float) ($l['qty'] ?? 0), 'desc' => substr((string) ($l['desc'] ?? ''), 0, 80)];
                }, $r['lines'])) : null,
                'purchases' => isset($r['purchases']) && is_array($r['purchases']) ? array_values(array_map('intval', $r['purchases'])) : null,
                'note' => isset($r['note']) ? substr((string) $r['note'], 0, 300) : null,
                'source' => $r['source'] ?? 'registry',
            ], function ($v) { return $v !== null; });
            $reg[$k] = array_merge($reg[$k] ?? [], $row, ['updated_at' => now()->toDateTimeString()]);
            $n++;
        }
        Storage::disk('local')->put(self::REGISTRY, json_encode($reg, JSON_PRETTY_PRINT));

        if ($request->wantsJson() || $request->ajax()) {
            return response()->json(['success' => true, 'registered' => $n, 'total' => count($reg)]);
        }
        return redirect('/reports/distributor-invoice-audit')->with('status', ['success' => 1, 'msg' => "Registered {$n} invoice(s)."]);
    }
}
