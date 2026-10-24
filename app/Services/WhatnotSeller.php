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
    const ORDERS_FILE = 'whatnot-seller/whatnot-orders.json';
    const STREAMS_FILE = 'whatnot-seller/streams.json';

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
            'premium_pct'        => 10,     // sold more than 10% over sticker...
            'premium_per_item'   => 0.65,   // ...pays 65 cents instead (Sarah 10/23)
            'premium_start'      => '2026-10-26', // the 65 cents starts next week
            // Last payment Jon made (Venmo 10/15, $438.80 = 24h01m x $18 + $5
            // tips for 9/28-10/10; no per-item pay before that).
            'paid_thru'          => '2026-10-10',
            'last_paid_on'       => '2026-10-15',
            'last_paid_amount'   => 438.80,
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
            // Bag fees ride along on some POS rings; they aren't items he sold.
            if (preg_match('/\bbag fee\b/i', (string) ($r->product_id ? $r->name : $r->manual_name))) {
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

    /** Whatnot show times: {key: {start: 'Y-m-d H:i' (LA), minutes}}. */
    public static function streams(): array
    {
        try {
            if (Storage::disk('local')->exists(self::STREAMS_FILE)) {
                return json_decode(Storage::disk('local')->get(self::STREAMS_FILE), true) ?: [];
            }
        } catch (\Throwable $e) {
        }
        return [];
    }

    /**
     * His paid hours are the Whatnot stream length (Sarah 2026-10-23), so this
     * lists the shows in the window as {date, hours, note}.
     */
    public static function hoursBetween(string $from, string $to): array
    {
        $out = [];
        foreach (self::streams() as $st) {
            $date = substr($st['start'], 0, 10);
            if ($date < $from || $date > $to) {
                continue;
            }
            $out[] = [
                'date' => $date,
                'hours' => round($st['minutes'] / 60, 2),
                'minutes' => (int) $st['minutes'],
                'url' => !empty($st['id']) ? 'https://www.whatnot.com/dashboard/analytics/shows/' . $st['id'] : null,
                'note' => 'Whatnot show ' . Carbon::parse($st['start'])->format('g:ia') . ', ' . intdiv($st['minutes'], 60) . 'h ' . str_pad($st['minutes'] % 60, 2, '0', STR_PAD_LEFT) . 'm',
            ];
        }
        usort($out, function ($a, $b) { return strcmp($b['date'], $a['date']); });
        return $out;
    }

    /** Tips buyers left on his shows (from the Whatnot reports), passed to him. */
    public static function tipsBetween(string $from, string $to): float
    {
        $t = 0.0;
        foreach (self::whatnotOrders() as $o) {
            if ($o['type'] === 'TIP' && $o['date'] >= $from && $o['date'] <= $to) {
                $t += (float) $o['earned'];
            }
        }
        return round($t, 2);
    }

    /** Everything he's owed for a window: items, show hours, tips. */
    public static function owed(int $businessId, string $from, string $to): array
    {
        $lines = self::soldLines($businessId, $from, $to);
        $shows = self::hoursBetween($from, $to);
        $minutes = array_sum(array_column($shows, 'minutes'));
        $pay = self::pay($lines, round($minutes / 60, 4));
        $pay['minutes'] = $minutes;
        $pay['tips'] = self::tipsBetween($from, $to);
        $pay['total'] = round($pay['total'] + $pay['tips'], 2);
        $pay['shows'] = count($shows);
        return $pay;
    }

    public static function hoursLabel(int $minutes): string
    {
        return intdiv($minutes, 60) . 'h ' . str_pad($minutes % 60, 2, '0', STR_PAD_LEFT) . 'm';
    }

    /** What one sold line pays him. */
    public static function linePay(array $l): float
    {
        $s = self::settings();
        return round($l['qty'] * (float) (self::isPremium($l) ? $s['premium_per_item'] : $s['per_item']), 2);
    }

    /** $0.50 per item, $0.65 per premium item, + $18 per hour (Sarah 2026-10-23). */
    public static function pay(array $lines, float $hours): array
    {
        $s = self::settings();
        $items = 0; $premium = 0;
        foreach ($lines as $l) {
            $items += $l['qty'];
            if (self::isPremium($l)) {
                $premium += $l['qty'];
            }
        }
        $itemPay = round(($items - $premium) * (float) $s['per_item'] + $premium * (float) $s['premium_per_item'], 2);
        $hourPay = round($hours * (float) $s['hourly_rate'], 2);
        return ['items' => $items, 'premium_items' => $premium, 'hours' => $hours, 'item_pay' => $itemPay, 'hour_pay' => $hourPay, 'total' => round($itemPay + $hourPay, 2)];
    }

    /** Rows imported from Whatnot's weekly order report CSVs, keyed by ledger id. */
    public static function whatnotOrders(): array
    {
        try {
            if (Storage::disk('local')->exists(self::ORDERS_FILE)) {
                return json_decode(Storage::disk('local')->get(self::ORDERS_FILE), true) ?: [];
            }
        } catch (\Throwable $e) {
        }
        return [];
    }

    /**
     * Parse one weekly order report CSV (Seller Hub > Finances > Statements &
     * Reports) and merge it in. Re-importing the same week changes nothing.
     * Returns [added, skipped].
     */
    public static function importWhatnotCsv(string $path): array
    {
        $fh = fopen($path, 'r');
        if (!$fh) {
            return [0, 0];
        }
        $head = fgetcsv($fh);
        if ($head && in_array('STREAM_START_LA', $head, true)) {
            $all = self::streams(); $added = 0; $skipped = 0;
            while (($row = fgetcsv($fh)) !== false) {
                if (count($row) !== count($head)) { continue; }
                $r = array_combine($head, $row);
                $start = Carbon::parse($r['STREAM_START_LA'])->format('Y-m-d H:i');
                if (isset($all[$start])) { $skipped++; }
                else { $added++; }
                $all[$start] = ['start' => $start, 'minutes' => (int) $r['MINUTES'], 'id' => preg_replace('/[^a-f0-9-]/', '', (string) ($r['SHOW_ID'] ?? ''))];
            }
            fclose($fh);
            Storage::disk('local')->put(self::STREAMS_FILE, json_encode($all, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            return [$added, $skipped];
        }
        if (!$head || !in_array('ORDER_ID', $head, true) || !in_array('ORIGINAL_ITEM_PRICE', $head, true)) {
            fclose($fh);
            throw new \RuntimeException('That is not a Whatnot weekly order report.');
        }
        $all = self::whatnotOrders();
        $added = 0; $skipped = 0;
        while (($row = fgetcsv($fh)) !== false) {
            if (count($row) !== count($head)) {
                continue;
            }
            $r = array_combine($head, $row);
            $key = ($r['LEDGER_TRANSACTION_ID'] ?? '') ?: (($r['ORDER_ID'] ?? '') . '-' . ($r['TRANSACTION_TYPE'] ?? ''));
            if (isset($all[$key])) {
                $skipped++;
                continue;
            }
            $placed = ($r['ORDER_PLACED_AT_UTC'] ?? '') ?: ($r['TRANSACTION_COMPLETED_AT_UTC'] ?? '');
            $all[$key] = [
                'type'    => ($r['TRANSACTION_TYPE'] ?? ''),
                'order'   => ($r['ORDER_ID'] ?? ''),
                'date'    => $placed ? Carbon::parse($placed, 'UTC')->setTimezone('America/Los_Angeles')->format('Y-m-d') : '',
                'title'   => ($r['LISTING_TITLE'] ?? ''),
                'show'    => trim(($r['LIVESTREAM_TITLE'] ?? '')),
                'qty'     => (float) (($r['QUANTITY_SOLD'] ?? '') ?: 0),
                'price'   => (float) (($r['ORIGINAL_ITEM_PRICE'] ?? '') ?: 0),
                'paid'    => (float) (($r['BUYER_PAID'] ?? '') ?: 0),
                'earned'  => (float) (($r['TRANSACTION_AMOUNT'] ?? '') ?: 0),
                'buyer'   => ($r['BUYER_NAME'] ?? ''),
            ];
            $added++;
        }
        fclose($fh);
        Storage::disk('local')->put(self::ORDERS_FILE, json_encode($all, JSON_UNESCAPED_SLASHES));
        return [$added, $skipped];
    }

    /**
     * Day by day: what Whatnot says sold (count + real prices) vs what's in
     * the ERP (count + sticker). If Whatnot's total is lower than the ERP
     * sticker total for the same items, he sold under sticker that day.
     */
    public static function dailyCheck(array $erpLines, string $from, string $to): array
    {
        $days = [];
        foreach (self::whatnotOrders() as $o) {
            if ($o['date'] < $from || $o['date'] > $to) {
                continue;
            }
            $sign = 0;
            if ($o['type'] === 'ORDER_EARNINGS') { $sign = 1; }
            elseif (stripos($o['type'], 'REFUND') !== false) { $sign = -1; }
            if (!$sign) {
                continue;
            }
            $d = &$days[$o['date']];
            $d['wn_items'] = ($d['wn_items'] ?? 0) + $sign * max(1, $o['qty']);
            $d['wn_total'] = ($d['wn_total'] ?? 0) + $sign * $o['price'];
            $d['shows'][$o['show']] = true;
            unset($d);
        }
        foreach ($erpLines as $l) {
            $d = &$days[$l['date_raw']];
            $d['erp_items'] = ($d['erp_items'] ?? 0) + $l['qty'];
            $d['erp_total'] = ($d['erp_total'] ?? 0) + ($l['sticker'] ?? $l['sold']) * $l['qty'];
            unset($d);
        }
        krsort($days);
        $out = [];
        foreach ($days as $date => $d) {
            $out[] = [
                'date'      => Carbon::parse($date)->format('m/d/y'),
                'wn_items'  => isset($d['wn_items']) ? (int) $d['wn_items'] : null,
                'wn_total'  => isset($d['wn_total']) ? round($d['wn_total'], 2) : null,
                'erp_items' => (int) ($d['erp_items'] ?? 0),
                'erp_total' => round($d['erp_total'] ?? 0, 2),
                'shows'     => implode(', ', array_keys($d['shows'] ?? [])),
            ];
        }
        return $out;
    }

    public static function whatnotImportedRange(): ?array
    {
        $dates = array_filter(array_column(self::whatnotOrders(), 'date'));
        return $dates ? [min($dates), max($dates)] : null;
    }
}
