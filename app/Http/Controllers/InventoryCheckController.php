<?php

namespace App\Http\Controllers;

use App\BusinessLocation;
use App\Category;
use App\ChartPick;
use App\ChartPickImport;
use App\Contact;
use App\CustomerWant;
use App\InventoryCheckNote;
use App\InventoryCheckSession;
use App\Services\ChartPickParser;
use App\Services\InventoryCheckService;
use App\Services\TabularChartParser;
use App\Services\UniversalChartParser;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Response;
use Illuminate\Support\Facades\Schema;

class InventoryCheckController extends Controller
{
    /** @var InventoryCheckService */
    protected $inventoryCheckService;

    /** @var ChartPickParser */
    protected $chartPickParser;

    public function __construct(InventoryCheckService $inventoryCheckService, ChartPickParser $chartPickParser)
    {
        $this->inventoryCheckService = $inventoryCheckService;
        $this->chartPickParser = $chartPickParser;
    }

    public function index()
    {
        // Open to all authenticated staff — inventory check assistant is
        // operational reorder data, not aggregated sales (Sarah 2026-04-28).

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

        // Freshness check for the pasted charts — surface "last imported" dates.
        // Guarded: tables may not exist if migrations haven't run yet on this deploy.
        $chartFreshness = [];
        $migrationsMissing = false;
        if (Schema::hasTable('chart_pick_imports')) {
            $chartFreshness = ChartPickImport::where('business_id', $business_id)
                ->selectRaw('source, MAX(week_of) as week_of, MAX(created_at) as imported_at')
                ->groupBy('source')
                ->get()
                ->keyBy('source')
                ->toArray();
        } else {
            $migrationsMissing = true;
        }

        return view('report.inventory_check_assistant')->with(compact(
            'business_locations',
            'categories',
            'suppliers',
            'presetOptions',
            'presetMeta',
            'copyFormat',
            'amsColumns',
            'chartFreshness',
            'migrationsMissing'
        ));
    }

    /**
     * Bucketed candidate data — the "Order for this week" view.
     */
    public function buckets(Request $request)
    {
        // Open to all authenticated staff — inventory check assistant is
        // operational reorder data, not aggregated sales (Sarah 2026-04-28).

        try {
            $business_id = (int) $request->session()->get('user.business_id');
            $input = $request->only([
                'location_id', 'category_id', 'category_ids', 'preset',
            ]);

            if (!empty($input['preset'])) {
                $resolved = $this->inventoryCheckService->resolvePreset($business_id, $input['preset']);
                $input = array_merge($resolved, $input);
            }

            if (!empty($input['category_ids']) && is_string($input['category_ids'])) {
                $input['category_ids'] = array_filter(array_map('intval', explode(',', $input['category_ids'])));
            }

            $permitted = auth()->user()->permitted_locations();
            $result = $this->inventoryCheckService->buildBuckets($business_id, $input, $permitted);

            return response()->json($result);
        } catch (\Throwable $e) {
            // Don't let a buildBuckets exception become a Laravel HTML
            // error page — it'd render as the misleading "Server returned
            // no buckets" empty state. Instead surface the exact reason
            // back to the JS so the page can show it.
            \Illuminate\Support\Facades\Log::error('ICA buckets build failed', [
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);
            return response()->json([
                'buckets' => [],
                'meta' => [
                    'error' => 'build_failed',
                    'message' => $e->getMessage(),
                    'file' => basename($e->getFile()) . ':' . $e->getLine(),
                ],
            ], 200);
        }
    }

    public function export(Request $request)
    {
        // Open to all authenticated staff — inventory check assistant is
        // operational reorder data, not aggregated sales (Sarah 2026-04-28).

        $business_id = (int) $request->session()->get('user.business_id');
        $input = $request->only([
            'location_id', 'category_id', 'category_ids', 'preset',
        ]);

        if (!empty($input['preset'])) {
            $resolved = $this->inventoryCheckService->resolvePreset($business_id, $input['preset']);
            $input = array_merge($resolved, $input);
        }

        if (!empty($input['category_ids']) && is_string($input['category_ids'])) {
            $input['category_ids'] = array_filter(array_map('intval', explode(',', $input['category_ids'])));
        }

        $permitted = auth()->user()->permitted_locations();
        $result = $this->inventoryCheckService->buildBuckets($business_id, $input, $permitted);

        $columns = config('inventory_check.ams_export_columns', []);
        $self = $this;

        $callback = function () use ($result, $columns, $self) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, $columns);
            foreach ($result['buckets'] as $key => $bucket) {
                foreach ($bucket['items'] as $r) {
                    $r['bucket'] = $key;
                    $line = [];
                    foreach ($columns as $col) {
                        $line[] = $self->exportColumnValue($col, $r);
                    }
                    fputcsv($out, $line);
                }
            }
            fclose($out);
        };

        $filename = 'order_for_this_week_' . Carbon::now()->format('Y-m-d_His') . '.csv';

        return Response::stream($callback, 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="' . $filename . '"',
        ]);
    }

    protected function exportColumnValue(string $col, array $r): string
    {
        switch ($col) {
            case 'bucket': return (string) ($r['bucket'] ?? '');
            case 'sku': return (string) ($r['sku'] ?? '');
            case 'product': return (string) ($r['product'] ?? '');
            case 'artist': return (string) ($r['artist'] ?? '');
            case 'format': return (string) ($r['format'] ?? '');
            case 'location': return (string) ($r['location_name'] ?? '');
            case 'category': return (string) ($r['category_name'] ?? '');
            case 'current_stock': return (string) ($r['stock'] ?? '');
            case 'sold_qty_window': return (string) ($r['sold_qty_window'] ?? '');
            case 'avg_sell_days': return isset($r['avg_sell_days']) ? (string) $r['avg_sell_days'] : '';
            case 'suggested_qty': return (string) ($r['suggested_qty'] ?? '');
            case 'source_tags': return isset($r['tags']) ? implode('|', $r['tags']) : '';
            case 'reason': return (string) ($r['reason'] ?? '');
            case 'variation': return (string) ($r['variation_label'] ?? '');
            default: return '';
        }
    }

    // ── Chart paste imports (Street Pulse / Universal Top) ────────────

    public function importChart(Request $request)
    {
        // Open to all authenticated staff — inventory check assistant is
        // operational reorder data, not aggregated sales (Sarah 2026-04-28).

        // Accept EITHER a pasted body OR an uploaded chart file (xlsx, csv, tsv).
        // Sarah's actual sources don't fit a single textarea: Universal sends
        // an xlsx attachment; Luminate / Street Pulse exports as a tabular
        // chart. Validate one-or-the-other manually so the failure path is
        // a clean JSON 422 (Laravel's validate() helper sometimes redirects
        // with HTML on this older version, which the JS choked on with
        // "Unexpected token '<'").
        $validator = \Illuminate\Support\Facades\Validator::make($request->all(), [
            'source' => 'required|in:street_pulse,universal_top',
            'body' => 'nullable|string|max:500000',
            'week_of' => 'nullable|date',
            'chart_file' => 'nullable|file|max:20480',
        ]);
        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'error' => 'validation_failed',
                'message' => implode(' ', $validator->errors()->all()),
            ], 422);
        }
        if (!$request->filled('body') && !$request->hasFile('chart_file')) {
            return response()->json([
                'success' => false,
                'error' => 'no_input',
                'message' => 'Paste the chart body or upload an .xlsx / .csv file.',
            ], 422);
        }
        // Reject images cleanly (OCR is supposed to happen client-side)
        if ($request->hasFile('chart_file')) {
            $ext = strtolower($request->file('chart_file')->getClientOriginalExtension());
            $allowed = ['xlsx', 'xls', 'csv', 'tsv', 'txt'];
            if (!in_array($ext, $allowed, true)) {
                return response()->json([
                    'success' => false,
                    'error' => 'unsupported_file_type',
                    'message' => 'For image files (.png/.jpg), the browser OCR fills the paste box automatically — wait for "✓ Extracted N rows" before clicking Import. For other files use ' . implode(', ', $allowed) . '.',
                ], 422);
            }
        }

        if (!Schema::hasTable('chart_pick_imports') || !Schema::hasTable('chart_picks')) {
            return response()->json([
                'success' => false,
                'error' => 'migrations_missing',
                'message' => 'chart_picks tables not yet created on this server. Run "php artisan migrate" and try again.',
            ], 503);
        }

        $business_id = (int) $request->session()->get('user.business_id');
        $source = $request->input('source');
        $weekOf = $request->input('week_of') ?: Carbon::now()->format('Y-m-d');
        $body = (string) $request->input('body', '');

        $rows = [];
        $rawForAudit = $body;
        $diagnostic = ['mode' => null, 'filename' => null];

        if ($request->hasFile('chart_file')) {
            $file = $request->file('chart_file');
            $filename = $file->getClientOriginalName();
            $diagnostic['mode'] = 'file';
            $diagnostic['filename'] = $filename;
            $rawForAudit = '[file: ' . $filename . ']';

            $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));

            // Universal-Top xlsx has a known multi-sheet layout (Top 200 +
            // deliveries) that the dedicated UMe parser handles best.
            if ($source === 'universal_top' && in_array($ext, ['xlsx', 'xls'], true)) {
                $rows = $this->parseUniversalXlsx($file->getRealPath());
            } else {
                $rows = app(TabularChartParser::class)->parseFile($file->getRealPath(), $filename);
            }
        } else {
            $diagnostic['mode'] = 'paste';
            $rows = $this->chartPickParser->parse($body, $source);
            // Some pasted bodies are tab-separated tables (Luminate copy-paste).
            // If the line parser found nothing, retry through the tabular
            // parser using the body as a CSV/TSV blob.
            if (empty($rows)) {
                $tmp = tempnam(sys_get_temp_dir(), 'chartpaste');
                file_put_contents($tmp, $body);
                try {
                    $rows = app(TabularChartParser::class)->parseCsv($tmp);
                } finally {
                    @unlink($tmp);
                }
            }
        }

        if (empty($rows)) {
            return response()->json([
                'success' => false,
                'error' => 'no_rows_parsed',
                'message' => 'Could not find Artist + Title columns in the input. For xlsx/csv files make sure they have headers like "Artist" and "Title" (or "ARTIST NAME" / "Title"). For paste, format each line as "Artist — Title — Format".',
                'diagnostic' => $diagnostic,
            ], 422);
        }

        return DB::transaction(function () use ($business_id, $source, $weekOf, $rawForAudit, $rows, $diagnostic) {
            $import = ChartPickImport::create([
                'business_id' => $business_id,
                'source' => $source,
                'week_of' => $weekOf,
                'imported_by' => auth()->id(),
                'row_count' => count($rows),
                'raw_body' => mb_substr($rawForAudit, 0, 65535),
            ]);

            // Replace any existing picks for this source+week (idempotent re-paste / re-upload)
            ChartPick::where('business_id', $business_id)
                ->where('source', $source)
                ->whereDate('week_of', $weekOf)
                ->delete();

            foreach ($rows as $row) {
                ChartPick::create([
                    'import_id' => $import->id,
                    'business_id' => $business_id,
                    'source' => $source,
                    'week_of' => $weekOf,
                    'chart_rank' => $row['rank'] ?? null,
                    'artist' => $row['artist'] ?? null,
                    'title' => $row['title'] ?? null,
                    'format' => $row['format'] ?? null,
                    'is_new_release' => !empty($row['is_new_release']),
                ]);
            }

            return response()->json([
                'success' => true,
                'source' => $source,
                'week_of' => $weekOf,
                'parsed_rows' => count($rows),
                'import_id' => $import->id,
                'diagnostic' => $diagnostic,
            ]);
        });
    }

    /**
     * UMe Universal xlsx → flat row list. Pulls Top 200 (vinyl + CD) and
     * this-week deliveries; deliveries get is_new_release=true.
     */
    protected function parseUniversalXlsx(string $path): array
    {
        $parser = app(UniversalChartParser::class);
        $parsed = $parser->parse($path);
        $rows = [];
        foreach ($parsed['top_200_vinyl'] as $r) {
            $rows[] = array_merge($r, ['is_new_release' => false]);
        }
        foreach ($parsed['top_200_cd'] as $r) {
            $rows[] = array_merge($r, ['is_new_release' => false]);
        }
        foreach ($parsed['deliveries_vinyl'] as $r) {
            $rows[] = array_merge($r, ['is_new_release' => true]);
        }
        foreach ($parsed['deliveries_cd'] as $r) {
            $rows[] = array_merge($r, ['is_new_release' => true]);
        }
        return $rows;
    }

    public function latestChart(Request $request, string $source)
    {
        // Open to all authenticated staff — inventory check assistant is
        // operational reorder data, not aggregated sales (Sarah 2026-04-28).
        if (!in_array($source, ['street_pulse', 'universal_top'], true)) {
            abort(404);
        }

        $business_id = (int) $request->session()->get('user.business_id');
        $import = ChartPickImport::where('business_id', $business_id)
            ->where('source', $source)
            ->orderByDesc('week_of')
            ->first();

        return response()->json([
            'import' => $import,
            'row_count' => $import ? ChartPick::where('import_id', $import->id)->count() : 0,
        ]);
    }

    // ── Run the email-import command from the browser ─────────────────

    public function runEmailImport(Request $request)
    {
        // Open to all authenticated staff — inventory check assistant is
        // operational reorder data, not aggregated sales (Sarah 2026-04-28).

        // Request::boolean() doesn't exist on this Laravel version — use
        // filter_var. Without this fix, the button silently 500s (this is
        // why "the apple report wont fetch" looked broken).
        $dryRun = filter_var($request->input('dry_run'), FILTER_VALIDATE_BOOLEAN);
        $since = max(1, (int) $request->input('since', 7));
        $businessId = (int) $request->session()->get('user.business_id');

        $args = [
            '--since' => $since,
            '--business-id' => $businessId,
        ];
        if ($dryRun) {
            $args['--dry-run'] = true;
        }

        try {
            $exit = Artisan::call('charts:import-from-email', $args);
            $output = Artisan::output();
        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'error' => $e->getMessage(),
            ], 500);
        }

        return response()->json([
            'success' => $exit === 0,
            'exit_code' => $exit,
            'dry_run' => $dryRun,
            'output' => $output,
        ]);
    }

    public function runAppleMusicImport(Request $request)
    {
        // Open to all authenticated staff — inventory check assistant is
        // operational reorder data, not aggregated sales (Sarah 2026-04-28).

        $dryRun = filter_var($request->input('dry_run'), FILTER_VALIDATE_BOOLEAN);
        $businessId = (int) $request->session()->get('user.business_id');

        $args = ['--business-id' => $businessId];
        if ($dryRun) {
            $args['--dry-run'] = true;
        }

        try {
            $exit = Artisan::call('charts:import-apple-music', $args);
            $output = Artisan::output();
        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'error' => $e->getMessage(),
            ], 500);
        }

        return response()->json([
            'success' => $exit === 0,
            'exit_code' => $exit,
            'dry_run' => $dryRun,
            'output' => $output,
        ]);
    }

    // ── Customer Wants fulfillment from the ICA view ──────────────────

    public function fulfillCustomerWant(Request $request, $id)
    {
        // Open to all authenticated staff — inventory check assistant is
        // operational reorder data, not aggregated sales (Sarah 2026-04-28).

        $business_id = (int) $request->session()->get('user.business_id');
        $want = CustomerWant::where('business_id', $business_id)
            ->where('id', (int) $id)
            ->firstOrFail();

        $want->status = 'fulfilled';
        $want->fulfilled_by = auth()->id();
        $want->fulfilled_at = Carbon::now();
        $want->fulfilled_note = $request->input('note') ?: 'marked via Inventory Check Assistant';
        $want->save();

        return response()->json(['success' => true, 'customer_want' => $want]);
    }

    // ── Notes (Street Pulse annotations / one-off customer-request notes) ──

    public function listNotes(Request $request)
    {
        // Open to all authenticated staff — inventory check assistant is
        // operational reorder data, not aggregated sales (Sarah 2026-04-28).

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
        // Open to all authenticated staff — inventory check assistant is
        // operational reorder data, not aggregated sales (Sarah 2026-04-28).

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
        // Open to all authenticated staff — inventory check assistant is
        // operational reorder data, not aggregated sales (Sarah 2026-04-28).

        $business_id = (int) request()->session()->get('user.business_id');
        $note = InventoryCheckNote::where('business_id', $business_id)->where('id', (int) $id)->firstOrFail();
        $note->delete();

        return response()->json(['success' => true]);
    }

    // ── Saved sessions ────────────────────────────────────────────────

    public function listSessions(Request $request)
    {
        // Open to all authenticated staff — inventory check assistant is
        // operational reorder data, not aggregated sales (Sarah 2026-04-28).

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
        // Open to all authenticated staff — inventory check assistant is
        // operational reorder data, not aggregated sales (Sarah 2026-04-28).

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
        // Open to all authenticated staff — inventory check assistant is
        // operational reorder data, not aggregated sales (Sarah 2026-04-28).

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
        // Open to all authenticated staff — inventory check assistant is
        // operational reorder data, not aggregated sales (Sarah 2026-04-28).

        $business_id = (int) request()->session()->get('user.business_id');
        $session = InventoryCheckSession::where('business_id', $business_id)
            ->where('user_id', auth()->id())
            ->where('id', (int) $id)
            ->firstOrFail();
        $session->delete();

        return response()->json(['success' => true]);
    }
}
