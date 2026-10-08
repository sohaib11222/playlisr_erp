<?php

namespace App\Services\SupplierFetchers;

/**
 * Matador Direct (4AD, Matador, Rough Trade, XL, Young, Beggars Banquet).
 *
 * Built 2026-10-08. They have no ordering portal; the dealer price list is
 * "Catalog EXCEL_new prices.xlsx" in their public Box folder
 * (https://beggars.box.com/v/MDDirectSalesFolder), linked from Ed Hynds'
 * weekly emails. No login needed, so this runs on the nightly cron.
 * Columns: ARTIST, TITLE, FORMAT, TYPE, NEW COST *, NEW LIST PRICE, CAT #, UPC, LABEL.
 * The file id can change when they upload a new catalog, so the folder page
 * is read each run to find the current "Catalog ... .xlsx".
 */
class MatadorFetcher extends AbstractHttpFetcher
{
    protected string $folder = 'https://beggars.app.box.com/v/MDDirectSalesFolder';

    public function supplierKey(): string { return 'matador'; }

    public function readCredentials(): array { return []; }

    public function fetch(): array
    {
        $html = $this->get($this->folder);
        $fileId = null;
        // Folder listing embeds each item as JSON; find the catalog xlsx.
        if (preg_match_all('#"typedID":"f_(\d+)"[^{}]*?"name":"([^"]+)"#', $html, $m, PREG_SET_ORDER)) {
            foreach ($m as $hit) {
                if (stripos($hit[2], 'catalog') !== false && preg_match('/\.xlsx?$/i', $hit[2])) { $fileId = $hit[1]; break; }
            }
        }
        if (!$fileId && preg_match('#/v/MDDirectSalesFolder/file/(\d+)[^"]*"[^>]*>[^<]*Catalog#i', $html, $mm)) { $fileId = $mm[1]; }
        if (!$fileId) { $fileId = '1955756231657'; } // last known (Oct 1 2026 catalog)

        $bin = $this->get('https://beggars.app.box.com/index.php?rm=box_download_shared_file&vanity_name=MDDirectSalesFolder&file_id=f_' . $fileId);
        if (substr($bin, 0, 2) !== 'PK') {
            throw new \RuntimeException('Matador: catalog download did not return a spreadsheet (Box link may have changed).');
        }
        $tmp = tempnam(sys_get_temp_dir(), 'matador') . '.xlsx';
        file_put_contents($tmp, $bin);
        try {
            $sheet = \PhpOffice\PhpSpreadsheet\IOFactory::load($tmp)->getSheet(0)->toArray(null, true, false, false);
        } finally {
            @unlink($tmp);
        }
        $head = array_map(function ($h) { return strtoupper(trim((string) $h)); }, (array) array_shift($sheet));
        $col = function ($name) use ($head) {
            foreach ($head as $i => $h) { if (strpos($h, $name) === 0) return $i; }
            return null;
        };
        $iA = $col('ARTIST'); $iT = $col('TITLE'); $iF = $col('FORMAT'); $iTy = $col('TYPE'); $iC = $col('NEW COST'); $iU = $col('UPC');
        if ($iC === null || $iU === null) {
            throw new \RuntimeException('Matador: catalog columns changed (no NEW COST / UPC).');
        }
        $now = date('c');
        $out = [];
        foreach ($sheet as $r) {
            $upc = preg_replace('/\D+/', '', (string) ($r[$iU] ?? ''));
            $cost = (float) preg_replace('/[^\d.]/', '', (string) ($r[$iC] ?? ''));
            if (strlen($upc) < 8 || $cost <= 0) continue;
            $t = strtolower(trim((string) ($iTy !== null ? $r[$iTy] : '') . ' ' . (string) ($iF !== null ? $r[$iF] : '')));
            $format = (strpos($t, 'cd') !== false) ? 'CD' : ((strpos($t, 'lp') !== false || strpos($t, '12') !== false || strpos($t, '7"') !== false || strpos($t, 'vinyl') !== false) ? 'LP'
                : ((strpos($t, 'mc') !== false || strpos($t, 'cass') !== false) ? 'Cassette' : null));
            $out[] = [
                'artist' => $iA !== null ? trim((string) $r[$iA]) : null,
                'title' => $iT !== null ? trim((string) $r[$iT]) : null,
                'format' => $format,
                'cost' => round($cost, 2),
                'upc' => $upc,
                'url' => null,
                'checked_at' => $now,
            ];
        }
        return $out;
    }
}
