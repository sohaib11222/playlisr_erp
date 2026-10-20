<?php

namespace App\Services;

use App\Http\Controllers\WebsiteOrdersController as WO;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * One morning pull list per store (Sarah, 2026-10-19: "just tag the person
 * working that morning on what they have to pull and from where").
 * Replaces the long shipping summary, the #pico pull list and the per-order
 * "New order to ship" posts. Hollywood Store + Warehouse go to #shipping,
 * Pico to #pico. Posted once a day, when the first person clocks in at that
 * store, tagging only them; by 11 AM it goes out anyway, tagging whoever
 * Sling has there. Same items as the Pick Route / store "to pull" counts.
 * Never throws.
 */
class MorningPullsNotifier
{
    const STORES = [
        'hollywood' => ['channel' => 'shipping', 'places' => ['hollywood', 'warehouse'], 'label' => 'Hollywood'],
        'pico' => ['channel' => 'pico', 'places' => ['pico'], 'label' => 'Pico'],
    ];
    const PLACE_LABEL = ['hollywood' => 'Hollywood Store', 'warehouse' => 'Warehouse', 'pico' => 'Pico Store'];
    const ROUTE_URL = 'https://playlist.nivessa.com/website-orders/pick-route?store=';
    const MAX_LINES = 60;

    /** Every few minutes in the morning: post each store that is due. */
    public static function tick(bool $dryRun = false): array
    {
        $out = [];
        $tz = 'America/Los_Angeles';
        $now = \Carbon::now($tz);
        $file = storage_path('app/shipping-shift/morning-pulls.json');
        $state = is_file($file) ? (json_decode((string) file_get_contents($file), true) ?: []) : [];
        foreach (self::STORES as $store => $cfg) {
            if (($state[$store] ?? '') === $now->toDateString()) continue;
            $who = self::clockedIn($store);
            if (!$who) {
                if ($now->hour < 11) continue; // wait for the morning person to clock in
                $who = self::scheduled($store);
            }
            $text = self::build($store, $who);
            if ($dryRun) {
                $out[$store] = $text ?? '(nothing to pull)';
                continue;
            }
            $ok = $text === null ? true : self::send($cfg['channel'], $text);
            if ($ok) {
                $state[$store] = $now->toDateString();
                if (!is_dir(dirname($file))) @mkdir(dirname($file), 0775, true);
                @file_put_contents($file, json_encode($state));
            }
            $out[$store] = $text === null ? 'nothing to pull' : ($ok ? 'posted' : 'post failed');
        }
        return $out;
    }

    /** Message text for one store, or null when nothing is waiting there. */
    public static function build(string $store, array $userIds = []): ?string
    {
        $cfg = self::STORES[$store];
        $web = self::api('GET', '/erp/orders/console?limit=300&payment_status=completed', null, 30);
        if (!is_array($web) || ($web['success'] ?? true) === false) {
            throw new \RuntimeException('could not load website orders');
        }
        $orders = $web['data'] ?? [];
        $rows = array_values(array_filter(WO::pendingPulls($orders, WO::erpLocations($orders)), function ($r) use ($cfg) {
            // Pending arrival isn't on the shelf yet
            return in_array($r['store'], $cfg['places'], true) && $r['pick_status'] !== 'Pending Arrival';
        }));
        if (!$rows) return null;

        $tags = self::tags($userIds);
        $now = \Carbon::now('America/Los_Angeles');
        $lines = ['*' . $cfg['label'] . ' pulls for ' . $now->format('D m/d/y') . '*'];
        $lines[] = ($tags ? implode(' ', $tags) . ' ' : '') . 'please pull these today and tap Picked or Can\'t find on the Pick Route.';
        $shown = 0;
        foreach ($cfg['places'] as $place) {
            $here = array_values(array_filter($rows, fn($r) => $r['store'] === $place));
            if (!$here) continue;
            // Walking order, like the Pick Route: area / genre, then bin
            usort($here, function ($a, $b) {
                return strnatcasecmp(self::spot($a), self::spot($b));
            });
            $lines[] = '';
            $lines[] = '*' . self::PLACE_LABEL[$place] . '* (' . count($here) . ') <' . self::ROUTE_URL . $place . '|Pick Route>';
            foreach ($here as $r) {
                if ($shown >= self::MAX_LINES) break 2;
                $shown++;
                $o = $r['order'];
                $due = WO::dueDate($o);
                $late = $due && $due[1] === 'over' ? ' *LATE*' : '';
                $spot = self::spot($r);
                $lines[] = '• ' . ($spot !== '' ? '*' . self::esc($spot) . '*: ' : '') . self::esc(self::itemName($r))
                    . ' (#' . ($o['orderNumber'] ?? '') . ' ' . self::esc(self::buyer($o)) . ')' . $late;
            }
        }
        if ($shown < count($rows)) {
            $lines[] = '+' . (count($rows) - $shown) . ' more on the Pick Route';
        }
        return implode("\n", $lines);
    }

    /** "HW Main Floor / Bin pico19" (warehouse) or "Vinyl - Used / Rock" (store). */
    private static function spot(array $r): string
    {
        $p = $r['parts'];
        if ($r['store'] === 'warehouse') {
            return implode(' / ', array_filter([$p['area'], $p['bin'] !== '' ? 'Bin ' . $p['bin'] : ''], fn($x) => $x !== ''));
        }
        // "Vinyl - Used / Rock": format section + genre bin (Sarah, 2026-10-14)
        if ($r['genre_full'] !== '') return $r['genre_full'];
        if (($p['genre'] ?? '') !== '') return $p['genre'];
        return $p['bin'] !== '' ? 'Bin ' . $p['bin'] : '';
    }

    /** ERP time-clock location ids for a store (Hollywood includes its warehouse). */
    private static function locationIds(string $store): array
    {
        $ids = [];
        foreach (TimeClockService::locations(TimeClockService::businessId()) as $id => $name) {
            if (stripos((string) $name, $store) !== false) $ids[] = (int) $id;
        }
        return $ids;
    }

    /** The first person clocked in at the store today (still on the clock). */
    private static function clockedIn(string $store): array
    {
        try {
            $locs = self::locationIds($store);
            if (!$locs || !Schema::hasTable('time_clock_punches')) return [];
            $tz = config('app.timezone');
            $start = \Carbon::now('America/Los_Angeles')->startOfDay()->setTimezone($tz)->format('Y-m-d H:i:s');
            $uid = DB::table('time_clock_punches')->whereIn('location_id', $locs)->whereNull('clock_out_at')->whereNull('voided_at')
                ->where('clock_in_at', '>=', $start)->orderBy('clock_in_at')->value('user_id');
            return $uid ? [(int) $uid] : [];
        } catch (\Throwable $e) {
            \Log::warning('Morning pulls clock check failed: ' . $e->getMessage());
            return [];
        }
    }

    /** Nobody clocked in by 11: whoever Sling has at the store now, else next today. */
    private static function scheduled(string $store): array
    {
        try {
            if (!Schema::hasTable('sling_shifts')) return [];
            $tz = config('app.timezone');
            $now = \Carbon::now($tz);
            $base = \App\SlingShift::where('event_type', \App\SlingShift::TYPE_SHIFT)
                ->where('location_name', 'like', '%' . $store . '%')->whereNotNull('erp_user_id');
            $uid = (clone $base)->where('dtstart', '<=', $now)->where('dtend', '>', $now)->orderBy('dtstart')->value('erp_user_id');
            if (!$uid) {
                $end = \Carbon::now('America/Los_Angeles')->endOfDay()->setTimezone($tz);
                $uid = (clone $base)->where('dtstart', '>', $now)->where('dtstart', '<=', $end)->orderBy('dtstart')->value('erp_user_id');
            }
            return $uid ? [(int) $uid] : [];
        } catch (\Throwable $e) {
            return [];
        }
    }

    private static function tags(array $userIds): array
    {
        try {
            $slack = TimeClockService::slackIds(TimeClockService::businessId());
        } catch (\Throwable $e) {
            return [];
        }
        $tags = [];
        foreach ($userIds as $uid) {
            if (!empty($slack[$uid])) $tags[] = '<@' . $slack[$uid] . '>';
        }
        return $tags;
    }

    /** Who would be tagged right now, by name (for the preview page). */
    public static function whoNow(string $store): string
    {
        $ids = self::clockedIn($store);
        $how = 'clocked in';
        if (!$ids) {
            $ids = self::scheduled($store);
            $how = 'on Sling';
        }
        if (!$ids) return 'nobody yet';
        $u = DB::table('users')->where('id', $ids[0])->first(['first_name', 'last_name']);
        return trim(($u->first_name ?? '') . ' ' . ($u->last_name ?? '')) . ' (' . $how . ')';
    }

    private static function send(string $channel, string $text): bool
    {
        $j = self::api('POST', '/erp/slack-store-post', ['store' => $channel, 'text' => $text], 15);
        if (!is_array($j) || empty($j['success'])) {
            \Log::warning('Morning pulls post failed', ['channel' => $channel, 'error' => is_array($j) ? ($j['error'] ?? '') : 'no response']);
            return false;
        }
        return true;
    }

    private static function itemName(array $r): string
    {
        $p = is_array($r['item']['product_id'] ?? null) ? $r['item']['product_id'] : [];
        $name = trim($r['name']);
        $artist = trim((string) ($p['artist'] ?? ''));
        if (strcasecmp($artist, 'Unknown Artist') === 0) $artist = '';
        $q = max(1, (int) ($r['item']['quantity'] ?? 1));
        return (($artist !== '' && stripos($name, $artist) === false) ? "{$artist} - {$name}" : $name) . ($q > 1 ? " x{$q}" : '');
    }

    private static function buyer(array $o): string
    {
        return (string) (($o['display_name'] ?? null) ?: (($o['shippingAddress'][0]['name'] ?? null) ?: ($o['user_id']['name'] ?? '')));
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
