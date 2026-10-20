<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/**
 * Real barcodes for sealed items (Sarah, 2026-10-19). 4,704 sealed vinyl and
 * 994 sealed CDs had no UPC in the SKU (made-up SKU = product id, or text),
 * so they can't be scanned. Each one linked to a Discogs release gets the
 * release's Barcode identifier.
 *
 * Two steps, like every bulk fix here: scan() only reads Discogs and saves a
 * proposal per product (scheduled, sealed-barcodes:scan); apply() writes
 * them when Sarah clicks Apply at /products/sealed-barcodes. Every write
 * keeps the old SKU in the log so it can be undone.
 */
class SealedBarcodeBackfillService
{
    const CATEGORIES = ['Vinyl - Sealed', 'CD - Sealed', 'Cassettes - Sealed'];
    const LOG = 'sealed-barcodes/log.json';

    public static function load(): array
    {
        $f = storage_path('app/' . self::LOG);
        return is_file($f) ? (json_decode((string) file_get_contents($f), true) ?: []) : [];
    }

    private static function save(array $log): void
    {
        $f = storage_path('app/' . self::LOG);
        if (!is_dir(dirname($f))) @mkdir(dirname($f), 0775, true);
        file_put_contents($f, json_encode($log));
    }

    /** SKU is a real barcode: 8-14 digits and not just the product id. */
    public static function isRealBarcode($sku, int $id): bool
    {
        $sku = trim((string) $sku);
        return preg_match('/^\d{8,14}$/', $sku) && ltrim($sku, '0') !== (string) $id;
    }

    /** Sealed, active, linked to Discogs, no real barcode. */
    public static function eligible(int $businessId)
    {
        return DB::table('products as p')
            ->join('categories as c', 'c.id', '=', 'p.category_id')
            ->where('p.business_id', $businessId)
            ->where('p.is_inactive', 0)
            ->whereIn('c.name', self::CATEGORIES)
            ->where('p.discogs_release_id', '>', 0)
            ->select('p.id', 'p.name', 'p.sku', 'p.discogs_release_id', 'c.name as cat')
            ->orderByDesc('p.id')
            ->get()
            ->filter(fn($p) => !self::isRealBarcode($p->sku, (int) $p->id))
            ->values();
    }

    /** Look up to $limit unchecked products on Discogs (read-only for the ERP). */
    public static function scan(int $businessId, int $limit): array
    {
        $svc = new DiscogsService($businessId);
        if (!$svc->isConfigured()) return ['ok' => false, 'error' => 'Discogs not configured'];
        $log = self::load();
        $todo = self::eligible($businessId)->filter(fn($p) => !isset($log[$p->id]) || ($log[$p->id]['status'] ?? '') === 'error')->take($limit);
        $n = ['checked' => 0, 'found' => 0, 'failed' => 0];
        foreach ($todo as $p) {
            $resp = $svc->getReleaseById($p->discogs_release_id);
            usleep(1100000); // ~54/min, under Discogs' 60
            $n['checked']++;
            if (!empty($resp['error'])) {
                $n['failed']++;
                $log[$p->id] = ['status' => 'error', 'name' => $p->name, 'old' => $p->sku, 'at' => date('c')];
                continue;
            }
            $codes = [];
            foreach (($resp['data']->identifiers ?? []) as $idf) {
                if (strcasecmp((string) ($idf->type ?? ''), 'Barcode') !== 0) continue;
                $d = preg_replace('/\D/', '', (string) ($idf->value ?? ''));
                if (in_array(strlen($d), [12, 13], true) && self::checksumOk($d)) $codes[$d] = true;
            }
            // Same code written as UPC (12) and EAN (13, leading 0) counts once
            $norm = [];
            foreach (array_keys($codes) as $c) $norm[strlen($c) === 13 && $c[0] === '0' ? substr($c, 1) : $c] = true;
            $norm = array_keys($norm);
            if (count($norm) === 1) {
                $n['found']++;
                $log[$p->id] = ['status' => 'found', 'new' => (string) $norm[0], 'name' => $p->name, 'cat' => $p->cat, 'old' => $p->sku, 'release' => (int) $p->discogs_release_id, 'at' => date('c')];
            } else {
                $log[$p->id] = ['status' => $norm ? 'several' : 'none', 'codes' => $norm, 'name' => $p->name, 'old' => $p->sku, 'release' => (int) $p->discogs_release_id, 'at' => date('c')];
            }
            if ($n['checked'] % 25 === 0) self::save($log);
        }
        self::save($log);
        return ['ok' => true] + $n;
    }

    /** GTIN check digit (UPC-A 12 / EAN-13). */
    public static function checksumOk(string $d): bool
    {
        $digits = array_map('intval', str_split($d));
        $check = array_pop($digits);
        $sum = 0;
        foreach (array_reverse($digits) as $i => $v) $sum += $v * ($i % 2 === 0 ? 3 : 1);
        return (10 - $sum % 10) % 10 === $check;
    }

    /** Proposals ready to write, with a reason when one can't be written. */
    public static function proposals(int $businessId): array
    {
        $out = [];
        $log = self::load();
        $found = array_filter($log, fn($r) => ($r['status'] ?? '') === 'found');
        if (!$found) return [];
        $codes = array_values(array_unique(array_column($found, 'new')));
        // Barcode already on another product = a duplicate listing (merge it instead)
        $taken = [];
        foreach (array_chunk($codes, 1000) as $chunk) {
            $variants = [];
            foreach ($chunk as $c) { $variants[] = $c; $variants[] = '0' . $c; }
            foreach (DB::table('products')->where('business_id', $businessId)->whereIn('sku', $variants)->get(['id', 'sku']) as $r) {
                $taken[ltrim($r->sku, '0')][] = (int) $r->id;
            }
        }
        $dupInBatch = array_count_values(array_column($found, 'new'));
        $cur = DB::table('products')->whereIn('id', array_keys($found))->pluck('sku', 'id');
        foreach ($found as $id => $r) {
            $other = array_values(array_diff($taken[ltrim($r['new'], '0')] ?? [], [(int) $id]));
            $why = '';
            if ($other) $why = 'Barcode already on product ' . implode(', ', $other) . ' (duplicate listing)';
            elseif ($dupInBatch[$r['new']] > 1) $why = 'Same barcode found for ' . $dupInBatch[$r['new']] . ' products (duplicate listings)';
            elseif ((string) ($cur[$id] ?? '') !== (string) $r['old']) $why = 'SKU changed since the scan';
            $out[] = ['id' => (int) $id] + $r + ['blocked' => $why];
        }
        return $out;
    }

    /** Write every unblocked proposal. Old SKU stays in the log ('applied'). */
    public static function apply(int $businessId, string $by): int
    {
        $log = self::load();
        $done = 0;
        foreach (self::proposals($businessId) as $p) {
            if ($p['blocked'] !== '') continue;
            DB::transaction(function () use ($p) {
                DB::table('products')->where('id', $p['id'])->where('sku', $p['old'])->update(['sku' => $p['new'], 'updated_at' => now()]);
                DB::table('variations')->where('product_id', $p['id'])->where('sub_sku', $p['old'])->update(['sub_sku' => $p['new'], 'updated_at' => now()]);
            });
            $log[$p['id']]['status'] = 'applied';
            $log[$p['id']]['applied_by'] = $by;
            $log[$p['id']]['applied_at'] = date('c');
            $done++;
        }
        self::save($log);
        return $done;
    }

    /** Put the old SKUs back on everything applied. */
    public static function undo(): int
    {
        $log = self::load();
        $n = 0;
        foreach ($log as $id => $r) {
            if (($r['status'] ?? '') !== 'applied') continue;
            DB::table('products')->where('id', $id)->where('sku', $r['new'])->update(['sku' => $r['old'], 'updated_at' => now()]);
            DB::table('variations')->where('product_id', $id)->where('sub_sku', $r['new'])->update(['sub_sku' => $r['old'], 'updated_at' => now()]);
            $log[$id]['status'] = 'found';
            $n++;
        }
        self::save($log);
        return $n;
    }
}
