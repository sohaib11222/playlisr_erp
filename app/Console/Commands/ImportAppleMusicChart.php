<?php

namespace App\Console\Commands;

use App\Business;
use App\ChartPick;
use App\ChartPickImport;
use App\Services\AppleMusicChartFetcher;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Pulls Apple Music "Top 100 Most Played Albums — US" and drops them
 * into chart_picks with source=apple_music_top. Scheduled daily.
 *
 * Usage:
 *   php artisan charts:import-apple-music
 *   php artisan charts:import-apple-music --dry-run
 */
class ImportAppleMusicChart extends Command
{
    protected $signature = 'charts:import-apple-music {--dry-run} {--business-id=}';

    protected $description = 'Import Apple Music Top 100 into chart_picks (source=apple_music_top)';

    public function handle(AppleMusicChartFetcher $fetcher)
    {
        if (!Schema::hasTable('chart_picks') || !Schema::hasTable('chart_pick_imports')) {
            $this->error('chart_picks tables missing — run php artisan migrate first.');
            return 1;
        }

        $dryRun = (bool) $this->option('dry-run');
        $businessId = (int) ($this->option('business-id') ?: $this->resolveDefaultBusinessId());
        if (!$businessId) {
            $this->error('Could not resolve business_id. Pass --business-id=N.');
            return 1;
        }

        $rows = $fetcher->fetchTop100();
        if (empty($rows)) {
            $this->warn('Apple Music feed returned 0 rows (network issue or feed format change?).');
            return 0;
        }

        $this->info('Fetched ' . count($rows) . ' Apple Music top-100 entries');

        if ($dryRun) {
            $this->line('(dry-run) top 5:');
            foreach (array_slice($rows, 0, 5) as $r) {
                $this->line(sprintf('  #%-3d %s — %s', $r['rank'], $r['artist'], $r['title']));
            }
            return 0;
        }

        $weekOf = Carbon::now()->format('Y-m-d');

        DB::transaction(function () use ($businessId, $rows, $weekOf) {
            $import = ChartPickImport::create([
                'business_id' => $businessId,
                'source' => 'apple_music_top',
                'week_of' => $weekOf,
                'imported_by' => 0,
                'row_count' => count($rows),
                'raw_body' => null,
            ]);

            ChartPick::where('business_id', $businessId)
                ->where('source', 'apple_music_top')
                ->whereDate('week_of', $weekOf)
                ->delete();

            foreach ($rows as $r) {
                // Consider a release within the last 60 days a "new release"
                $isNew = false;
                if (!empty($r['release_date'])) {
                    $releaseTs = strtotime($r['release_date']);
                    if ($releaseTs && $releaseTs > strtotime('-60 days')) {
                        $isNew = true;
                    }
                }

                ChartPick::create([
                    'import_id' => $import->id,
                    'business_id' => $businessId,
                    'source' => 'apple_music_top',
                    'week_of' => $weekOf,
                    'chart_rank' => $r['rank'],
                    'artist' => $r['artist'],
                    'title' => $r['title'],
                    'format' => null,
                    'is_new_release' => $isNew,
                ]);
            }
        });

        $this->info("Imported " . count($rows) . " rows (week_of={$weekOf}).");

        return 0;
    }

    protected function resolveDefaultBusinessId(): int
    {
        try {
            $b = Business::orderBy('id')->first();
            return $b ? (int) $b->id : 0;
        } catch (\Throwable $ignore) {
            return 0;
        }
    }
}
