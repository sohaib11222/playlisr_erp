<?php

namespace App\Console\Commands;

use App\Services\AbcImportService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

/**
 * Monthly ABC-XYZ recalculation straight from ERP sales — the automated
 * counterpart to the "Recalculate from ERP sales" button at
 * /admin/abc-import. Replaces Sarah re-uploading Sabina's analyzer CSV each
 * month (Sarah 2026-08-19: "fully replace sabina").
 *
 * Same computeFromSales() + save() path as AbcImportController@recalculate
 * + @save, just without the preview/token round-trip — there's no one to
 * review it before it goes live, so it saves directly.
 *
 * Usage:
 *   php artisan abc:recalculate-from-sales
 */
class RecalculateAbcFromSales extends Command
{
    protected $signature = 'abc:recalculate-from-sales {--business=1 : business_id}';

    protected $description = 'Recompute ABC-XYZ classification from ERP sales and activate it (monthly, automated).';

    public function handle()
    {
        $businessId = (int) $this->option('business');

        $svc = new AbcImportService();
        $result = $svc->computeFromSales($businessId);

        if (empty($result['global_map'])) {
            $this->warn('No final sales found for business ' . $businessId . ' — nothing to classify. Leaving the active classification unchanged.');
            return 0;
        }

        $payload = [
            'uploaded_at' => date('c'),
            'source_file' => 'ERP sales (auto, scheduled)',
            'period_label' => $result['period_label'] ?? '',
            'stats' => [
                'rows' => $result['total'],
                'matched' => $result['matched_count'],
                'sku_matched' => $result['sku_matched'] ?? 0,
                'unmatched' => count($result['unmatched']),
                'distinct_products' => count($result['global_map']),
                'distinct_abcxyz' => count($result['abcxyz_map'] ?? []),
            ],
            'global_map' => $result['global_map'],
            'abcxyz_map' => $result['abcxyz_map'] ?? [],
            'location_map' => $result['location_map'],
            'unmatched' => $result['unmatched'],
            'report_rows' => $result['report_rows'] ?? [],
            'months' => $result['months'] ?? [],
        ];

        $svc->save($payload);
        Cache::forget('ica_abc_map_' . $businessId);

        $this->info('Saved. ' . count($result['global_map']) . ' products classified (' . ($result['period_label'] ?? '') . ').');
        return 0;
    }
}
