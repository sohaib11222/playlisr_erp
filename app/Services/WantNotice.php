<?php

namespace App\Services;

/**
 * Wording + links for "your wanted record came in" texts and emails
 * (Sarah, 2026-10-14). We don't hold records: the customer either places a
 * pickup order on nivessa.com (pulled in 1-2 days) or comes by.
 */
class WantNotice
{
    const STORES = [
        'pico'      => ['key' => 'pico', 'name' => 'Nivessa on Pico', 'address' => '5770 W Pico Blvd, Los Angeles, CA 90019'],
        'hollywood' => ['key' => 'hollywood', 'name' => 'Nivessa Hollywood', 'address' => '6434 Hollywood Blvd, Los Angeles, CA 90028'],
    ];

    /** ['name' => ..., 'address' => ...] for a location name; Hollywood when unknown. */
    public static function store(?string $locationName): array
    {
        return stripos((string) $locationName, 'pico') !== false ? self::STORES['pico'] : self::STORES['hollywood'];
    }

    /**
     * The product's nivessa.com page, or null when it isn't live there yet
     * (new ERP stock reaches the site with the nightly sync).
     */
    public static function productLink($productId): ?string
    {
        if (empty($productId)) return null;
        try {
            $ch = curl_init('https://nivessa.com/api/v1/product/single/erp-' . (int) $productId);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => 8,
                CURLOPT_HTTPHEADER     => ['Accept: application/json'],
            ]);
            $raw = curl_exec($ch);
            $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            if ($raw === false || $code !== 200) return null;
            $p = json_decode((string) $raw, true)['data'] ?? null;
            if (empty($p['sku']) || empty($p['slug'])) return null;
            return 'https://nivessa.com/products/' . rawurlencode($p['sku']) . '/' . rawurlencode($p['slug']);
        } catch (\Throwable $e) {
            return null;
        }
    }

    /** Wanted record came in (Sarah's wording, 2026-10-23). */
    public static function text(string $firstName, string $label, array $store, ?string $link): string
    {
        $hi = 'Hi' . ($firstName !== '' ? ' ' . $firstName : '') . ', Nivessa Records here.';
        $msg = "{$hi} The record you asked us to look out for just came in at {$store['name']}: {$label}.";
        if ($link) {
            return $msg . " Want it? Place a pickup order here and we'll pull it in 1 to 2 days: {$link} Or just come on by! {$store['address']}";
        }
        return $msg . " Come by if you still want it! {$store['address']}";
    }

    /** Special order arrived / in-store order ready (Sarah's wording, 2026-10-23). */
    public static function pickupText(string $kind, string $firstName, string $label, array $store): string
    {
        $hi = 'Hi' . ($firstName !== '' ? ' ' . $firstName : '') . ', Nivessa Records here.';
        $what = $kind === 'special'
            ? ($label !== '' ? "Your special order just came in: {$label}." : 'Your special order just came in.')
            : ($label !== '' ? "Your order is ready for pickup: {$label}." : 'Your order is ready for pickup.');
        return "{$hi} {$what} We're holding it at the counter at {$store['name']}, {$store['address']}. "
             . 'Just tell us your name at the register when you come by. See you soon!';
    }

    /**
     * Pickup email (want / special / instore) through the website, which sends
     * from orders@ so it actually delivers. Returns ['ok' => bool, 'msg' => string].
     */
    public static function email(string $to, string $kind, string $firstName, array $store, array $item = []): array
    {
        $resp = WebsiteBridge::erp('POST', '/customer-pickup/email', [
            'to' => $to,
            'kind' => $kind,
            'firstName' => $firstName,
            'store' => $store['key'] ?? 'hollywood',
            'artist' => (string) ($item['artist'] ?? ''),
            'title' => (string) ($item['title'] ?? ''),
            'format' => (string) ($item['format'] ?? ''),
            'image' => (string) ($item['image'] ?? ''),
            'productUrl' => (string) ($item['link'] ?? ''),
        ], 15);
        return !empty($resp['success'])
            ? ['ok' => true, 'msg' => 'Emailed ' . $to]
            : ['ok' => false, 'msg' => 'Email failed: ' . ($resp['message'] ?? 'unknown error')];
    }
}
