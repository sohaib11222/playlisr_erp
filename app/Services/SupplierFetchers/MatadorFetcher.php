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
        // Every spreadsheet in the folder that's a catalog or an upcoming-
        // releases list (both carry dealer cost + UPC). Newest upload wins
        // per barcode because the catalog is read last.
        $files = [];
        if (preg_match_all('#"typedID":"f_(\d+)"[^{}]*?"name":"([^"]+)"#', $html, $m, PREG_SET_ORDER)) {
            foreach ($m as $hit) {
                if (!preg_match('/\.xlsx?$/i', $hit[2])) continue;
                if (stripos($hit[2], 'upcoming') !== false) $files[0] = $hit[1];
                elseif (stripos($hit[2], 'catalog') !== false) $files[1] = $hit[1];
            }
        }
        if (!isset($files[1])) { $files[1] = '1955756231657'; } // last known catalog (Oct 1 2026)
        ksort($files);
        $byUpc = [];
        foreach ($files as $fileId) {
            foreach ($this->readSheet($fileId) as $row) { $byUpc[$row['upc']] = $row; }
        }
        if (!$byUpc) {
            throw new \RuntimeException('Matador: no priced rows found in the Box folder.');
        }
        return array_values($byUpc);
    }

    /** Rows from one Box spreadsheet. Header row optional (upcoming sheet has none). */
    protected function readSheet(string $fileId): array
    {
        $bin = $this->get('https://beggars.app.box.com/index.php?rm=box_download_shared_file&vanity_name=MDDirectSalesFolder&file_id=f_' . $fileId);
        if (substr($bin, 0, 2) !== 'PK') {
            throw new \RuntimeException('Matador: file ' . $fileId . ' did not download as a spreadsheet (Box link may have changed).');
        }
        $tmp = tempnam(sys_get_temp_dir(), 'matador') . '.xlsx';
        file_put_contents($tmp, $bin);
        try {
            $sheet = \PhpOffice\PhpSpreadsheet\IOFactory::load($tmp)->getSheet(0)->toArray(null, true, false, false);
        } finally {
            @unlink($tmp);
        }
        // Default layout: ARTIST, TITLE, FORMAT, TYPE, COST, LIST, CAT #, UPC, LABEL.
        $iA = 0; $iT = 1; $iF = 2; $iTy = 3; $iC = 4; $iU = 7;
        $first = array_map(function ($h) { return strtoupper(trim((string) $h)); }, (array) ($sheet[0] ?? []));
        if (in_array('UPC', $first, true)) {
            array_shift($sheet);
            foreach ($first as $i => $h) {
                if ($h === 'ARTIST') $iA = $i; elseif ($h === 'TITLE') $iT = $i; elseif ($h === 'FORMAT') $iF = $i;
                elseif ($h === 'TYPE') $iTy = $i; elseif (strpos($h, 'NEW COST') === 0 || $h === 'COST') $iC = $i; elseif ($h === 'UPC') $iU = $i;
            }
        }
        $now = date('c');
        $out = [];
        foreach ($sheet as $r) {
            $upc = preg_replace('/\D+/', '', (string) ($r[$iU] ?? ''));
            $cost = (float) preg_replace('/[^\d.]/', '', (string) ($r[$iC] ?? ''));
            if (strlen($upc) < 8 || $cost <= 0) continue; // date divider rows etc.
            $t = strtolower(trim((string) ($r[$iTy] ?? '') . ' ' . (string) ($r[$iF] ?? '')));
            $format = (strpos($t, 'cd') !== false) ? 'CD' : ((strpos($t, 'lp') !== false || strpos($t, '12') !== false || strpos($t, '7"') !== false || strpos($t, 'vinyl') !== false) ? 'LP'
                : ((strpos($t, 'mc') !== false || strpos($t, 'cass') !== false) ? 'Cassette' : null));
            $out[] = [
                'artist' => trim((string) ($r[$iA] ?? '')) ?: null,
                'title' => trim((string) ($r[$iT] ?? '')) ?: null,
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
