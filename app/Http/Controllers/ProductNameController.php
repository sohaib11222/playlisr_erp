<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Services\ProductNameNormalizer;

/**
 * Owner-only tool to bring product names onto the "ARTIST - TITLE" standard.
 *
 * Scan is read-only: it proposes a normalized name for every product whose
 * name is off-standard and can be fixed confidently (real artist column + a
 * derivable title). Products with no real artist ("Unknown Artist"/blank) or
 * an underivable title are flagged for manual review and never auto-changed.
 *
 * Apply runs in batches, snapshots each old name to admin-snapshots first, and
 * is reversible at /admin/admin-action-history (action product-name-cleanup).
 */
class ProductNameController extends Controller
{
    protected function isOwner()
    {
        $u = auth()->user();
        return $u
            && strtolower(trim((string) $u->first_name)) === 'jonathan'
            && strtolower(trim((string) $u->last_name)) === 'hedvat';
    }

    public function index()
    {
        if (!$this->isOwner()) {
            abort(403, 'Product name cleanup is owner-only.');
        }
        return view('products.name_cleanup');
    }

    /**
     * Walk the catalog and split into: to-fix (confident, off-standard) and
     * flagged (needs manual). Returns counts + a capped preview of to-fix.
     */
    protected function computeChanges($business_id, $collectFixes = true, $limit = null)
    {
        $fixes = [];
        $toFix = 0;
        $flagged = 0;
        $compliant = 0;

        \DB::table('products')
            ->where('business_id', $business_id)
            ->select('id', 'name', 'artist')
            ->orderBy('id')
            ->chunk(2000, function ($rows) use (&$fixes, &$toFix, &$flagged, &$compliant, $collectFixes, $limit) {
                foreach ($rows as $r) {
                    $res = ProductNameNormalizer::canonical($r->artist, $r->name);
                    if (!$res['confident']) { $flagged++; continue; }
                    if ($res['compliant']) { $compliant++; continue; }
                    $toFix++;
                    if ($collectFixes && ($limit === null || count($fixes) < $limit)) {
                        $fixes[] = ['id' => (int) $r->id, 'old' => $r->name, 'new' => $res['name']];
                    }
                }
            });

        return ['fixes' => $fixes, 'to_fix' => $toFix, 'flagged' => $flagged, 'compliant' => $compliant];
    }

    public function scan(Request $request)
    {
        @set_time_limit(0);
        @ini_set('memory_limit', '1024M');
        if (!$this->isOwner()) {
            return response()->json(['success' => false, 'msg' => 'Owner-only.'], 403);
        }
        $business_id = $request->session()->get('user.business_id');
        try {
            $data = $this->computeChanges($business_id, true, 300);
        } catch (\Throwable $e) {
            \Log::error('product-name-cleanup scan failed: ' . $e->getMessage());
            return response()->json(['success' => false, 'msg' => 'Scan failed: ' . $e->getMessage()]);
        }

        return response()->json([
            'success' => true,
            'to_fix' => $data['to_fix'],
            'flagged' => $data['flagged'],
            'compliant' => $data['compliant'],
            'preview' => $data['fixes'],
        ]);
    }

    /**
     * Rename one batch of off-standard products (up to `max`, default 500) and
     * report how many remain. The UI loops until remaining hits 0. Each call
     * writes one product-name-cleanup snapshot.
     */
    public function apply(Request $request)
    {
        @set_time_limit(0);
        @ini_set('memory_limit', '1024M');
        if (!$this->isOwner()) {
            return response()->json(['success' => false, 'msg' => 'Owner-only.'], 403);
        }
        $business_id = $request->session()->get('user.business_id');
        $max = (int) $request->input('max', 500);
        if ($max < 1) { $max = 500; }

        // Gather this batch of confident, off-standard products.
        $batch = [];
        \DB::table('products')
            ->where('business_id', $business_id)
            ->select('id', 'name', 'artist')
            ->orderBy('id')
            ->chunk(2000, function ($rows) use (&$batch, $max) {
                foreach ($rows as $r) {
                    $res = ProductNameNormalizer::canonical($r->artist, $r->name);
                    if ($res['confident'] && !$res['compliant']) {
                        $batch[] = ['id' => (int) $r->id, 'old' => $r->name, 'new' => $res['name']];
                        if (count($batch) >= $max) { return false; } // stop chunking
                    }
                }
            });

        if (empty($batch)) {
            return response()->json(['success' => true, 'renamed' => 0, 'remaining' => 0, 'msg' => 'Nothing left to fix.']);
        }

        $timestamp = now()->format('Y-m-d_His');
        $renamed = 0;

        \DB::beginTransaction();
        try {
            foreach ($batch as $b) {
                // Guard against a concurrent edit: only rename if the name is
                // still exactly what we snapshotted.
                $affected = \DB::table('products')->where('id', $b['id'])->where('name', $b['old'])
                    ->update(['name' => $b['new']]);
                if ($affected) { $renamed++; }
            }

            \Storage::disk('local')->put(
                "admin-snapshots/product-name-cleanup-{$timestamp}.json",
                json_encode([
                    'timestamp' => $timestamp,
                    'action' => 'product-name-cleanup',
                    'user_id' => auth()->id(),
                    'business_id' => $business_id,
                    'source_name' => $renamed . ' product name(s)',
                    'target_name' => 'ARTIST - TITLE',
                    'rows' => array_map(function ($b) { return ['id' => $b['id'], 'old' => $b['old'], 'new' => $b['new']]; }, $batch),
                ], JSON_PRETTY_PRINT)
            );
            \DB::commit();
        } catch (\Throwable $e) {
            \DB::rollBack();
            if (\Storage::disk('local')->exists("admin-snapshots/product-name-cleanup-{$timestamp}.json")) {
                \Storage::disk('local')->delete("admin-snapshots/product-name-cleanup-{$timestamp}.json");
            }
            \Log::emergency('product-name-cleanup failed: ' . $e->getMessage());
            return response()->json(['success' => false, 'msg' => 'Rename failed — nothing was changed.']);
        }

        $remaining = $this->computeChanges($business_id, false)['to_fix'];

        return response()->json([
            'success' => true,
            'renamed' => $renamed,
            'remaining' => $remaining,
            'msg' => "Renamed {$renamed}. {$remaining} remaining.",
        ]);
    }
}
