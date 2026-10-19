<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Pending orders that need pulling at Pico, posted to #pico with whoever is
 * on staff there tagged (Sarah, 2026-10-14). Sent with each shipping summary
 * (Nick's clock-in / clock-out). An item is a Pico pull when the order line
 * ships from Pico (two store carts), or for older lines and Discogs, when
 * the ERP only has it stocked at Pico (Discogs codes like "pico111" too).
 * Plus Pico pickups not marked ready. Never throws.
 */
class PicoPullsNotifier
{
    const TO_SHIP_STATUSES = ['processing', 'pending', 'flag', ''];
    const PICKUP_OPEN_STATUSES = ['processing', 'pending', 'flag', ''];
    const TO_SHIP_URL = 'https://playlist.nivessa.com/website-orders?tab=to_ship';
    const PICKUP_URL = 'https://playlist.nivessa.com/website-orders?tab=pickup';

    /** @return array ['posted' => bool, 'reason' => string] */
    public static function post(string $when = ''): array
    {
        // Turned off 2026-10-18 (Sarah: "pls stop these updates, long and confusing").
        // build() still feeds the preview page; nothing is posted to #pico.
        return ['posted' => false, 'reason' => 'Pico pulls posts are turned off'];
        try {
            $text = self::build($when);
            if ($text === null) {
                return ['posted' => false, 'reason' => 'nothing to pull at Pico'];
            }
            $j = self::api('POST', '/erp/slack-store-post', ['store' => 'pico', 'text' => $text], 15);
            if (!is_array($j) || empty($j['success'])) {
                \Log::warning('Pico pulls post failed', ['error' => is_array($j) ? ($j['error'] ?? $j['message'] ?? '') : 'no response']);
                return ['posted' => false, 'reason' => 'Slack post failed'];
            }
            return ['posted' => true, 'reason' => ''];
        } catch (\Throwable $e) {
            \Log::warning('Pico pulls post error: ' . $e->getMessage());
            return ['posted' => false, 'reason' => $e->getMessage()];
        }
    }

    /** Message text, or null when nothing needs pulling at Pico. */
    public static function build(string $when = ''): ?string
    {
        $web = self::api('GET', '/erp/orders/console?limit=300&payment_status=completed', null, 30);
        if (!is_array($web) || ($web['success'] ?? true) === false) {
            throw new \RuntimeException('could not load website orders');
        }
        $orders = $web['data'] ?? [];
        $dis = self::api('GET', '/erp/discogs/to-ship', null, 20);
        $discogs = (is_array($dis) && ($dis['success'] ?? false) === true) ? ($dis['data'] ?? []) : [];

        $ship = [];
        $pickups = [];
        foreach ($orders as $o) {
            if (!empty($o['archived']) || ($o['payment_status'] ?? '') !== 'completed') continue;
            $items = array_values(array_filter($o['items'] ?? [], function ($it) {
                return empty($it['is_gift_card']) && !(empty($it['product_id']) && !empty($it['gift_card_amount'])) && empty($it['cancelled']);
            }));
            if (!$items) continue;
            $preorder = false;
            foreach ($items as $it) {
                if (!empty($it['product_id']['isPreorder'])) $preorder = true;
            }
            if ($preorder) continue;
            $status = (string) ($o['order_status'] ?? '');
            $fm = $o['fulfillment_method'] ?? null;
            if ($fm === 'pickup') {
                if (strtolower((string) ($o['pickup_location'] ?? '')) === 'pico' && in_array($status, self::PICKUP_OPEN_STATUSES, true)) {
                    $pickups[] = ['o' => $o, 'items' => $items];
                }
            } elseif ($fm === 'shipping' && in_array($status, self::TO_SHIP_STATUSES, true)) {
                $ship[] = ['o' => $o, 'items' => $items];
            }
        }

        // ERP stock per product, for lines with no ship_from and Discogs.
        $pids = [];
        foreach ($ship as $r) foreach ($r['items'] as $it) {
            $pid = (int) ($it['product_id']['posProductId'] ?? 0);
            if ($pid) $pids[$pid] = true;
        }
        foreach ($pickups as $r) foreach ($r['items'] as $it) {
            $pid = (int) ($it['product_id']['posProductId'] ?? 0);
            if ($pid) $pids[$pid] = true;
        }
        foreach ($discogs as $o) foreach (($o['items'] ?? []) as $it) {
            $pid = (int) ($it['erp_product_id'] ?? 0);
            if ($pid) $pids[$pid] = true;
        }
        $stock = self::stock(array_keys($pids));

        $shipRows = [];
        $shipItems = 0;
        foreach ($ship as $r) {
            $o = $r['o'];
            $pico = [];
            $total = 0;
            foreach ($r['items'] as $it) {
                $q = max(1, (int) ($it['quantity'] ?? 1));
                $total += $q;
                $pid = (int) ($it['product_id']['posProductId'] ?? 0);
                $code = trim((string) ($it['product_id']['discogsLocation'] ?? '')) ?: ($stock[$pid]['bin'] ?? '');
                $mapped = self::binStore($code);
                if ($mapped !== null ? $mapped === 'pico' : self::isPico($it['ship_from'] ?? null, $stock[$pid] ?? null)) {
                    $pico[] = ['name' => self::itemName($it), 'qty' => $q, 'bin' => $code];
                }
            }
            if (!$pico) continue;
            $n = array_sum(array_column($pico, 'qty'));
            $shipItems += $n;
            $shipRows[] = [
                'at' => strtotime($o['createdAt'] ?? '') ?: time(),
                'head' => '<' . self::TO_SHIP_URL . '&q=' . ($o['_id'] ?? '') . '|#' . ($o['orderNumber'] ?? '') . '> ' . self::esc(self::buyer($o))
                    . ' · ' . ($n === $total ? self::itemsN($n) : "{$n} of {$total} items at Pico") . ' · ' . self::days($o['createdAt'] ?? null),
                'items' => $pico,
            ];
        }
        foreach ($discogs as $o) {
            $pico = [];
            $total = 0;
            foreach (($o['items'] ?? []) as $it) {
                $q = max(1, (int) ($it['quantity'] ?? 1));
                $total += $q;
                $code = strtolower(trim((string) ($it['location'] ?? '')));
                $pid = (int) ($it['erp_product_id'] ?? 0);
                $mapped = self::binStore($code);
                $isPico = $mapped !== null ? $mapped === 'pico' : ($code !== '' ? strpos($code, 'pico') === 0 : self::isPico(null, $stock[$pid] ?? null));
                if ($isPico) {
                    $pico[] = ['name' => (string) ($it['name'] ?? 'Item'), 'qty' => $q, 'bin' => $code !== '' ? $code : ($stock[$pid]['bin'] ?? '')];
                }
            }
            if (!$pico) continue;
            $n = array_sum(array_column($pico, 'qty'));
            $shipItems += $n;
            $shipRows[] = [
                'at' => strtotime($o['order_date'] ?? '') ?: time(),
                'head' => '<' . self::TO_SHIP_URL . '&q=' . urlencode((string) ($o['id'] ?? '')) . '|Discogs ' . ($o['id'] ?? '') . '> ' . self::esc((string) ($o['buyer'] ?? ''))
                    . ' · ' . ($n === $total ? self::itemsN($n) : "{$n} of {$total} items at Pico") . ' · ' . self::days($o['order_date'] ?? null),
                'items' => $pico,
            ];
        }
        usort($shipRows, function ($a, $b) { return $a['at'] <=> $b['at']; });

        $pickRows = [];
        foreach ($pickups as $r) {
            $o = $r['o'];
            $n = array_sum(array_map(function ($it) { return max(1, (int) ($it['quantity'] ?? 1)); }, $r['items']));
            $pickRows[] = [
                'at' => strtotime($o['createdAt'] ?? '') ?: time(),
                'head' => '<' . self::PICKUP_URL . '&q=' . ($o['_id'] ?? '') . '|#' . ($o['orderNumber'] ?? '') . '> ' . self::esc(self::buyer($o))
                    . ' · ' . self::itemsN($n) . ' · ' . self::days($o['createdAt'] ?? null) . ', mark ready when pulled',
                'items' => array_map(function ($it) use ($stock) {
                    $pid = (int) ($it['product_id']['posProductId'] ?? 0);
                    return ['name' => self::itemName($it), 'qty' => max(1, (int) ($it['quantity'] ?? 1)), 'bin' => $stock[$pid]['bin'] ?? ''];
                }, $r['items']),
            ];
        }
        usort($pickRows, function ($a, $b) { return $a['at'] <=> $b['at']; });

        if (!$shipRows && !$pickRows) {
            return null;
        }

        $now = \Carbon::now('America/Los_Angeles');
        $lines = ['*Pico pulls · ' . $now->format('D n/j') . ($when !== '' ? ' · ' . $when : '') . '*'];
        $tags = self::staffTags();
        $lines[] = ($tags ? implode(' ', $tags) . ' ' : '') . 'Please pull these at Pico.';
        if ($shipRows) {
            $lines[] = '*To ship:* ' . count($shipRows) . ' order' . (count($shipRows) === 1 ? '' : 's') . ' · ' . self::itemsN($shipItems);
            foreach ($shipRows as $r) self::pushRow($lines, $r);
        }
        if ($pickRows) {
            $lines[] = '*Pickups at Pico not marked ready:* ' . count($pickRows);
            foreach ($pickRows as $r) self::pushRow($lines, $r);
        }
        $lines[] = '<' . self::TO_SHIP_URL . '|To Ship tab> · <' . self::PICKUP_URL . '&location=pico|Pickup tab>';
        return implode("\n", $lines);
    }

    private static function pushRow(array &$lines, array $r): void
    {
        $lines[] = $r['head'];
        foreach ($r['items'] as $it) {
            $lines[] = '      ' . self::esc($it['name']) . ($it['qty'] > 1 ? ' x' . $it['qty'] : '') . ($it['bin'] !== '' ? ' · bin ' . self::esc($it['bin']) : '');
        }
    }

    /** Bin code => store from Warehouse Locations, loaded once per run. */
    private static $binStores = null;

    /**
     * The store a bin code is mapped to in Warehouse Locations ('pico' or
     * 'hollywood'), or null when the code isn't mapped. The bin map wins:
     * most "Pico##" bins are at Hollywood (Sarah, 2026-10-15).
     */
    public static function binStore($code): ?string
    {
        $code = strtolower(trim((string) $code));
        if ($code === '') return null;
        if (self::$binStores === null) {
            self::$binStores = [];
            try {
                $resp = \App\Services\WebsiteBridge::admin('GET', '/warehouse/locations?active=true', null, 30);
                foreach (($resp['data'] ?? []) as $l) {
                    $c = strtolower(trim((string) ($l['discogs_code'] ?? '')));
                    $st = strtolower((string) ($l['store'] ?? ''));
                    if ($c !== '' && in_array($st, ['pico', 'hollywood'], true)) self::$binStores[$c] = $st;
                }
            } catch (\Throwable $e) {
                \Log::warning('PicoPullsNotifier bin map failed: ' . $e->getMessage());
            }
        }
        return self::$binStores[$code] ?? null;
    }

    /** Pico pull? ship_from wins; else the ERP has it at Pico only. */
    public static function isPico($shipFrom, ?array $loc): bool
    {
        $sf = strtolower(trim((string) $shipFrom));
        if ($sf !== '') {
            return $sf === 'pico';
        }
        if (!$loc) {
            return false;
        }
        $where = !empty($loc['stores']) ? array_keys($loc['stores']) : array_keys($loc['stocked'] ?? []);
        return $where === ['Pico'];
    }

    /** [product id => ['stores' => [label => qty], 'stocked' => [label => true], 'bin' => '']] */
    private static function stock(array $ids): array
    {
        if (!$ids) return [];
        $rows = DB::table('products as p')
            ->leftJoin('variation_location_details as vld', 'vld.product_id', '=', 'p.id')
            ->leftJoin('business_locations as bl', 'bl.id', '=', 'vld.location_id')
            ->whereIn('p.id', $ids)
            ->select('p.id', 'p.bin_position', 'p.listing_location', 'bl.name as loc_name', 'vld.qty_available')
            ->get();
        $map = [];
        foreach ($rows as $r) {
            $pid = (int) $r->id;
            if (!isset($map[$pid])) {
                $bin = trim((string) ($r->bin_position ?? ''));
                if ($bin === '') $bin = trim((string) ($r->listing_location ?? ''));
                $map[$pid] = ['stores' => [], 'stocked' => [], 'bin' => $bin];
            }
            if (empty($r->loc_name)) continue;
            $name = (string) $r->loc_name;
            if (stripos($name, 'warehouse') !== false) $label = 'Warehouse';
            elseif (stripos($name, 'pico') !== false) $label = 'Pico';
            elseif (stripos($name, 'hollywood') !== false) $label = 'Hollywood';
            else $label = $name;
            $map[$pid]['stocked'][$label] = true;
            if ((float) ($r->qty_available ?? 0) > 0) {
                $map[$pid]['stores'][$label] = ($map[$pid]['stores'][$label] ?? 0) + (float) $r->qty_available;
            }
        }
        return $map;
    }

    /**
     * Slack tags for who is on staff at Pico: clocked in there now, plus
     * anyone on the Sling schedule at Pico right now. If nobody, whoever is
     * scheduled at Pico later today.
     */
    private static function staffTags(): array
    {
        try {
            $businessId = TimeClockService::businessId();
            $slack = TimeClockService::slackIds($businessId);
            $users = [];
            $picoLocs = [];
            foreach (TimeClockService::locations($businessId) as $id => $name) {
                if (stripos((string) $name, 'pico') !== false) $picoLocs[] = (int) $id;
            }
            if ($picoLocs && Schema::hasTable('time_clock_punches')) {
                foreach (DB::table('time_clock_punches')->whereIn('location_id', $picoLocs)->whereNull('clock_out_at')->whereNull('voided_at')
                    ->where('clock_in_at', '>=', \Carbon::now('America/Los_Angeles')->subHours(16)->format('Y-m-d H:i:s'))
                    ->pluck('user_id') as $uid) {
                    $users[(int) $uid] = true;
                }
            }
            if (Schema::hasTable('sling_shifts')) {
                $tz = config('app.timezone');
                $now = \Carbon::now($tz);
                $base = \App\SlingShift::where('event_type', \App\SlingShift::TYPE_SHIFT)
                    ->where('location_name', 'like', '%pico%')->whereNotNull('erp_user_id');
                foreach ((clone $base)->where('dtstart', '<=', $now)->where('dtend', '>', $now)->pluck('erp_user_id') as $uid) {
                    $users[(int) $uid] = true;
                }
                if (!$users) {
                    $end = \Carbon::now('America/Los_Angeles')->endOfDay()->setTimezone($tz);
                    foreach ((clone $base)->where('dtstart', '>', $now)->where('dtstart', '<=', $end)->pluck('erp_user_id') as $uid) {
                        $users[(int) $uid] = true;
                    }
                }
            }
            $tags = [];
            foreach (array_keys($users) as $uid) {
                if (!empty($slack[$uid])) $tags[$slack[$uid]] = '<@' . $slack[$uid] . '>';
            }
            return array_values($tags);
        } catch (\Throwable $e) {
            \Log::warning('Pico pulls staff tags failed: ' . $e->getMessage());
            return [];
        }
    }

    private static function itemName(array $it): string
    {
        $p = is_array($it['product_id'] ?? null) ? $it['product_id'] : [];
        $name = trim((string) ($p['name'] ?? ($it['product_name'] ?? 'Item')));
        $artist = trim((string) ($p['artist'] ?? ''));
        return ($artist !== '' && stripos($name, $artist) === false) ? "{$artist} - {$name}" : $name;
    }

    private static function buyer(array $o): string
    {
        return (string) (($o['display_name'] ?? null) ?: (($o['shippingAddress'][0]['name'] ?? null) ?: ($o['user_id']['name'] ?? '')));
    }

    private static function days($date): string
    {
        $t = strtotime((string) $date);
        $d = $t ? (int) floor((time() - $t) / 86400) : 0;
        return $d . ' day' . ($d === 1 ? '' : 's');
    }

    private static function itemsN(int $n): string
    {
        return $n . ' item' . ($n === 1 ? '' : 's');
    }

    private static function esc(string $s): string
    {
        return str_replace(['&', '<', '>'], ['&amp;', '&lt;', '&gt;'], $s);
    }

    private static function api(string $method, string $path, ?array $body, int $timeout): ?array
    {
        $key = ShippingShiftNotifier::erpApiKey();
        if ($key === '') return null;
        $ch = curl_init(ShippingShiftNotifier::baseUrl() . $path);
        $headers = ['Accept: application/json', 'x-erp-key: ' . $key];
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => $timeout, CURLOPT_CONNECTTIMEOUT => 8, CURLOPT_CUSTOMREQUEST => $method]);
        if ($body !== null) {
            $headers[] = 'Content-Type: application/json';
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
        }
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        $raw = curl_exec($ch);
        curl_close($ch);
        $j = json_decode((string) $raw, true);
        return is_array($j) ? $j : null;
    }
}
