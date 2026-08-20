<?php

namespace App\Services;

/**
 * Fills the street/release date (products.product_custom_field2 — the same
 * field the storefront's New Releases row reads, see AbcFullReport's New
 * Releases scope) from the Discogs release the product is already linked to
 * (products.discogs_release_id). Only touches products with that field
 * currently EMPTY — never overwrites a date staff already typed in by hand.
 *
 * Shared by DiscogsStreetDateController (button at /admin/discogs-street-dates)
 * and the scheduled command (discogs:backfill-street-dates), same pattern as
 * AbcImportService::computeFromSales feeding both the button and the cron job.
 */
class DiscogsStreetDateBackfillService
{
    /**
     * One batch: find up to $limit eligible products, look each up on
     * Discogs, and (if $commit) write the date. Always returns a preview of
     * what happened/would happen so the caller can show it either way.
     */
    public function run(int $businessId, int $limit = 150, bool $commit = false): array
    {
        $svc = new DiscogsService($businessId);
        if (!$svc->isConfigured()) {
            return ['ok' => false, 'error' => 'Discogs API token not configured (Settings > Integrations > Discogs).'];
        }

        $products = \DB::table('products')
            ->where('business_id', $businessId)
            ->whereNotNull('discogs_release_id')
            ->where('discogs_release_id', '>', 0)
            ->where(function ($q) {
                $q->whereNull('product_custom_field2')->orWhere('product_custom_field2', '');
            })
            ->select('id', 'name', 'discogs_release_id')
            ->limit($limit)
            ->get();

        $results = [];
        $updated = 0;
        $notFound = 0;
        $failed = 0;

        foreach ($products as $p) {
            $resp = $svc->getReleaseById($p->discogs_release_id);
            // Discogs allows ~60 req/min; pace so a 150-row batch (~165s)
            // never trips the rate limit instead of relying on the one
            // short retry callApi() already does for a single blip.
            usleep(1100000);

            if (!empty($resp['error'])) {
                $failed++;
                $results[] = ['id' => $p->id, 'name' => $p->name, 'discogs_release_id' => $p->discogs_release_id, 'status' => 'error', 'detail' => $resp['message'] ?? 'unknown error'];
                continue;
            }

            $date = $this->extractReleaseDate($resp['data']);
            if (!$date) {
                $notFound++;
                $results[] = ['id' => $p->id, 'name' => $p->name, 'discogs_release_id' => $p->discogs_release_id, 'status' => 'no_date', 'detail' => 'Discogs has no release date for this release.'];
                continue;
            }

            if ($commit) {
                \DB::table('products')->where('id', $p->id)->update(['product_custom_field2' => $date]);
            }
            $updated++;
            $results[] = ['id' => $p->id, 'name' => $p->name, 'discogs_release_id' => $p->discogs_release_id, 'status' => 'found', 'detail' => $date];
        }

        return [
            'ok' => true,
            'commit' => $commit,
            'checked' => count($products),
            'updated' => $updated,
            'no_date' => $notFound,
            'failed' => $failed,
            'results' => $results,
        ];
    }

    /** How many products are still eligible (for the "X remaining" count). */
    public function countEligible(int $businessId): int
    {
        return (int) \DB::table('products')
            ->where('business_id', $businessId)
            ->whereNotNull('discogs_release_id')
            ->where('discogs_release_id', '>', 0)
            ->where(function ($q) {
                $q->whereNull('product_custom_field2')->orWhere('product_custom_field2', '');
            })
            ->count();
    }

    /**
     * "released" is the real date Discogs shows on the release page — prefer
     * it, in whatever precision Discogs gives (full date / year-month /
     * year-only), over the bare `year` field. Returns Y-m-d or null.
     */
    protected function extractReleaseDate($data): ?string
    {
        $released = trim((string) ($data->released ?? ''));
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $released)) {
            return $released;
        }
        if (preg_match('/^\d{4}-\d{2}$/', $released)) {
            return $released . '-01';
        }
        if (preg_match('/^\d{4}$/', $released) && $released !== '0000') {
            return $released . '-01-01';
        }
        $year = (int) ($data->year ?? 0);
        if ($year > 0) {
            return $year . '-01-01';
        }
        return null;
    }
}
