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

        $svc = new AbcImportService();
        try {
            $rows = $svc->parseCsv($tmpPath);
        } catch (\Throwable $e) {
            Log::error('ABC import parse failed', ['err' => $e->getMessage()]);
            return response()->json(['ok' => false, 'error' => 'Could not parse CSV: ' . $e->getMessage()], 422);
        }

        if (empty($rows)) {
            return response()->json(['ok' => false, 'error' => 'No usable rows found. Expected columns: Product, Format, Location, Sales, Q-ty, ABC.'], 422);
        }

        $result = $svc->match($rows, $business_id);

        // Stash the full payload server-side under a token so Save can pick it
        // up without re-uploading the file. 10-min TTL is fine for review.
        $payload = $this->buildPayload($file->getClientOriginalName(), $request->input('period_label'), $result);
        $token = bin2hex(random_bytes(8));
        $stashPath = storage_path('app/' . AbcImportService::STORAGE_DIR);
        if (!is_dir($stashPath)) {
            @mkdir($stashPath, 0775, true);
        }
        file_put_contents($stashPath . '/pending_' . $token . '.json', json_encode($payload));

        return response()->json([
            'ok' => true,
            'token' => $token,
            'stats' => $payload['stats'],
            'period_label' => $payload['period_label'],
            'source_file' => $payload['source_file'],
            'sample_matched' => $this->sampleMatched($result['global_map'], $business_id, 10),
            'sample_unmatched' => array_slice($result['unmatched'], 0, 25),
        ]);
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
                'unmatched' => count($result['unmatched']),
                'distinct_products' => count($result['global_map']),
            ],
            'global_map' => $result['global_map'],
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
