<?php

namespace App\Services;

/**
 * Server-side twin of the browser parsers in
 * resources/views/admin/distributor_invoice_import.blade.php, used by the
 * automatic intake (distributor-invoices:pull). Same rules: AMS lines by the
 * node-verified regex; Alliance by column position, qty = Qty Shipped,
 * ship-0 lines and the trailing INFORMATION PAGE skipped.
 *
 * parse() returns ['header' => [invoice, date (Y-m-d), account, amsRef],
 * 'items' => [[upc, description, prodType, qty, unitPrice, lineTotal]],
 * 'totals' => [goods, amount], 'sum' => float, 'goods_matches' => ?bool].
 */
class DistributorInvoiceParser
{
    public static function parse($distributor, $pdfBytes)
    {
        $items = (new PdfTextExtractor())->items($pdfBytes);
        $rows = self::rows($items);
        $out = $distributor === 'alliance' ? self::parseAlliance($rows) : self::parseAms($rows);
        $sum = 0.0;
        foreach ($out['items'] as $it) $sum += $it['lineTotal'];
        $out['sum'] = round($sum, 2);
        $goods = $out['totals']['goods'] ?? null;
        $out['goods_matches'] = $goods !== null ? abs($out['sum'] - $goods) < 0.01 : null;
        return $out;
    }

    public static function rows(array $items)
    {
        usort($items, function ($a, $b) {
            return [$a['y'], $a['x']] <=> [$b['y'], $b['x']];
        });
        $rows = [];
        foreach ($items as $it) {
            if (trim($it['str']) === '') continue;
            $found = null;
            foreach ($rows as $i => $r) {
                if (abs($r['y'] - $it['y']) <= 3) { $found = $i; break; }
            }
            if ($found === null) { $rows[] = ['y' => $it['y'], 'parts' => []]; $found = count($rows) - 1; }
            $rows[$found]['parts'][] = $it;
        }
        foreach ($rows as &$r) {
            usort($r['parts'], function ($a, $b) { return $a['x'] <=> $b['x']; });
        }
        unset($r);
        return $rows;
    }

    private static function clean($s)
    {
        return str_replace("\u{00AD}", '-', (string) $s);
    }

    public static function rowText(array $r)
    {
        return trim(preg_replace('/\s+/u', ' ', implode(' ', array_map(function ($p) { return self::clean($p['str']); }, $r['parts']))));
    }

    private static function num($s)
    {
        return (float) str_replace(',', '', (string) $s);
    }

    public static function parseAms(array $rows)
    {
        static $months = ['Jan' => 1, 'Feb' => 2, 'Mar' => 3, 'Apr' => 4, 'May' => 5, 'Jun' => 6, 'Jul' => 7, 'Aug' => 8, 'Sep' => 9, 'Oct' => 10, 'Nov' => 11, 'Dec' => 12];
        $re = '/^(\d{11,14})\s+(.+?)\s+(\d{2}\/\d{2}\/\d{4})\s+([A-Z0-9]{1,6})\s+(\d{1,4})\s+(.*?)\s+(\d{1,3}(?:,\d{3})*\.\d{2})\s+(\d{1,3}(?:,\d{3})*\.\d{2})\s+(\d{1,3}(?:,\d{3})*\.\d{2})$/';
        $header = []; $items = []; $totals = [];
        foreach ($rows as $r) {
            $line = self::rowText($r);
            if (!isset($header['invoice']) && preg_match('/INVOICE:\s*(\d+)/', $line, $m)) $header['invoice'] = $m[1];
            if (!isset($header['amsRef']) && preg_match('/AMS REF #:\s*(\d+)/', $line, $m)) $header['amsRef'] = $m[1];
            if (!isset($header['account']) && preg_match('/Account #:\s*(\d+)/', $line, $m)) $header['account'] = $m[1];
            if (!isset($header['date']) && preg_match('/Date:\s*([A-Z][a-z]{2})\s+(\d{1,2})\s+(\d{4})/', $line, $m) && isset($months[$m[1]])) {
                $header['date'] = sprintf('%04d-%02d-%02d', $m[3], $months[$m[1]], $m[2]);
            }
            if (preg_match('/Total Goods\s*\$?\s*([\d,]+\.\d{2})/', $line, $m)) $totals['goods'] = self::num($m[1]);
            if (preg_match($re, $line, $lm)) {
                $items[] = ['upc' => $lm[1], 'description' => trim($lm[2]), 'prodType' => $lm[4],
                    'qty' => (int) $lm[5], 'unitPrice' => self::num($lm[8]), 'lineTotal' => self::num($lm[9])];
            }
        }
        return ['header' => $header, 'items' => $items, 'totals' => $totals];
    }

    public static function parseAlliance(array $rows)
    {
        $header = []; $items = []; $totals = [];
        $text = implode("\n", array_map([self::class, 'rowText'], $rows));
        if (preg_match('/Invoice #\s*(PLS\d+)/', $text, $m)) $header['invoice'] = $m[1];
        if (preg_match('/Account:\s*(\d{6})/', $text, $m)) $header['account'] = $m[1];
        if (preg_match('/Invoice Date\s+(\d{2})\/(\d{2})\/(\d{2,4})/', $text, $m)) {
            $y = strlen($m[3]) === 2 ? '20' . $m[3] : $m[3];
            $header['date'] = "{$y}-{$m[1]}-{$m[2]}";
        }
        if (preg_match('/P\/O #\s*(\S+)/', $text, $m)) $header['amsRef'] = $m[1];
        if (preg_match_all('/Subtotal\s+([\d,]+\.\d{2})/', $text, $m)) $totals['goods'] = self::num(end($m[1]));
        if (preg_match('/Invoice Amount\s+([\d,]+\.\d{2})/', $text, $m)) $totals['amount'] = self::num($m[1]);

        // Column x positions from page 1's header (defaults = 10/2026 layout).
        $col = ['ord' => 346, 'ship' => 368, 'bo' => 390, 'list' => 412, 'unit' => 458, 'ext' => 505];
        $head = ['Ord' => 'ord', 'Ship' => 'ship', 'B/O' => 'bo', 'List Price' => 'list', 'Unit Price' => 'unit', 'Extended Amt' => 'ext'];
        $seen = [];
        foreach ($rows as $r) {
            if ($r['y'] > 150000) break;
            foreach ($r['parts'] as $p) {
                $k = $head[trim(self::clean($p['str']))] ?? null;
                if ($k && !isset($seen[$k])) { $seen[$k] = true; $col[$k] = $p['x']; }
            }
        }
        $numCols = [['ord', $col['ord']], ['ship', $col['ship']], ['bo', $col['bo']], ['list', $col['list']], ['unit', $col['unit']], ['ext', $col['ext']]];
        $colFor = function ($p) use ($numCols) {
            $right = $p['x'] + ($p['width'] ?? 0) - 2;
            $name = null;
            foreach ($numCols as $nc) if ($nc[1] <= $right) $name = $nc[0];
            return $name;
        };

        foreach ($rows as $r) {
            if (strpos(self::rowText($r), 'INFORMATION PAGE') !== false) break;
            $upc = $r['parts'][0] ?? null;
            if (!$upc || !preg_match('/^\d{11,14}$/', trim($upc['str'])) || $upc['x'] > $col['ord'] / 3) continue;
            $cf = isset($r['parts'][1]) && preg_match('/^[A-Z0-9]{2}$/', trim($r['parts'][1]['str'])) ? $r['parts'][1] : null;
            $desc = [];
            $v = [];
            foreach ($r['parts'] as $i => $p) {
                if ($i === 0 || ($cf && $i === 1)) continue;
                if ($p['x'] < $col['ord'] - 4) { $desc[] = self::clean($p['str']); continue; }
                $s = trim($p['str']);
                if ($p['x'] < $col['ord'] - 20 || !preg_match('/^[\d,]+(\.\d{2})?$/', $s)) continue;
                $k = $colFor($p);
                if ($k) $v[$k] = self::num($s);
            }
            $ship = $v['ship'] ?? 0;
            if ($ship <= 0) continue;
            $unit = $v['unit'] ?? 0;
            $items[] = [
                'upc' => trim($upc['str']),
                'description' => trim(preg_replace('/\s+/u', ' ', implode(' ', $desc))),
                'prodType' => $cf ? trim($cf['str']) : '',
                'qty' => (int) $ship,
                'unitPrice' => $unit,
                'lineTotal' => isset($v['ext']) ? $v['ext'] : round($ship * $unit, 2),
                'ordered' => $v['ord'] ?? null,
                'backordered' => $v['bo'] ?? 0,
            ];
        }
        return ['header' => $header, 'items' => $items, 'totals' => $totals];
    }
}
