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
        'pico'      => ['name' => 'Nivessa Pico', 'address' => '5770 W Pico Blvd, Los Angeles, CA 90019'],
        'hollywood' => ['name' => 'Nivessa Hollywood', 'address' => '6434 Hollywood Blvd, Los Angeles, CA 90028'],
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

    public static function text(string $firstName, string $label, array $store, ?string $link): string
    {
        $hi = $firstName !== '' ? 'Hi ' . $firstName . '! ' : 'Hi! ';
        $msg = $hi . "Nivessa here. Your {$label} just came in at {$store['name']}, {$store['address']}.";
        if ($link) {
            $msg .= " If you want it, please place a pickup order here: {$link}"
                  . "\n\nPickup orders are pulled in 1 to 2 days. If you want it sooner, just come by!";
        } else {
            $msg .= " If you want it, come by soon!";
        }
        return $msg;
    }
}
