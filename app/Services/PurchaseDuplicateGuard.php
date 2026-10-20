<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/**
 * Soft block against receiving the same distributor box twice (10/2026:
 * #180115 re-received Alliance invoice PLS97602328 records that #178807 /
 * #178797 already had).
 *
 * For a purchase from a distributor supplier (AMS, Alliance, Redeye, ...)
 * warnings() lists:
 *   - ref:      another purchase from the same supplier in the last 30 days
 *               has the same ref / invoice number;
 *   - received: the same UPCs from the same supplier were received at the
 *               same store in the last 30 days;
 *   - ordered:  the UPCs are on an open Ordered purchase from the supplier at
 *               the store (usually the automatic invoice import): receive that
 *               purchase instead of entering a new one.
 * The UI asks for a reason before saving anyway; it never blocks outright,
 * and a failed check never stops a save.
 */
class PurchaseDuplicateGuard
{
    const DAYS = 30;

    public static function isDistributor($business_id, $contact_id)
    {
        if (!$contact_id) return false;
        $c = DB::table('contacts')->where('business_id', $business_id)->where('id', $contact_id)->first(['name', 'supplier_business_name']);
        if (!$c) return false;
        foreach (\App\Http\Controllers\DistributorInvoiceAuditController::DISTRIBUTOR_SUPPLIERS as $names) {
            foreach ($names as $n) {
                if (strcasecmp(trim((string) $c->name), $n) === 0 || strcasecmp(trim((string) $c->supplier_business_name), $n) === 0) return true;
            }
        }
        return false;
    }

    public static function distributorIds($business_id)
    {
        $ids = [];
        foreach (\App\Http\Controllers\DistributorInvoiceAuditController::DISTRIBUTOR_SUPPLIERS as $names) {
            foreach (DB::table('contacts')->where('business_id', $business_id)->where('type', '!=', 'customer')
                ->where(function ($q) use ($names) { foreach ($names as $n) $q->orWhere('name', $n)->orWhere('supplier_business_name', $n); })
                ->pluck('id') as $id) $ids[] = (int) $id;
        }
        return array_values(array_unique($ids));
    }

    // Register quick receive: one record received at the till. Flag text if
    // that barcode came in from a distributor at this store in the last 30
    // days, or is on an open incoming order (receive that instead).
    public static function quickReceiveFlag($business_id, $location_id, $variation_id, $exclude_tx_id = null)
    {
        $w = self::warnings($business_id, null, $location_id, '', [$variation_id], 'received', null);
        $w = array_values(array_filter($w, function ($x) use ($exclude_tx_id) { return (int) $x['transaction_id'] !== (int) $exclude_tx_id; }));
        if (!$w) return null;
        $parts = [];
        foreach ($w as $x) {
            $parts[] = $x['type'] === 'ordered'
                ? 'on incoming order #' . $x['transaction_id'] . ', receive that instead'
                : 'already received on #' . $x['transaction_id'] . ' (' . date('m/d/y', strtotime($x['date'])) . ')';
        }
        return 'Check this one: ' . implode('; ', array_slice($parts, 0, 3)) . '.';
    }

    /**
     * @param string $status  the status being saved (received / ordered / pending)
     * @param int|null $purchase_id  the purchase being edited (excluded), null when creating
     * @return array of ['type' => ref|received|ordered, 'transaction_id', 'ref_no', 'status', 'date', 'items' => [names]]
     */
    public static function warnings($business_id, $contact_id, $location_id, $ref_no, array $variation_ids, $status, $purchase_id = null)
    {
        // contact_id null = any distributor (the register quick receive has no supplier).
        if ($contact_id === null) {
            $contactIds = self::distributorIds($business_id);
            if (!$contactIds) return [];
        } elseif (!self::isDistributor($business_id, $contact_id)) {
            return [];
        } else {
            $contactIds = [(int) $contact_id];
        }
        $since = now()->subDays(self::DAYS)->toDateString();
        $out = [];

        // Same ref / invoice number. Auto-numbered refs ("PO2026/1234") are
        // unique by construction, so only typed refs count.
        $ref = trim((string) $ref_no);
        if ($ref !== '' && !preg_match('#^PO\d{4}/\d+$#', $ref)) {
            $q = DB::table('transactions')->where('business_id', $business_id)->where('type', 'purchase')
                ->whereIn('contact_id', $contactIds)->where('ref_no', $ref)->where('transaction_date', '>=', $since);
            if ($purchase_id) $q->where('id', '!=', $purchase_id);
            foreach ($q->get(['id', 'ref_no', 'status', 'transaction_date']) as $t) {
                $out[] = ['type' => 'ref', 'transaction_id' => (int) $t->id, 'ref_no' => $t->ref_no, 'status' => $t->status, 'date' => substr($t->transaction_date, 0, 10), 'items' => []];
            }
        }

        // UPC overlap, across duplicate products carrying the same barcode.
        $variation_ids = array_values(array_unique(array_filter(array_map('intval', $variation_ids))));
        if (!$variation_ids || !$location_id) return $out;
        $skus = DB::table('variations')->whereIn('id', $variation_ids)->pluck('sub_sku')->all();
        $keys = [];
        foreach ($skus as $s) {
            $d = preg_replace('/\D/', '', (string) $s);
            if (strlen($d) >= 11 && strlen($d) <= 14 && $d === (string) $s) $keys[ltrim($d, '0')] = true; // real barcodes only
        }
        if (!$keys) return $out;
        $cands = [];
        foreach (array_keys($keys) as $k) { $cands[] = $k; $cands[] = '0' . $k; $cands[] = '00' . $k; }

        $q = DB::table('purchase_lines as pl')
            ->join('transactions as t', 't.id', '=', 'pl.transaction_id')
            ->join('variations as v', 'v.id', '=', 'pl.variation_id')
            ->join('products as p', 'p.id', '=', 'pl.product_id')
            ->where('t.business_id', $business_id)->where('t.type', 'purchase')
            ->whereIn('t.contact_id', $contactIds)->where('t.location_id', $location_id)
            ->whereIn('v.sub_sku', $cands)
            ->where(function ($w) use ($since) {
                $w->where(function ($a) use ($since) {
                    $a->where('t.status', 'received')->where('t.transaction_date', '>=', $since);
                })->orWhere(function ($b) {
                    $b->whereIn('t.status', ['ordered', 'pending']);
                });
            });
        if ($purchase_id) $q->where('t.id', '!=', $purchase_id);
        $byTx = [];
        foreach ($q->get(['t.id', 't.ref_no', 't.status', 't.transaction_date', 'p.name', 'pl.quantity']) as $r) {
            $type = $r->status === 'received' ? 'received' : 'ordered';
            if ($type === 'received' && $status !== 'received') continue; // only matters when receiving
            if ($type === 'ordered' && $purchase_id) continue;            // only when entering a new purchase
            $k = $type . ':' . $r->id;
            if (!isset($byTx[$k])) {
                $byTx[$k] = ['type' => $type, 'transaction_id' => (int) $r->id, 'ref_no' => $r->ref_no, 'status' => $r->status, 'date' => substr($r->transaction_date, 0, 10), 'items' => []];
            }
            if (count($byTx[$k]['items']) < 8) $byTx[$k]['items'][] = $r->name . ' x' . (float) $r->quantity;
        }
        return array_merge($out, array_values($byTx));
    }

    public static function summary(array $warnings)
    {
        $parts = [];
        foreach ($warnings as $w) {
            $what = $w['type'] === 'ref' ? 'same ref on' : ($w['type'] === 'received' ? 'same items received on' : 'same items on open order');
            $parts[] = $what . ' #' . $w['transaction_id'];
        }
        return implode('; ', $parts);
    }
}
