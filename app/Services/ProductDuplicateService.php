<?php

namespace App\Services;

use App\Product;

/**
 * Catches duplicate products at entry time so the same record can't be created
 * twice. Matches on SKU / barcode only (never on name) so a genuinely different
 * pressing that reuses no barcode is never blocked. Runs *before* a product is
 * saved, unlike the owner-only Merge Duplicates tool which cleans up afterwards.
 */
class ProductDuplicateService
{
    /**
     * Real-barcode signature. Junk placeholders ("3", "003", "0004"), blanks
     * and auto-generated SKUs are rejected (return '') so they never anchor a
     * duplicate. Leading zeros are stripped so "0012345678" == "12345678".
     * Kept in lockstep with ProductMergeController::skuKey().
     */
    public function skuKey($sku)
    {
        $s = trim((string) $sku);
        if ($s === '' || !ctype_digit($s) || strlen($s) < 8) {
            return '';
        }
        $stripped = ltrim($s, '0');
        return $stripped === '' ? $s : $stripped;
    }

    /**
     * Return the first active product that a new entry would duplicate by SKU,
     * or null. Matching is on the SKU / barcode only — never on the name, so a
     * genuinely different pressing that reuses no barcode is not blocked.
     *
     * @return array{type:string,product:\App\Product}|null
     */
    public function findConflict($business_id, $name, $artist, $sku, $excludeId = null)
    {
        $s = trim((string) $sku);
        if ($s === '') {
            return null; // No SKU entered yet -> nothing to match on.
        }

        $query = Product::where('business_id', $business_id)
            ->where('is_inactive', 0)
            ->when($excludeId, function ($q) use ($excludeId) {
                $q->where('id', '!=', $excludeId);
            });

        $key = $this->skuKey($s);
        if ($key !== '') {
            // Real barcode: compare leading-zero-stripped so "0012345678" and
            // "12345678" collide. TRIM here mirrors skuKey().
            $query->whereRaw("TRIM(LEADING '0' FROM TRIM(sku)) = ?", [$key]);
        } else {
            // Short / non-barcode SKU: exact match only.
            $query->whereRaw('TRIM(sku) = ?', [$s]);
        }

        $match = $query->orderBy('id')->first();
        return $match ? ['type' => 'barcode', 'product' => $match] : null;
    }

    /**
     * Human-readable reason for a conflict, e.g. for a toast or inline message.
     */
    public function conflictMessage(array $conflict)
    {
        $p = $conflict['product'];
        return 'SKU ' . trim((string) $p->sku) . ' is already used by "' . $p->name . '". '
            . 'Add stock to that product instead of creating a new one.';
    }
}
