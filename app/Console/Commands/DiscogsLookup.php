<?php

namespace App\Console\Commands;

use App\Services\DiscogsSalesLookupService;
use App\Services\DiscogsService;
use Illuminate\Console\Command;

class DiscogsLookup extends Command
{
    protected $signature = 'discogs:lookup
                            {url : Discogs release URL or numeric release id}
                            {--business=1 : Business id for Discogs API auth}
                            {--limit=25 : Max detail rows printed per channel}
                            {--all-status : Include non-final transactions (drafts, holds)}';

    protected $description = 'Look up a Discogs release and report local sales history grouped by channel (in-store Pico/Hollywood, Discogs online, etc.) plus current Discogs marketplace stats.';

    public function handle()
    {
        $url = trim((string) $this->argument('url'));
        $businessId = (int) $this->option('business');
        $limit = max(1, (int) $this->option('limit'));

        $parsed = $this->parseDiscogsUrl($url);
        if (!$parsed) {
            $this->error('Could not parse a Discogs release/master id from: ' . $url);
            $this->line('Accepted forms:');
            $this->line('  https://www.discogs.com/release/<id>[-slug]');
            $this->line('  https://www.discogs.com/<lang>/release/<id>[-slug]');
            $this->line('  https://www.discogs.com/master/<id>[-slug]');
            $this->line('  <numeric release id>');
            return 1;
        }
        [$type, $discogsId] = $parsed;

        $svc = new DiscogsService($businessId);
        if (!$svc->isConfigured()) {
            $this->error('Discogs API token not configured for business_id ' . $businessId
                . ' (Settings → Integrations → Discogs).');
            return 1;
        }

        if ($type === 'master') {
            $this->warn('Master URLs cover multiple pressings — for tighter matches pass a specific /release/ URL.');
        }

        $this->info('Fetching Discogs ' . strtoupper($type) . ' #' . $discogsId . ' ...');
        $response = $svc->getReleaseById($discogsId);
        if (!empty($response['error'])) {
            $this->error('Discogs API error: ' . ($response['message'] ?? 'unknown'));
            return 1;
        }
        $data = $response['data'];

        $artist = $this->extractArtist($data);
        $title  = trim((string) ($data->title ?? ''));
        $year   = $data->year ?? null;

        $this->line('');
        $this->line(str_repeat('=', 78));
        $this->line('  ' . $artist . ' — ' . $title . ($year ? ' (' . $year . ')' : ''));
        $this->line(str_repeat('=', 78));

        // Discogs marketplace stats (from release JSON)
        $community = $data->community ?? null;
        $haves   = $community->have ?? null;
        $wants   = $community->want ?? null;
        $lowest  = $data->lowest_price ?? null;
        $forSale = $data->num_for_sale ?? null;

        $this->line('');
        $this->line('<comment>Discogs marketplace</comment>');
        $this->line('  Have on Discogs:   ' . ($haves   ?? '—'));
        $this->line('  Want on Discogs:   ' . ($wants   ?? '—'));
        $this->line('  Lowest listed:     ' . ($lowest !== null ? '$' . number_format((float) $lowest, 2) : '—'));
        $this->line('  For sale now:      ' . ($forSale ?? '—'));

        if ($type === 'release') {
            $suggest = $svc->getPriceSuggesions($discogsId);
            if (empty($suggest['error']) && !empty($suggest['data'])) {
                $this->line('  Suggested by condition (Discogs median):');
                foreach ($suggest['data'] as $cond => $info) {
                    $val = isset($info->value) ? '$' . number_format((float) $info->value, 2) : '—';
                    $this->line(sprintf('    %-30s %s', $cond, $val));
                }
            }
        }
        $this->line('  Last sold date:    <fg=yellow>not exposed by Discogs API</>');

        // Local DB lookup — shared service used by the bulk-mass-create UI too.
        $this->line('');
        $this->line('<comment>Local sales history (Nivessa DB)</comment>');

        $svcLookup = new DiscogsSalesLookupService();
        $result = $svcLookup->lookup(
            $discogsId,
            $artist,
            $title,
            $businessId,
            (bool) $this->option('all-status')
        );

        if (($result['total_lines'] ?? 0) === 0) {
            $this->line('  No matching sales found.');
            $this->line('  (Tried: products.discogs_release_id=' . $discogsId
                . ' | products.artist LIKE artist | products.name LIKE artist|title'
                . ' | transaction_sell_lines.legacy_artist|legacy_title LIKE)');
            return 0;
        }

        $this->line('');
        $this->line('  Channel summary:');
        $this->line(sprintf('  %-28s %6s %6s %14s %20s %20s',
            'Channel', 'Lines', 'Qty', 'Revenue', 'First sold', 'Most recent'));
        $this->line('  ' . str_repeat('-', 96));
        foreach ($result['by_channel'] as $b) {
            $this->line(sprintf('  %-28s %6d %6d %14s %20s %20s',
                $b['label'],
                $b['lines'],
                $b['qty'],
                '$' . number_format($b['revenue'], 2),
                $b['first'] ?? '—',
                $b['last']  ?? '—'
            ));
        }
        $this->line(sprintf('  %-28s %6d %6d %14s %20s %20s',
            'TOTAL',
            $result['total_lines'],
            $result['total_qty'],
            '$' . number_format((float) $result['total_revenue'], 2),
            $result['first_sold'] ?? '—',
            $result['last_sold']  ?? '—'
        ));

        // Group detail rows by bucket for per-channel listings.
        $rowsByBucket = [];
        foreach ($result['rows'] as $r) {
            $channel = $r->channel ?? 'in_store';
            $key = $channel === 'in_store'
                ? ('In-store: ' . ($r->location_name ?: 'Unknown'))
                : ucfirst($channel);
            $rowsByBucket[$key][] = $r;
        }
        ksort($rowsByBucket);

        foreach ($rowsByBucket as $name => $brows) {
            $this->line('');
            $this->line('  <info>' . $name . '</info> — sales (newest first):');
            $shown = array_slice($brows, 0, $limit);
            foreach ($shown as $r) {
                $label = $r->product_name
                    ?: trim(((string) ($r->legacy_artist ?? '')) . ' — ' . ((string) ($r->legacy_title ?? '')), ' —');
                $this->line(sprintf('    %s  qty=%d  $%s  [txn #%d  %s]  %s',
                    $r->transaction_date,
                    (int) $r->quantity,
                    number_format((float) $r->unit_price_inc_tax, 2),
                    $r->transaction_id,
                    $r->status,
                    $label
                ));
            }
            $rest = count($brows) - count($shown);
            if ($rest > 0) {
                $this->line('    ... ' . $rest . ' more (pass --limit=' . count($brows) . ' to see all)');
            }
        }

        return 0;
    }

    private function parseDiscogsUrl(string $url): ?array
    {
        if (preg_match('#/(release|master)/(\d+)#i', $url, $m)) {
            return [strtolower($m[1]), (int) $m[2]];
        }
        if (preg_match('#^\d+$#', $url)) {
            return ['release', (int) $url];
        }
        return null;
    }

    private function extractArtist($data): string
    {
        if (!empty($data->artists) && is_array($data->artists)) {
            $names = [];
            foreach ($data->artists as $a) {
                $n = trim((string) ($a->name ?? ''));
                // Discogs disambiguates duplicate artist names with " (2)", " (3)" etc.
                $n = preg_replace('/\s*\(\d+\)\s*$/', '', $n);
                if ($n !== '') {
                    $names[] = $n;
                }
            }
            if (!empty($names)) {
                return implode(' / ', $names);
            }
        }
        return trim((string) ($data->artists_sort ?? ''));
    }

}
