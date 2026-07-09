<?php

namespace App\Services;

use App\Product;

/**
 * Catches duplicate products at entry time so the same record can't be created
 * twice. Mirrors the matching logic the owner-only Merge Duplicates tool uses
 * (see ProductMergeController: skuKey + canonical "ARTIST - TITLE" name), but
 * runs *before* a product is saved instead of cleaning up afterwards.
 *
 * Two independent signals, each strong enough on its own to block a new entry:
 *   - barcode  : a real scannable barcode (>= 8 digits) already on file. Two
 *                products can never legitimately share a barcode, so this is a
 *                hard collision.
 *   - name     : the canonical "ARTIST - TITLE" already exists among active
 *                products. This is the common case for used vinyl with no
 *                barcode, where the same title just gets typed in twice.
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
     * The canonical "ARTIST - TITLE" name, matching what ProductController@store
     * writes on save. Falls back to the raw name when no confident canonical
     * form exists (e.g. non-music items, no real artist).
     */
    public function canonicalName($artist, $name)
    {
        $canon = ProductNameNormalizer::canonical($artist ?? '', $name ?? '');
        return $canon['confident'] ? $canon['name'] : trim((string) $name);
    }

    /**
     * Return the first active product that a new entry would duplicate, or null.
     * Barcode collisions take priority over name collisions.
     *
     * @return array{type:string,product:\App\Product}|null
     */
    public function findConflict($business_id, $name, $artist, $sku, $excludeId = null)
    {
        // 1) Real barcode already on file.
        $key = $this->skuKey($sku);
        if ($key !== '') {
            $barcodeMatch = Product::where('business_id', $business_id)
                ->where('is_inactive', 0)
                ->when($excludeId, function ($q) use ($excludeId) {
                    $q->where('id', '!=', $excludeId);
                })
                // Compare on the leading-zero-stripped barcode so "0012345678"
                // and "12345678" collide. TRIM here mirrors skuKey().
                ->whereRaw("TRIM(LEADING '0' FROM TRIM(sku)) = ?", [$key])
                ->orderBy('id')
                ->first();
            if ($barcodeMatch) {
                return ['type' => 'barcode', 'product' => $barcodeMatch];
            }
        }

        // 2) Same canonical "ARTIST - TITLE" already on file.
        $canonical = $this->canonicalName($artist, $name);
        if ($canonical !== '') {
            $nameMatch = Product::where('business_id', $business_id)
                ->where('is_inactive', 0)
                ->when($excludeId, function ($q) use ($excludeId) {
                    $q->where('id', '!=', $excludeId);
                })
                ->whereRaw('LOWER(TRIM(name)) = ?', [mb_strtolower(trim($canonical))])
                ->orderBy('id')
                ->first();
            if ($nameMatch) {
                return ['type' => 'name', 'product' => $nameMatch];
            }
        }

        return null;
    }

    /**
     * Human-readable reason for a conflict, e.g. for a toast or inline message.
     */
    public function conflictMessage(array $conflict)
    {
        $p = $conflict['product'];
        if ($conflict['type'] === 'barcode') {
            return 'Barcode ' . trim((string) $p->sku) . ' is already used by "' . $p->name . '". '
                . 'Add stock to that product instead of creating a new one.';
        }
        return '"' . $p->name . '" already exists (SKU ' . trim((string) $p->sku) . '). '
            . 'Open that product and add stock instead of creating a duplicate.';
    }
}
