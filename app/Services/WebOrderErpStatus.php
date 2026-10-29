<?php

namespace App\Services;

use App\Http\Controllers\WebsiteOrdersController;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Mirrors what happened to a website / Discogs order on /website-orders onto
 * the ERP sale(s) it created, so the sale shows Cancelled, Refunded or
 * Refund pending too (Sarah, 2026-10-29). The website books one ERP sale per
 * item (note "Website order <id>"), so a cancelled item only marks its own
 * sale. Discogs orders are matched on "Discogs order <no>".
 */
class WebOrderErpStatus
{
    const LABELS = [
        'refund_pending' => 'Refund pending',
        'refunded' => 'Refunded',
        'cancelled' => 'Cancelled',
    ];

    const COLORS = [
        'refund_pending' => 'background:#fef9c3;color:#854d0e;',
        'refunded' => 'background:#f3e8ff;color:#6b21a8;',
        'cancelled' => 'background:#fee2e2;color:#991b1b;',
    ];

    public static function enabled(): bool
    {
        static $on = null;
        if ($on === null) {
            try { $on = Schema::hasColumn('transactions', 'web_order_status'); } catch (\Throwable $e) { $on = false; }
        }
        return $on;
    }

    public static function pill(?string $status): string
    {
        if (!$status || !isset(self::LABELS[$status])) return '';
        return '<span class="label" style="' . self::COLORS[$status] . '">' . self::LABELS[$status] . '</span>';
    }

    /** One website order from /erp/orders/console (or the cancel response). Returns sales changed. */
    public static function applyWebsiteOrder(array $o): int
    {
        $id = (string) ($o['_id'] ?? '');
        if (!self::enabled() || !preg_match('/^[a-f0-9]{24}$/i', $id)) return 0;

        $sales = DB::table('transactions')
            ->where('type', 'sell')
            ->where(function ($q) use ($id) {
                $q->where('additional_notes', 'Website order ' . $id)
                  ->orWhere('additional_notes', 'like', 'Website order ' . $id . '%')
                  ->orWhere(function ($q2) use ($id) {
                      $q2->where('import_source', 'nivessa_web')->where('import_external_id', $id);
                  });
            })
            ->get(['id', 'web_order_status']);
        if ($sales->isEmpty()) return 0;

        $orderStatus = self::websiteOrderStatus($o);
        $byProduct = [];
        foreach (($o['items'] ?? []) as $it) {
            if (!empty($it['is_gift_card'])) continue;
            $pid = (string) ($it['product_id']['posProductId'] ?? '');
            if ($pid !== '') $byProduct[$pid] = self::websiteItemStatus($o, $it);
        }

        $changed = 0;
        foreach ($sales as $s) {
            $productIds = DB::table('transaction_sell_lines')->where('transaction_id', $s->id)->pluck('product_id')->map(function ($p) { return (string) $p; })->all();
            $matched = array_values(array_intersect_key($byProduct, array_flip($productIds)));
            // Sale for one item: that item's status. No match (placeholder
            // import, or the item was substituted): the whole order's.
            $status = $matched ? self::strongest($matched) : $orderStatus;
            $changed += self::write($s, $status);
        }
        return $changed;
    }

    /** One Discogs order row from /erp/orders/all?source=discogs ({id, status}). */
    public static function applyDiscogsOrder(array $row): int
    {
        $id = (string) ($row['id'] ?? '');
        if (!self::enabled() || $id === '') return 0;
        $st = strtolower(trim((string) ($row['status'] ?? '')));
        $status = $st === 'refund pending' ? 'refund_pending'
            : ($st === 'refund sent' ? 'refunded'
            : (strpos($st, 'cancelled') === 0 ? 'cancelled' : null));

        $sales = DB::table('transactions')
            ->where('type', 'sell')
            ->where(function ($q) use ($id) {
                $q->where('additional_notes', 'Discogs order ' . $id)
                  ->orWhere('additional_notes', 'like', 'Discogs order ' . $id . ' %')
                  ->orWhere(function ($q2) use ($id) {
                      $q2->where('import_source', 'discogs')->where('import_external_id', $id);
                  });
            })
            ->get(['id', 'web_order_status']);

        $changed = 0;
        foreach ($sales as $s) $changed += self::write($s, $status);
        return $changed;
    }

    /** Pull recent website + Discogs orders and update their ERP sales. [website, discogs] sales changed. */
    public static function syncRecent(int $discogsPages = 3): array
    {
        $ctl = app(WebsiteOrdersController::class);
        $web = 0;
        foreach ($ctl->recentPaidOrders() as $o) $web += self::applyWebsiteOrder($o);

        $dg = 0;
        for ($page = 1; $page <= $discogsPages; $page++) {
            $r = $ctl->bridgeGet('/erp/orders/all?source=discogs&per=200&page=' . $page, 30);
            if (($r['success'] ?? false) !== true) break;
            foreach (($r['data'] ?? []) as $row) $dg += self::applyDiscogsOrder($row);
            if (count($r['data'] ?? []) < 200) break;
        }
        return [$web, $dg];
    }

    protected static function websiteOrderStatus(array $o): ?string
    {
        if (($o['order_status'] ?? '') === 'cancelled') {
            return self::refundOk($o['cancel_refund']['status'] ?? null) || ($o['payment_status'] ?? '') === 'refunded' ? 'refunded' : 'cancelled';
        }
        if (($o['payment_status'] ?? '') === 'refunded') return 'refunded';
        if (WebsiteOrdersController::hasCantFind($o)) return 'refund_pending';
        return null;
    }

    protected static function websiteItemStatus(array $o, array $it): ?string
    {
        if (($o['order_status'] ?? '') === 'cancelled' || ($o['payment_status'] ?? '') === 'refunded') {
            return self::websiteOrderStatus($o);
        }
        if (!empty($it['cancelled'])) {
            $itemId = (string) ($it['_id'] ?? '');
            foreach (($o['item_cancellations'] ?? []) as $c) {
                if (in_array($itemId, array_map('strval', (array) ($c['item_ids'] ?? [])), true) && self::refundOk($c['refund_status'] ?? null)) {
                    return 'refunded';
                }
            }
            return 'cancelled';
        }
        // Same rule as the Can't Find: Decide Refund tab, for this item.
        $name = strtolower(trim((string) ($it['product_id']['name'] ?? ($it['product_name'] ?? ''))));
        $recheck = WebsiteOrdersController::hwRecheckNames($o);
        foreach (($o['pick_progress']['lines'] ?? []) as $pl) {
            $k = strtolower(trim((string) ($pl['name'] ?? '')));
            if ($k === $name && ($pl['status'] ?? '') === "Can't Find" && !isset($recheck[$k])) return 'refund_pending';
        }
        return null;
    }

    protected static function refundOk($status): bool
    {
        return in_array($status, ['ok', 'manual'], true);
    }

    protected static function strongest(array $statuses): ?string
    {
        foreach (['refunded', 'cancelled', 'refund_pending'] as $s) if (in_array($s, $statuses, true)) return $s;
        return null;
    }

    protected static function write($sale, ?string $status): int
    {
        if ((string) $sale->web_order_status === (string) $status) return 0;
        DB::table('transactions')->where('id', $sale->id)->update(['web_order_status' => $status]);
        return 1;
    }
}
