<?php

namespace App\Http\Controllers;

use App\Services\AbcImportService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Admin UI for replacing the live ABC classification with externally-computed
 * data. The uploaded CSV becomes storage/app/abc-import/latest.json and is
 * preferred over the inventory-value computation in InventoryCheckService.
 */
class AbcImportController extends Controller
{
    public function index(Request $request)
    {
        $svc = new AbcImportService();
        $current = $svc->load();
        return view('admin.abc_import', [
            'current' => $current,
        ]);
    }

    /**
     * Parse + match the uploaded CSV but do NOT save yet. Returns JSON the
     * page renders as a preview (match rate, top unmatched names).
     */
    public function preview(Request $request)
    {
        @set_time_limit(0);
        @ini_set('memory_limit', '512M');

        // Hand-rolled validation so failures always return JSON. Laravel's
        // ValidationException returns an HTML redirect when the request
        // doesn't carry Accept: application/json, which the page parses as
        // "Unexpected token '<', \"<!DOCTYPE\"...".
        $file = $request->file('csv');
        if (!$file || !$file->isValid()) {
            return response()->json(['ok' => false, 'error' => 'Upload missing or invalid.'], 422);
        }
        if ($file->getSize() > 20 * 1024 * 1024) {
            return response()->json(['ok' => false, 'error' => 'File over 20 MB.'], 422);
        }
        $ext = strtolower($file->getClientOriginalExtension());
        if (!in_array($ext, ['csv', 'txt'], true)) {
            return response()->json(['ok' => false, 'error' => 'Expected a .csv file (got .' . $ext . ').'], 422);
        }

        $business_id = $request->session()->get('user.business_id');
        $tmpPath = $file->getRealPath();

        try {
            $svc = new AbcImportService();
            $rows = $svc->parseCsv($tmpPath);

            if (empty($rows)) {
                return response()->json(['ok' => false, 'error' => 'No usable rows found. Expected a header row with: Product, SKU, Format, ABC, XYZ, ABC-XYZ (Location optional). A leading banner row above the header is fine.'], 422);
            }

            $result = $svc->match($rows, $business_id);

            // Stash the full payload server-side under a token so Save can pick
            // it up without re-uploading the file. 10-min TTL is fine for review.
            $payload = $this->buildPayload($file->getClientOriginalName(), $request->input('period_label'), $result);
            $token = bin2hex(random_bytes(8));
            $stashPath = storage_path('app/' . AbcImportService::STORAGE_DIR);
            if (!is_dir($stashPath)) {
                @mkdir($stashPath, 0775, true);
            }
            file_put_contents($stashPath . '/pending_' . $token . '.json', json_encode($payload));

            // Show 20 matched mappings + 20 "ambiguous" ones (rows where the
            // CSV product name had multiple ERP candidates and format didn't
            // narrow to one) so it's obvious when the wrong product was picked.
            $trace = $result['matched_trace'];
            $ambiguous = array_values(array_filter($trace, function ($t) {
                return $t['final_candidates'] > 1;
            }));

            return response()->json([
                'ok' => true,
                'token' => $token,
                'stats' => $payload['stats'] + ['ambiguous' => count($ambiguous)],
                'period_label' => $payload['period_label'],
                'source_file' => $payload['source_file'],
                'sample_matched' => array_slice($trace, 0, 20),
                'sample_ambiguous' => array_slice($ambiguous, 0, 20),
                'sample_unmatched' => array_slice($result['unmatched'], 0, 25),
            ]);
        } catch (\Throwable $e) {
            // Catch-all so the page sees a real error, not Laravel's default
            // JSON exception envelope ({message, exception, trace}).
            Log::error('ABC import preview failed', [
                'err' => $e->getMessage(),
                'file' => $e->getFile() . ':' . $e->getLine(),
            ]);
            return response()->json([
                'ok' => false,
                'error' => 'Preview failed: ' . $e->getMessage() . ' (' . basename($e->getFile()) . ':' . $e->getLine() . ')',
            ], 500);
        }
    }

    public function save(Request $request)
    {
        $request->validate(['token' => 'required|string|alpha_num|size:16']);
        $token = $request->input('token');
        $stashFile = storage_path('app/' . AbcImportService::STORAGE_DIR . '/pending_' . $token . '.json');
        if (!is_file($stashFile)) {
            return response()->json(['ok' => false, 'error' => 'Preview expired. Re-upload and try again.'], 410);
        }
        $payload = json_decode(file_get_contents($stashFile), true);
        if (!is_array($payload)) {
            return response()->json(['ok' => false, 'error' => 'Corrupt preview payload.'], 500);
        }

        $svc = new AbcImportService();
        $svc->save($payload);
        @unlink($stashFile);

        // Bust the live-computed ABC cache so downstream pages see the new map
        // immediately instead of waiting 15 min.
        $business_id = $request->session()->get('user.business_id');
        \Illuminate\Support\Facades\Cache::forget('ica_abc_map_' . $business_id);

        return response()->json(['ok' => true, 'stats' => $payload['stats']]);
    }

    public function clear(Request $request)
    {
        $disk = \Illuminate\Support\Facades\Storage::disk('local');
        if ($disk->exists(AbcImportService::STORAGE_FILE)) {
            $disk->delete(AbcImportService::STORAGE_FILE);
        }
        $business_id = $request->session()->get('user.business_id');
        \Illuminate\Support\Facades\Cache::forget('ica_abc_map_' . $business_id);
        return back()->with('status', 'Cleared. ABC classification is back on the live inventory-value computation.');
    }

    protected function buildPayload(string $sourceFile, ?string $periodLabel, array $result): array
    {
        return [
            'uploaded_at' => date('c'),
            'source_file' => $sourceFile,
            'period_label' => $periodLabel ?: '',
            'stats' => [
                'rows' => $result['total'],
                'matched' => $result['matched_count'],
                'sku_matched' => $result['sku_matched'] ?? 0,
                'unmatched' => count($result['unmatched']),
                'distinct_products' => count($result['global_map']),
                'distinct_abcxyz' => count($result['abcxyz_map'] ?? []),
            ],
            'global_map' => $result['global_map'],
            'abcxyz_map' => $result['abcxyz_map'] ?? [],
            'location_map' => $result['location_map'],
            'unmatched' => $result['unmatched'],
        ];
    }

    protected function sampleMatched(array $global_map, int $business_id, int $limit): array
    {
        if (empty($global_map)) {
            return [];
        }
        $ids = array_slice(array_keys($global_map), 0, $limit);
        $rows = \DB::table('products')
            ->where('business_id', $business_id)
            ->whereIn('id', $ids)
            ->select('id', 'name')
            ->get();
        $out = [];
        foreach ($rows as $r) {
            $out[] = ['id' => $r->id, 'name' => $r->name, 'class' => $global_map[$r->id] ?? '?'];
        }
        return $out;
    }
}
