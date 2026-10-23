<?php

namespace App\Services;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Golden's Whatnot sales (Sarah 2026-10-23).
 *
 * Golden sells our stock on his Whatnot livestream and hands in a handwritten
 * commission sheet every 2 months. Store staff often forget to ring his sales
 * into the ERP, so items stay on the website and we can't see how far below
 * sticker he sold them. He gets a private link (no ERP login, he must not see
 * the rest of the business) where he looks items up by artist/title (barcodes
 * are often faded), marks them sold at the Whatnot price, and sees only his
 * own sales and commission. Owners see every Whatnot sale vs sticker on
 * /reports/whatnot-seller.
 *
 * Settings live in a JSON file (no migration needed), same as the time clock.
 */
class WhatnotSeller
{
    const SETTINGS_FILE = 'whatnot-seller/settings.json';
    const NOTE_TAG = 'WHATNOT-SELLER';
    const HOURS_FILE = 'whatnot-seller/hours.json';

    public static function settings(): array
    {
        $s = [];
        try {
            if (Storage::disk('local')->exists(self::SETTINGS_FILE)) {
                $s = json_decode(Storage::disk('local')->get(self::SETTINGS_FILE), true) ?: [];
            }
        } catch (\Throwable $e) {
            $s = [];
        }
        return array_merge([
            'business_id'        => null,
            'token'              => null,
            'seller_name'        => 'Golden',
            'user_id'            => null,   // ERP user the sales are credited to (optional)
            'per_item'           => 0.50,   // Sarah 10/23: $0.50 per item sold
            'hourly_rate'        => 18,     // plus $18/hour on stream
            'min_pct_of_sticker' => 70,     // below this % of sticker = flagged
            'min_days_in_store'  => 30,     // items newer than this stay in the store
            'paid_through'       => null,   // Y-m-d, last day already paid out
        ], $s);
    }

    public static function saveSettings(array $s): void
    {
        Storage::disk('local')->put(self::SETTINGS_FILE, json_encode($s, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }

    public static function tokenValid($token): bool
    {
        $s = self::settings();
        return !empty($s['token']) && is_string($token) && hash_equals($s['token'], $token);
    }

    public static function now(): Carbon
    {
        return Carbon::now('America/Los_Angeles');
    }

    /**
     * In-stock items matching an artist/title/barcode search, with the rule
     * checks Golden sees before he puts something on the stream.
     */
    public static function search(int $businessId, string $q): array
    {
        $q = trim($q);
        if (mb_strlen($q) < 2) {
            return [];
        }
        $words = array_slice(array_filter(preg_split('/\s+/', mb_strtolower($q))), 0, 6);
        $s = self::settings();

        $rows = DB::table('products as p')
            ->join('variations as v', 'v.product_id', '=', 'p.id')
            ->join('variation_location_details as vld', 'vld.variation_id', '=', 'v.id')
            ->join('business_locations as bl', 'bl.id', '=', 'vld.location_id')
            ->leftJoin('categories as c', 'c.id', '=', 'p.category_id')
            ->where('p.business_id', $businessId)
            ->whereNull('v.deleted_at')
            ->where('vld.qty_available', '>', 0)
            ->where(function ($w) use ($q, $words) {
                $w->where('v.sub_sku', $q)->orWhere('p.sku', $q)
                  ->orWhere(function ($ww) use ($words) {
                      foreach ($words as $word) {
                          $like = '%' . $word . '%';
                          $ww->where(function ($x) use ($like) {
                              $x->whereRaw('LOWER(p.name) LIKE ?', [$like])
                                ->orWhereRaw("LOWER(COALESCE(p.artist,'')) LIKE ?", [$like]);
                          });
                      }
                  });
            })
            ->orderByDesc('p.id')
            ->limit(40)
            ->get([
                'p.id as product_id', 'v.id as variation_id', 'p.name', 'p.artist', 'p.sku', 'v.sub_sku',
                'p.created_at', 'v.sell_price_inc_tax as price', 'vld.qty_available as qty',
                'vld.location_id', 'bl.name as location_name', 'c.name as category',
            ]);

        $out = [];
        foreach ($rows as $r) {
            $days = $r->created_at ? Carbon::parse($r->created_at)->diffInDays(self::now()) : null;
            $tooNew = $days !== null && $days < (int) $s['min_days_in_store'];
            $out[] = [
                'product_id'    => (int) $r->product_id,
                'variation_id'  => (int) $r->variation_id,
                'location_id'   => (int) $r->location_id,
                'title'         => self::displayName($r->name, $r->artist),
                'category'      => (string) $r->category,
                'sku'           => (string) ($r->sub_sku ?: $r->sku),
                'price'         => round((float) $r->price, 2),
                'floor'         => round((float) $r->price * (float) $s['min_pct_of_sticker'] / 100, 2),
                'qty'           => (float) $r->qty,
                'store'         => (string) $r->location_name,
                'added'         => $r->created_at ? Carbon::parse($r->created_at)->format('m/d/y') : '',
                'too_new'       => $tooNew,
                'ok_after'      => ($tooNew && $r->created_at) ? Carbon::parse($r->created_at)->addDays((int) $s['min_days_in_store'])->format('m/d/y') : '',
            ];
        }
        return $out;
    }

    public static function displayName($name, $artist): string
    {
        $name = trim((string) $name);
        $artist = trim((string) $artist);
        if ($artist !== '' && stripos($name, $artist) === false) {
            return $artist . ' - ' . $name;
        }
        return $name;
    }

    /**
     * Every Whatnot item sold (line level) in the window, with sticker, % of
     * sticker, how long it was in the store, and who put it in the ERP. The
     * monthly-statement rows (no line items) are left out on purpose.
     */
    public static function soldLines(int $businessId, string $from, string $to): array
    {
        $s = self::settings();
        $rows = DB::table('transaction_sell_lines as tsl')
            ->join('transactions as t', 't.id', '=', 'tsl.transaction_id')
            ->leftJoin('products as p', 'p.id', '=', 'tsl.product_id')
            ->leftJoin('variations as v', 'v.id', '=', 'tsl.variation_id')
            ->leftJoin('business_locations as bl', 'bl.id', '=', 't.location_id')
            ->leftJoin('users as u', 'u.id', '=', 't.created_by')
            ->where('t.business_id', $businessId)
            ->where('t.type', 'sell')
            ->where('t.status', 'final')
            ->where('t.is_whatnot', 1)
            ->whereNull('tsl.parent_sell_line_id')
            ->whereDate('t.transaction_date', '>=', $from)
            ->whereDate('t.transaction_date', '<=', $to)
            ->orderByDesc('t.transaction_date')
            ->get([
                't.id as transaction_id', 't.invoice_no', 't.transaction_date', 't.staff_note',
                'bl.name as store', 'u.first_name as entered_by',
                'tsl.product_id', 'tsl.product_name as manual_name', 'tsl.quantity', 'tsl.quantity_returned',
                'tsl.unit_price_inc_tax', 'tsl.unit_price_before_discount', 'tsl.sell_line_note',
                'p.name', 'p.artist', 'p.created_at as product_created_at',
                'v.sell_price_inc_tax as sticker_now', 'v.default_purchase_price as cost',
            ]);

        $out = [];
        foreach ($rows as $r) {
            $qty = (float) $r->quantity - (float) $r->quantity_returned;
            if ($qty <= 0) {
                continue;
            }
            $sold = (float) $r->unit_price_inc_tax;
            $sticker = $r->product_id ? (float) $r->sticker_now : null;
            $pct = ($sticker && $sticker > 0) ? round($sold / $sticker * 100) : null;
            $days = $r->product_created_at ? Carbon::parse($r->product_created_at)->diffInDays(Carbon::parse($r->transaction_date)) : null;
            $flags = [];
            if ($pct !== null && $pct < (int) $s['min_pct_of_sticker']) {
                $flags[] = 'Sold at ' . $pct . '% of sticker';
            } elseif ($sticker && $sold < $sticker - 0.009) {
                $flags[] = 'Under sticker by $' . number_format($sticker - $sold, 2);
            }
            if ($days === 0) {
                $flags[] = 'Added to ERP the day it sold';
            } elseif ($days !== null && $days < (int) $s['min_days_in_store']) {
                $flags[] = 'Only ' . $days . ' day' . ($days === 1 ? '' : 's') . ' in store';
            }
            if ($r->cost !== null && (float) $r->cost > 0 && $sold < (float) $r->cost) {
                $flags[] = 'Below our cost';
            }
            if (!$r->product_id) {
                $flags[] = 'Not linked to a product';
            }
            $out[] = [
                'transaction_id' => (int) $r->transaction_id,
                'invoice_no'     => (string) $r->invoice_no,
                'date'           => Carbon::parse($r->transaction_date)->format('m/d/y'),
                'date_raw'       => Carbon::parse($r->transaction_date)->format('Y-m-d'),
                'title'          => $r->product_id ? self::displayName($r->name, $r->artist) : (string) ($r->manual_name ?: 'Item'),
                'store'          => (string) $r->store,
                'qty'            => $qty,
                'sold'           => round($sold, 2),
                'sticker'        => $sticker !== null ? round($sticker, 2) : null,
                'pct'            => $pct,
                'days_in_store'  => $days,
                'cost'           => $r->cost !== null ? round((float) $r->cost, 2) : null,
                'by_golden'      => strpos((string) $r->staff_note, self::NOTE_TAG) === 0,
                'entered_by'     => strpos((string) $r->staff_note, self::NOTE_TAG) === 0 ? $s['seller_name'] : (string) $r->entered_by,
                'note'           => (string) $r->sell_line_note,
                'flags'          => $flags,
            ];
        }
        return $out;
    }

    /** Whatnot monthly statement totals typed in at /whatnot/import-statement. */
    public static function statementTotals(int $businessId, string $from, string $to): array
    {
        return DB::table('transactions')
            ->where('business_id', $businessId)
            ->where('type', 'sell')
            ->where('ref_no', 'like', 'WNS-%-REVENUE')
            ->whereDate('transaction_date', '>=', $from)
            ->whereDate('transaction_date', '<=', $to)
            ->orderBy('transaction_date')
            ->get(['transaction_date', 'final_total'])
            ->map(function ($r) {
                return ['month' => Carbon::parse($r->transaction_date)->format('Y-m'), 'total' => (float) $r->final_total];
            })->all();
    }

    /** Shows Golden logged: [{id, date (Y-m-d), hours, note, logged_at}]. */
    public static function hours(): array
    {
        try {
            if (Storage::disk('local')->exists(self::HOURS_FILE)) {
                return json_decode(Storage::disk('local')->get(self::HOURS_FILE), true) ?: [];
            }
        } catch (\Throwable $e) {
        }
        return [];
    }

    public static function saveHours(array $rows): void
    {
        usort($rows, function ($a, $b) { return strcmp($b['date'], $a['date']); });
        Storage::disk('local')->put(self::HOURS_FILE, json_encode(array_values($rows), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }

    public static function hoursBetween(string $from, string $to): array
    {
        return array_values(array_filter(self::hours(), function ($h) use ($from, $to) {
            return $h['date'] >= $from && $h['date'] <= $to;
        }));
    }

    /** $0.50 per item + $18 per hour (Sarah 2026-10-23). */
    public static function pay(float $items, float $hours): array
    {
        $s = self::settings();
        $itemPay = round($items * (float) $s['per_item'], 2);
        $hourPay = round($hours * (float) $s['hourly_rate'], 2);
        return ['items' => $items, 'hours' => $hours, 'item_pay' => $itemPay, 'hour_pay' => $hourPay, 'total' => round($itemPay + $hourPay, 2)];
    }
}
