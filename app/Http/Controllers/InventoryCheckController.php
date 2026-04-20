<?php

namespace App\Http\Controllers;

use App\BusinessLocation;
use App\Category;
use App\Contact;
use App\InventoryCheckNote;
use App\InventoryCheckSession;
use App\Services\InventoryCheckService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Response;

class InventoryCheckController extends Controller
{
    /** @var InventoryCheckService */
    protected $inventoryCheckService;

    public function __construct(InventoryCheckService $inventoryCheckService)
    {
        $this->inventoryCheckService = $inventoryCheckService;
    }

    public function index()
    {
        if (!auth()->user()->can('stock_report.view')) {
            abort(403, 'Unauthorized action.');
        }

        $business_id = request()->session()->get('user.business_id');
        $business_locations = BusinessLocation::forDropdown($business_id, true);
        $categories = Category::forDropdown($business_id, 'product');
        $suppliers = Contact::suppliersDropdown($business_id, false);

        $presets = config('inventory_check.presets', []);
        $presetOptions = ['' => __('lang_v1.none')];
        foreach ($presets as $key => $meta) {
            $presetOptions[$key] = $meta['label'] ?? $key;
        }

        $presetMeta = [];
        foreach (array_keys($presets) as $key) {
            $presetMeta[$key] = $this->inventoryCheckService->resolvePreset($business_id, $key);
        }

        $copyFormat = config('inventory_check.copy_line_format');
        $amsColumns = config('inventory_check.ams_export_columns', []);

        return view('report.inventory_check_assistant')->with(compact(
            'business_locations',
            'categories',
            'suppliers',
            'presetOptions',
            'presetMeta',
            'copyFormat',
            'amsColumns'
        ));
    }

    public function data(Request $request)
    {
        if (!auth()->user()->can('stock_report.view')) {
            abort(403, 'Unauthorized action.');
        }

        $business_id = (int) $request->session()->get('user.business_id');
        $input = $request->only([
            'location_id',
            'category_id',
            'category_ids',
            'sale_start',
            'sale_end',
            'supplier_id',
            'preset',
        ]);

        if (!empty($input['preset'])) {
            $resolved = $this->inventoryCheckService->resolvePreset($business_id, $input['preset']);
            $input = array_merge($resolved, $input);
        }

        if (!empty($input['category_ids']) && is_string($input['category_ids'])) {
            $input['category_ids'] = array_filter(array_map('intval', explode(',', $input['category_ids'])));
        }

        $permitted = auth()->user()->permitted_locations();
        $result = $this->inventoryCheckService->buildCandidates($business_id, $input, $permitted);

        return response()->json($result);
    }

    public function export(Request $request)
    {
        if (!auth()->user()->can('stock_report.view')) {
            abort(403, 'Unauthorized action.');
        }

        $business_id = (int) $request->session()->get('user.business_id');
        $input = $request->only([
            'location_id',
            'category_id',
            'category_ids',
            'sale_start',
            'sale_end',
            'supplier_id',
            'preset',
        ]);

        if (!empty($input['preset'])) {
            $resolved = $this->inventoryCheckService->resolvePreset($business_id, $input['preset']);
            $input = array_merge($resolved, $input);
        }

        if (!empty($input['category_ids']) && is_string($input['category_ids'])) {
            $input['category_ids'] = array_filter(array_map('intval', explode(',', $input['category_ids'])));
        }

        $permitted = auth()->user()->permitted_locations();
        $result = $this->inventoryCheckService->buildCandidates($business_id, $input, $permitted);
        $rows = $result['candidates'] ?? [];

        $columns = config('inventory_check.ams_export_columns', [
            'sku', 'product', 'artist', 'format', 'location', 'current_stock', 'suggested_qty', 'source_tags', 'reason',
        ]);

        $self = $this;
        $callback = function () use ($rows, $columns, $self) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, $columns);
            foreach ($rows as $r) {
                $line = [];
                foreach ($columns as $col) {
                    $line[] = $self->exportColumnValue($col, $r);
                }
                fputcsv($out, $line);
            }
            fclose($out);
        };

        $filename = 'inventory_check_order_' . Carbon::now()->format('Y-m-d_His') . '.csv';

        return Response::stream($callback, 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="' . $filename . '"',
        ]);
    }

    protected function exportColumnValue(string $col, array $r): string
    {
        switch ($col) {
            case 'sku':
                return (string) ($r['sku'] ?? '');
            case 'product':
                return (string) ($r['product'] ?? '');
            case 'artist':
                return (string) ($r['artist'] ?? '');
            case 'format':
                return (string) ($r['format'] ?? '');
            case 'location':
                return (string) ($r['location_name'] ?? '');
            case 'category':
                return (string) ($r['category_name'] ?? '');
            case 'current_stock':
                return (string) ($r['stock'] ?? '');
            case 'sold_qty_window':
                return (string) ($r['sold_qty_window'] ?? '');
            case 'avg_sell_days':
                return isset($r['avg_sell_days']) ? (string) $r['avg_sell_days'] : '';
            case 'suggested_qty':
                return (string) ($r['suggested_qty'] ?? '');
            case 'source_tags':
                return isset($r['tags']) ? implode('|', $r['tags']) : '';
            case 'reason':
                return isset($r['reasons']) ? implode('; ', $r['reasons']) : '';
            case 'variation':
                return (string) ($r['variation_label'] ?? '');
            default:
                return '';
        }
    }

    public function listNotes(Request $request)
    {
        if (!auth()->user()->can('stock_report.view')) {
            abort(403, 'Unauthorized action.');
        }

        $business_id = (int) $request->session()->get('user.business_id');
        $q = InventoryCheckNote::where('business_id', $business_id)
            ->orderByDesc('id')
            ->limit(100);

        if ($request->filled('location_id')) {
            $q->where('location_id', (int) $request->location_id);
        }
        if ($request->filled('note_type')) {
            $q->where('note_type', $request->note_type);
        }

        return response()->json(['data' => $q->get()]);
    }

    public function storeNote(Request $request)
    {
        if (!auth()->user()->can('stock_report.view')) {
            abort(403, 'Unauthorized action.');
        }

        $request->validate([
            'note_type' => 'required|in:street_pulse,customer_request',
            'body' => 'required|string|max:20000',
            'location_id' => 'nullable|integer',
            'reference_date' => 'nullable|date',
            'product_id' => 'nullable|integer',
            'variation_id' => 'nullable|integer',
        ]);

        $business_id = (int) $request->session()->get('user.business_id');
        $note = InventoryCheckNote::create([
            'business_id' => $business_id,
            'location_id' => $request->input('location_id'),
            'note_type' => $request->input('note_type'),
            'body' => $request->input('body'),
            'reference_date' => $request->input('reference_date'),
            'product_id' => $request->input('product_id'),
            'variation_id' => $request->input('variation_id'),
            'created_by' => auth()->id(),
        ]);

        return response()->json(['success' => true, 'note' => $note]);
    }

    public function destroyNote($id)
    {
        if (!auth()->user()->can('stock_report.view')) {
            abort(403, 'Unauthorized action.');
        }

        $business_id = (int) request()->session()->get('user.business_id');
        $note = InventoryCheckNote::where('business_id', $business_id)->where('id', (int) $id)->firstOrFail();
        $note->delete();

        return response()->json(['success' => true]);
    }

    public function listSessions(Request $request)
    {
        if (!auth()->user()->can('stock_report.view')) {
            abort(403, 'Unauthorized action.');
        }

        $business_id = (int) $request->session()->get('user.business_id');
        $sessions = InventoryCheckSession::where('business_id', $business_id)
            ->where('user_id', auth()->id())
            ->orderByDesc('updated_at')
            ->limit(50)
            ->get();

        return response()->json(['data' => $sessions]);
    }

    public function storeSession(Request $request)
    {
        if (!auth()->user()->can('stock_report.view')) {
            abort(403, 'Unauthorized action.');
        }

        $request->validate([
            'name' => 'required|string|max:191',
            'location_id' => 'nullable|integer',
            'category_id' => 'nullable|integer',
            'supplier_id' => 'nullable|integer',
            'sale_start' => 'nullable|date',
            'sale_end' => 'nullable|date',
            'preset_key' => 'nullable|string|max:64',
            'state_json' => 'nullable|string',
        ]);

        $business_id = (int) $request->session()->get('user.business_id');

        $session = InventoryCheckSession::create([
            'business_id' => $business_id,
            'user_id' => auth()->id(),
            'name' => $request->input('name'),
            'location_id' => $request->input('location_id'),
            'category_id' => $request->input('category_id'),
            'supplier_id' => $request->input('supplier_id'),
            'sale_start' => $request->input('sale_start'),
            'sale_end' => $request->input('sale_end'),
            'preset_key' => $request->input('preset_key'),
            'state_json' => $request->input('state_json'),
        ]);

        return response()->json(['success' => true, 'session' => $session]);
    }

    public function updateSession(Request $request, $id)
    {
        if (!auth()->user()->can('stock_report.view')) {
            abort(403, 'Unauthorized action.');
        }

        $business_id = (int) $request->session()->get('user.business_id');
        $session = InventoryCheckSession::where('business_id', $business_id)
            ->where('user_id', auth()->id())
            ->where('id', (int) $id)
            ->firstOrFail();

        $request->validate([
            'name' => 'sometimes|string|max:191',
            'state_json' => 'nullable|string',
            'location_id' => 'nullable|integer',
            'category_id' => 'nullable|integer',
            'supplier_id' => 'nullable|integer',
            'sale_start' => 'nullable|date',
            'sale_end' => 'nullable|date',
            'preset_key' => 'nullable|string|max:64',
        ]);

        $session->fill($request->only([
            'name', 'state_json', 'location_id', 'category_id', 'supplier_id',
            'sale_start', 'sale_end', 'preset_key',
        ]));
        $session->save();

        return response()->json(['success' => true, 'session' => $session]);
    }

    public function destroySession($id)
    {
        if (!auth()->user()->can('stock_report.view')) {
            abort(403, 'Unauthorized action.');
        }

        $business_id = (int) request()->session()->get('user.business_id');
        $session = InventoryCheckSession::where('business_id', $business_id)
            ->where('user_id', auth()->id())
            ->where('id', (int) $id)
            ->firstOrFail();
        $session->delete();

        return response()->json(['success' => true]);
    }
}
