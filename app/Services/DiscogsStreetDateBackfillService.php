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

        // Scoped to products added to the ERP in the last 180 days. A record
        // added years ago can never have a street date inside the "New
        // Releases" 90-day window, so checking it just burns Discogs budget
        // for nothing — that's why this crawled ~58,800 products at ~360/day
        // and never caught up (2026-08-28, Sarah: still 55,530 remaining
        // after 9 days). Restricting to recent additions shrinks the pool to
        // only rows that could plausibly qualify, so the existing 15-min cron
        // actually finishes instead of crawling the full back-catalog.
        $products = \DB::table('products')
            ->where('business_id', $businessId)
            ->whereNotNull('discogs_release_id')
            ->where('discogs_release_id', '>', 0)
            ->where('created_at', '>=', now()->subDays(180))
            ->where(function ($q) {
                $q->whereNull('product_custom_field2')->orWhere('product_custom_field2', '');
            })
            ->select('id', 'name', 'discogs_release_id')
            ->orderByDesc('created_at')
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
                // Stored as Y-m-d (ISO) — the website sync (jonhedvat/server)
                // parses this field as a date, so keep the stored format
                // stable. Only the admin-page display below is MM/DD/YYYY.
                \DB::table('products')->where('id', $p->id)->update(['product_custom_field2' => $date]);
            }
            $updated++;
            $results[] = ['id' => $p->id, 'name' => $p->name, 'discogs_release_id' => $p->discogs_release_id, 'status' => 'found', 'detail' => \Carbon\Carbon::createFromFormat('Y-m-d', $date)->format('m/d/Y')];
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
            ->where('created_at', '>=', now()->subDays(180))
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
