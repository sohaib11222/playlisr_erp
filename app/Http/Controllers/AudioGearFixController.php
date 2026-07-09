<?php

namespace App\Http\Controllers;

use App\Category;
use App\Product;
use Illuminate\Http\Request;
use Storage;
use DB;

/**
 * Owner-only one-click fix for audio gear (CD players, boomboxes, turntables,
 * radios) that was left uncategorized in the ERP and therefore fell through to
 * "Vinyl Records" on nivessa.com.
 *
 * These specific products have no category AND no descriptive sub-category, so
 * the website sync (which reads the POS category/sub-category names) can't tell
 * they're hardware from the name alone without also mis-labeling real records
 * like "Bootsy? Player Of The Year". This tool assigns them, by explicit ERP
 * product id, to a top-level "Audio Gear" product category (created if missing).
 * The website then reads category name "Audio Gear" and maps it correctly.
 *
 * Reversible: the BEFORE category_id/sub_category_id of every touched product is
 * snapshotted to storage/app/admin-snapshots and can be rolled back one-click at
 * /admin/admin-action-history (action 'recategorize-audio-gear').
 */
class AudioGearFixController extends Controller
{
    /**
     * Explicit ERP product ids of the blank-genre audio gear, verified against
     * the live catalogue (CD/cassette players, boomboxes, radios, turntables).
     * Kept as an explicit allow-list — NOT a name match — so no real record is
     * ever swept in by accident.
     */
    const GEAR_PRODUCT_IDS = [16237, 16238, 47249, 47250, 47251, 47252, 47253, 47255, 47256, 56273, 56275];

    /** Owner-only, mirroring the merge/name-cleanup gate (Jonathan Hedvat). */
    protected function isOwner()
    {
        $u = auth()->user();
        return $u
            && strtolower(trim((string) $u->first_name)) === 'jonathan'
            && strtolower(trim((string) $u->last_name)) === 'hedvat';
    }

    public function apply(Request $request)
    {
        if (!$this->isOwner()) {
            abort(403, 'Only Jon can run the audio-gear fix.');
        }

        $business_id = $request->session()->get('user.business_id');

        // Find or create the top-level "Audio Gear" product category.
        $category = Category::where('business_id', $business_id)
            ->where('category_type', 'product')
            ->where('parent_id', 0)
            ->whereRaw('LOWER(name) = ?', ['audio gear'])
            ->first();
        if (!$category) {
            $category = Category::create([
                'business_id'   => $business_id,
                'name'          => 'Audio Gear',
                'category_type' => 'product',
                'parent_id'     => 0,
                'created_by'    => $request->session()->get('user.id'),
            ]);
        }
        $audioGearId = (int) $category->id;

        // Load the targets scoped to this business, skipping any already sitting
        // in Audio Gear so re-running is a no-op.
        $products = Product::where('business_id', $business_id)
            ->whereIn('id', self::GEAR_PRODUCT_IDS)
            ->where(function ($q) use ($audioGearId) {
                $q->where('category_id', '!=', $audioGearId)->orWhereNull('category_id');
            })
            ->get(['id', 'name', 'sku', 'category_id', 'sub_category_id']);

        if ($products->isEmpty()) {
            return response()->json([
                'success'         => true,
                'audio_gear_id'   => $audioGearId,
                'updated'         => 0,
                'msg'             => 'Nothing to do — all target products are already in Audio Gear.',
            ]);
        }

        // Snapshot BEFORE state so it's undoable at /admin/admin-action-history.
        $timestamp   = now()->format('Y-m-d_His');
        $snapshotKey = "recategorize-audio-gear-{$timestamp}";
        Storage::disk('local')->put(
            "admin-snapshots/{$snapshotKey}.json",
            json_encode([
                'timestamp'      => $timestamp,
                'action'         => 'recategorize-audio-gear',
                'user_id'        => auth()->id(),
                'business_id'    => $business_id,
                'audio_gear_id'  => $audioGearId,
                'rows'           => $products->map(function ($p) {
                    return [
                        'id'              => $p->id,
                        'category_id'     => $p->category_id,
                        'sub_category_id' => $p->sub_category_id,
                    ];
                })->all(),
            ], JSON_PRETTY_PRINT)
        );

        $ids = $products->pluck('id')->all();

        DB::beginTransaction();
        try {
            Product::where('business_id', $business_id)
                ->whereIn('id', $ids)
                ->update([
                    'category_id'     => $audioGearId,
                    'sub_category_id' => null,
                ]);
            DB::commit();
        } catch (\Exception $e) {
            DB::rollBack();
            \Log::emergency('audio-gear fix failed: ' . $e->getMessage());
            return response()->json(['success' => false, 'msg' => 'Update failed, nothing changed: ' . $e->getMessage()], 500);
        }

        return response()->json([
            'success'       => true,
            'audio_gear_id' => $audioGearId,
            'updated'       => count($ids),
            'products'      => $products->map(function ($p) {
                return ['id' => $p->id, 'sku' => $p->sku, 'name' => $p->name];
            })->values(),
            'msg'           => 'Set ' . count($ids) . ' product(s) to Audio Gear. Undo at /admin/admin-action-history (snapshot ' . $snapshotKey . '). Run a website Full Re-Sync to reflect on nivessa.com.',
        ]);
    }
}
