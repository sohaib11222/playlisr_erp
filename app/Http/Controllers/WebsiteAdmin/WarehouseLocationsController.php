<?php

namespace App\Http\Controllers\WebsiteAdmin;

use App\Http\Controllers\Controller;
use App\Services\WebsiteBridge;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

/**
 * Discogs location code to zone mappings, moved from the website's
 * /admin/warehouse/locations (Sarah, 2026-10-15): list, add, edit, delete,
 * CSV export, CSV bulk import and the Auto-Mapper scan.
 */
class WarehouseLocationsController extends Controller
{
    const STORES = ['Pico', 'Hollywood', 'Warehouse', 'Other'];

    const SAMPLE_CSV = "discogs_code,store,zone,description,sub_location,shelf_details\n"
        . "FL2,Hollywood,Hollywood Main Floor,Front left section 2,Aisle F,Shelf L-2\n"
        . "J5,Hollywood,Hollywood Upstairs,Jazz section 5,Aisle J,Rack 5\n"
        . "PICO66,Pico,Pico Main Floor,Main floor section 66,Section 66,\n"
        . "B6,Hollywood,Hollywood Basement,Basement section 6,Aisle B,Shelf 6\n";

    protected function guard()
    {
        if (!auth()->user()->can('product.create') && !auth()->user()->can('sell.create')) {
            abort(403, 'Unauthorized action.');
        }
    }

    protected function scanKey(): string
    {
        return 'wa_wh_scan_' . auth()->id();
    }

    public function index(Request $request)
    {
        $this->guard();
        $f = [
            'q' => trim((string) $request->query('q', '')),
            'store' => (string) $request->query('store', ''),
            'zone' => (string) $request->query('zone', ''),
            'active' => (string) $request->query('active', 'true'),
        ];
        $params = array_filter(['store' => $f['store'], 'zone' => $f['zone'], 'active' => $f['active']], fn($v) => $v !== '');
        $resp = WebsiteBridge::admin('GET', '/warehouse/locations' . ($params ? '?' . http_build_query($params) : ''), null, 30);
        $all = ($resp['success'] ?? false) ? ($resp['data'] ?? []) : [];
        $rows = $all;
        if ($f['q'] !== '') {
            $needle = strtolower($f['q']);
            $rows = array_values(array_filter($all, function ($l) use ($needle) {
                return strpos(strtolower((string) ($l['discogs_code'] ?? '')), $needle) !== false
                    || strpos(strtolower((string) ($l['description'] ?? '')), $needle) !== false;
            }));
        }

        if ($request->query('export') === 'csv') {
            return response()->streamDownload(function () use ($rows) {
                $out = fopen('php://output', 'w');
                fputcsv($out, ['discogs_code', 'store', 'zone', 'description', 'sub_location', 'shelf_details', 'usage_count']);
                foreach ($rows as $l) {
                    fputcsv($out, [$l['discogs_code'] ?? '', $l['store'] ?? '', $l['zone'] ?? '', $l['description'] ?? '',
                        $l['sub_location'] ?? '', $l['shelf_details'] ?? '', $l['usage_count'] ?? 0]);
                }
                fclose($out);
            }, 'warehouse-locations-' . date('Y-m-d') . '.csv', ['Content-Type' => 'text/csv']);
        }

        $counts = ['total' => count($all)];
        foreach (self::STORES as $s) {
            $counts[$s] = count(array_filter($all, fn($l) => ($l['store'] ?? '') === $s));
        }

        return view('website_admin.warehouse.locations', [
            'f' => $f,
            'rows' => $rows,
            'counts' => $counts,
            'error' => ($resp['success'] ?? false) ? null : ($resp['message'] ?? 'Could not load locations.'),
            'stores' => self::STORES,
            'zonesByStore' => WarehouseController::zones(),
            'preview' => session('wa_wh_import_preview'),
            'importResult' => session('wa_wh_import_result'),
            'scan' => Cache::get($this->scanKey()),
            'scanResult' => session('wa_wh_scan_result'),
        ]);
    }

    protected function payload(Request $request): array
    {
        return [
            'discogs_code' => preg_replace('/\s+/', ' ', trim((string) $request->input('discogs_code'))),
            'store' => (string) $request->input('store'),
            'zone' => (string) $request->input('zone'),
            'description' => (string) $request->input('description', ''),
            'sub_location' => (string) $request->input('sub_location', ''),
            'shelf_details' => (string) $request->input('shelf_details', ''),
        ];
    }

    protected function invalid(array $p): ?string
    {
        if ($p['discogs_code'] === '') return 'The location code is required.';
        if (!preg_match('/^[A-Z0-9 _-]+$/i', $p['discogs_code'])) return 'Codes can only have letters, numbers, spaces, hyphens and underscores.';
        if (strlen($p['discogs_code']) > 50) return 'Codes must be 50 characters or less.';
        if (!in_array($p['store'], self::STORES, true)) return 'Pick a store.';
        if ($p['zone'] === '') return 'Pick a zone.';
        return null;
    }

    public function store(Request $request)
    {
        $this->guard();
        $p = $this->payload($request);
        if ($err = $this->invalid($p)) {
            return redirect()->back()->with('error', $err);
        }
        $resp = WebsiteBridge::admin('POST', '/warehouse/locations', $p);
        \Cache::forget('products_index_bin_map'); // Products list Location column picks it up now
        $ok = ($resp['success'] ?? false) === true;
        return redirect()->back()->with($ok ? 'status' : 'error', $ok ? "Added location {$p['discogs_code']}." : ($resp['message'] ?? 'Could not add the location.'));
    }

    public function update(Request $request, string $id)
    {
        $this->guard();
        $p = $this->payload($request);
        if ($err = $this->invalid($p)) {
            return redirect()->back()->with('error', $err);
        }
        $old = trim((string) $request->input('old_code'));
        $resp = WebsiteBridge::admin('PUT', '/warehouse/locations/' . rawurlencode($id), $p);
        \Cache::forget('products_index_bin_map'); // Products list Location column picks it up now
        $ok = ($resp['success'] ?? false) === true;
        $msg = $old !== '' && strcasecmp($old, $p['discogs_code']) !== 0
            ? "Changed code {$old} to {$p['discogs_code']}. Change it on Discogs too."
            : 'Location saved.';
        return redirect()->back()->with($ok ? 'status' : 'error', $ok ? $msg : ($resp['message'] ?? 'Could not save the location.'));
    }

    public function destroy(string $id)
    {
        $this->guard();
        $resp = WebsiteBridge::admin('DELETE', '/warehouse/locations/' . rawurlencode($id));
        \Cache::forget('products_index_bin_map'); // Products list Location column picks it up now
        $ok = ($resp['success'] ?? false) === true;
        return redirect()->back()->with($ok ? 'status' : 'error', $ok ? 'Location deleted. It is now inactive.' : ($resp['message'] ?? 'Could not delete the location.'));
    }

    public function sample()
    {
        $this->guard();
        return response(self::SAMPLE_CSV, 200, [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => 'attachment; filename="location-import-sample.csv"',
        ]);
    }

    /** Step 1 parses the CSV into a preview; step 2 (confirm) sends it. */
    public function import(Request $request)
    {
        $this->guard();
        if ($request->input('step') === 'confirm') {
            $rows = session('wa_wh_import_preview.rows', []);
            session()->forget('wa_wh_import_preview');
            if (!$rows) {
                return redirect()->back()->with('error', 'Nothing to import. Upload the CSV again.');
            }
            $resp = WebsiteBridge::admin('POST', '/warehouse/locations/bulk-import', ['locations' => $rows], 120);
            \Cache::forget('products_index_bin_map'); // Products list Location column picks it up now
            if (!($resp['success'] ?? false)) {
                return redirect()->back()->with('error', $resp['message'] ?? 'Import failed.');
            }
            return redirect()->back()->with('wa_wh_import_result', $resp['results'] ?? [])->with('status', 'Import finished.');
        }
        if ($request->input('step') === 'cancel') {
            session()->forget('wa_wh_import_preview');
            return redirect()->back();
        }

        $file = $request->file('csv');
        if (!$file || strtolower($file->getClientOriginalExtension()) !== 'csv') {
            return redirect()->back()->with('error', 'Pick a CSV file.');
        }
        $lines = array_values(array_filter(preg_split('/\r\n|\r|\n/', (string) file_get_contents($file->getRealPath())), fn($l) => trim($l) !== ''));
        if (!$lines) {
            return redirect()->back()->with('error', 'That CSV file is empty.');
        }
        $headers = array_map(fn($h) => trim($h, " \t\"\xEF\xBB\xBF"), str_getcsv($lines[0]));
        $missing = array_diff(['discogs_code', 'store', 'zone'], $headers);
        if ($missing) {
            return redirect()->back()->with('error', 'Missing required columns: ' . implode(', ', $missing) . '.');
        }
        $rows = [];
        foreach (array_slice($lines, 1) as $line) {
            $vals = str_getcsv($line);
            $row = [];
            foreach ($headers as $i => $h) {
                $v = trim((string) ($vals[$i] ?? ''));
                if ($v !== '') $row[$h] = $v;
            }
            if ($row) $rows[] = $row;
        }
        session(['wa_wh_import_preview' => ['name' => $file->getClientOriginalName(), 'rows' => $rows]]);
        return redirect()->back();
    }

    /** Auto-Mapper: scans the whole Discogs inventory, so it can take minutes. */
    public function scan()
    {
        $this->guard();
        @set_time_limit(620);
        $resp = WebsiteBridge::admin('GET', '/warehouse/locations/scan-unmapped', null, 600);
        if (!($resp['success'] ?? false)) {
            return redirect()->back()->with('error', 'Scan failed: ' . ($resp['message'] ?? 'unknown error'));
        }
        Cache::put($this->scanKey(), [
            'scanned' => $resp['scanned'] ?? 0,
            'existing_mappings' => $resp['existing_mappings'] ?? 0,
            'unmapped_count' => $resp['unmapped_count'] ?? 0,
            'unmapped_locations' => $resp['unmapped_locations'] ?? [],
            'at' => time(),
        ], 60 * 60 * 6);
        $n = (int) ($resp['unmapped_count'] ?? 0);
        return redirect()->back()->with('status', $n === 0 ? 'Every location code is already mapped.' : "Found {$n} unmapped location codes.");
    }

    public function scanExport()
    {
        $this->guard();
        $scan = Cache::get($this->scanKey());
        $rows = $scan['unmapped_locations'] ?? [];
        return response()->streamDownload(function () use ($rows) {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['discogs_code', 'store', 'zone', 'count', 'sample_item']);
            foreach ($rows as $l) {
                fputcsv($out, [$l['code'] ?? '', $l['suggested']['store'] ?? '', $l['suggested']['zone'] ?? '', $l['count'] ?? 0, $l['sample_title'] ?? '']);
            }
            fclose($out);
        }, 'unmapped-locations-' . date('Y-m-d') . '.csv', ['Content-Type' => 'text/csv']);
    }

    public function autoCreate(Request $request)
    {
        $this->guard();
        $scan = Cache::get($this->scanKey());
        $codes = array_map('strval', (array) $request->input('codes', []));
        $chosen = array_values(array_filter($scan['unmapped_locations'] ?? [], fn($l) => in_array((string) ($l['code'] ?? ''), $codes, true)));
        if (!$chosen) {
            return redirect()->back()->with('error', 'Tick at least one code to map.');
        }
        $dry = $request->input('mode') === 'preview';
        $resp = WebsiteBridge::admin('POST', '/warehouse/locations/auto-create', ['locations' => $chosen, 'dry_run' => $dry], 120);
        \Cache::forget('products_index_bin_map'); // Products list Location column picks it up now
        if (!($resp['success'] ?? false)) {
            return redirect()->back()->with('error', $resp['message'] ?? 'Could not create the mappings.');
        }
        $r = $resp['results'] ?? [];
        if ($dry) {
            return redirect()->back()->with('wa_wh_scan_result', ['dry' => true] + $r)
                ->with('status', 'Preview: would create ' . count($r['preview'] ?? []) . ' mappings, ' . ($r['skipped'] ?? 0) . ' skipped.');
        }
        // Drop the codes just mapped from the saved scan.
        $scan['unmapped_locations'] = array_values(array_filter($scan['unmapped_locations'] ?? [], fn($l) => !in_array((string) ($l['code'] ?? ''), $codes, true)));
        $scan['unmapped_count'] = count($scan['unmapped_locations']);
        Cache::put($this->scanKey(), $scan, 60 * 60 * 6);
        return redirect()->back()->with('wa_wh_scan_result', ['dry' => false] + $r)
            ->with('status', 'Created ' . ($r['created'] ?? 0) . ' mappings, ' . ($r['skipped'] ?? 0) . ' skipped, ' . count($r['errors'] ?? []) . ' errors.');
    }
}
