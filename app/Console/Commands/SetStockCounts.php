<?php

namespace App\Console\Commands;

use App\BusinessLocation;
use App\VariationLocationDetails;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Sarah 2026-10-06: set on-hand stock at one location from a physical count
 * sheet (CSV with upc,qty[,artist,title] columns, e.g. the "Order This Week"
 * CD count exported to database/data/stock-counts/).
 *
 * Matching: each UPC is compared against variations.sub_sku and products.sku
 * with leading zeros stripped on both sides, so a 13-digit EAN-style
 * "0602547670144" matches a 12-digit "602547670144" SKU and vice versa.
 * A UPC that matches more than one variation is reported and SKIPPED — it's
 * never safe to guess which duplicate the counted copies belong to.
 *
 * Writes variation_location_details.qty_available directly (creating the row
 * at that location if the product never had one), same as stock:zero-supplier.
 * Dry-run by default; --commit writes a snapshot first (undo at
 * /admin/admin-action-history) and pushes changed products to nivessa.com.
 *
 *   php artisan stock:set-counts database/data/stock-counts/x.csv --location=hollywood
 *   php artisan stock:set-counts database/data/stock-counts/x.csv --location=hollywood --commit
 */
class SetStockCounts extends Command
{
    protected $signature = 'stock:set-counts
                            {file : CSV path (relative to the app root) with upc,qty columns}
                            {--location=hollywood : Business location name to match}
                            {--business=1 : business_id}
                            {--commit : Actually write the counts (default: dry-run)}';

    protected $description = 'Set qty_available at one location from a counted CSV of UPC + qty. Dry-run by default.';

    private $claimedVariations = [];

    public function handle()
    {
        $businessId = (int) $this->option('business');
        $commit = (bool) $this->option('commit');
        $path = base_path($this->argument('file'));

        if (!is_file($path)) {
            $this->error("File not found: {$path}");
            return 1;
        }

        $locName = strtolower(trim((string) $this->option('location')));
        $locations = BusinessLocation::where('business_id', $businessId)
            ->whereRaw('LOWER(name) LIKE ?', ["%{$locName}%"])
            ->get();
        if ($locations->count() !== 1) {
            $this->error("Expected exactly one location matching \"{$locName}\", found {$locations->count()}.");
            return 1;
        }
        $location = $locations->first();

        $counts = $this->readCsv($path);

        $this->line('Mode:      ' . ($commit ? 'COMMIT (writing counts)' : 'DRY RUN (no changes)'));
        $this->line("Location:  #{$location->id} {$location->name}");
        $this->line('Rows:      ' . count($counts));
        $this->line(str_repeat('-', 72));

        $noUpc = [];
        $notFound = [];
        $ambiguous = [];
        $unchanged = 0;
        $changes = [];
        $queue = [];
        $fuzzyQueue = [];

        foreach ($counts as $c) {
            if ($c['upc'] === '') {
                $this->fuzzyOrReport($c, null, $businessId, $fuzzyQueue, $noUpc);
                continue;
            }
            $key = ltrim($c['upc'], '0');

            $matches = DB::table('variations as v')
                ->join('products as p', 'p.id', '=', 'v.product_id')
                ->where('p.business_id', $businessId)
                ->whereNull('v.deleted_at')
                ->where(function ($q) use ($key) {
                    $q->whereRaw("TRIM(LEADING '0' FROM TRIM(v.sub_sku)) = ?", [$key])
                        ->orWhereRaw("TRIM(LEADING '0' FROM TRIM(p.sku)) = ?", [$key]);
                })
                ->select('v.id as variation_id', 'v.product_id', 'v.product_variation_id', 'p.name', 'p.sku')
                ->get()
                ->unique('variation_id');

            if ($matches->isEmpty()) {
                $this->fuzzyOrReport($c, null, $businessId, $fuzzyQueue, $notFound);
                continue;
            }
            if ($matches->count() > 1) {
                $this->fuzzyOrReport($c, $matches, $businessId, $fuzzyQueue, $ambiguous);
                continue;
            }
            $queue[] = ['count' => $c, 'match' => $matches->first(), 'how' => 'upc'];
        }

        // A name match never overrides a product some other row hit by exact UPC.
        $upcVariations = [];
        foreach ($queue as $q) {
            $upcVariations[$q['match']->variation_id] = true;
        }
        foreach ($fuzzyQueue as $q) {
            if (isset($upcVariations[$q['match']->variation_id])) {
                $notFound[] = $q['count'] + ['matches' => ['name match #' . $q['match']->product_id . ' already set by another row\'s UPC']];
                continue;
            }
            $queue[] = $q;
        }

        foreach ($queue as $q) {
            $c = $q['count'];
            $m = $q['match'];
            $vld = VariationLocationDetails::where('variation_id', $m->variation_id)
                ->where('location_id', $location->id)
                ->first();
            $before = $vld ? (float) $vld->qty_available : null;

            if ($before !== null && $before == $c['qty']) {
                $unchanged++;
                continue;
            }
            $changes[] = ['count' => $c, 'match' => $m, 'vld' => $vld, 'before' => $before, 'how' => $q['how']];
        }

        $snapshotRows = [];
        $touchedProductIds = [];
        if ($commit && !empty($changes)) {
            DB::beginTransaction();
            try {
                foreach ($changes as &$ch) {
                    $vld = $ch['vld'];
                    if (!$vld) {
                        $vld = VariationLocationDetails::create([
                            'product_id' => $ch['match']->product_id,
                            'product_variation_id' => $ch['match']->product_variation_id,
                            'variation_id' => $ch['match']->variation_id,
                            'location_id' => $location->id,
                            'qty_available' => 0,
                        ]);
                    }
                    $snapshotRows[] = ['id' => $vld->id, 'qty_available' => (float) ($ch['before'] ?? 0)];
                    $vld->qty_available = $ch['count']['qty'];
                    $vld->save();
                    $touchedProductIds[(int) $ch['match']->product_id] = true;
                }
                unset($ch);
                DB::commit();
            } catch (\Throwable $e) {
                DB::rollBack();
                $this->error('Failed: ' . $e->getMessage());
                return 1;
            }

            $snapshotKey = 'set-stock-counts-' . now()->format('Y-m-d_His');
            Storage::disk('local')->put(
                "admin-snapshots/{$snapshotKey}.json",
                json_encode([
                    'timestamp'   => now()->toDateTimeString(),
                    'action'      => 'set-stock-counts',
                    'business_id' => $businessId,
                    'location_id' => $location->id,
                    'file'        => $this->argument('file'),
                    'rows'        => $snapshotRows,
                ], JSON_PRETTY_PRINT)
            );
            $this->line("Snapshot: {$snapshotKey} — undo at /admin/admin-action-history.");

            try {
                $notifier = new \App\Services\NivessaStockNotifier();
                foreach (array_chunk(array_keys($touchedProductIds), 100) as $chunk) {
                    $notifier->push($chunk);
                }
                $this->line('Pushed ' . count($touchedProductIds) . ' product(s) to the website.');
            } catch (\Throwable $pushEx) {
                $this->error('Website push failed: ' . $pushEx->getMessage());
            }
        }

        $this->line(str_repeat('-', 72));
        $this->line('Already correct:   ' . $unchanged);
        $this->line(($commit ? 'Updated:           ' : 'Would update:      ') . count($changes));
        $this->line('  of which by name: ' . count(array_filter($changes, function ($ch) {
            return $ch['how'] !== 'upc';
        })));
        $this->line('UPC not found:     ' . count($notFound));
        $this->line('UPC on >1 product: ' . count($ambiguous) . ' (skipped, no name tiebreak)');
        $this->line('No UPC, no match:  ' . count($noUpc) . ' (skipped)');
        $this->line(str_repeat('-', 72));

        $this->line('CHANGES (before -> counted):');
        foreach ($changes as $ch) {
            $this->line(sprintf(
                '  %-14s %-11s %5s -> %-3s #%-6d %s',
                $ch['count']['upc'] ?: '(no upc)',
                $ch['how'],
                $ch['before'] === null ? 'none' : $this->fmt($ch['before']),
                $this->fmt($ch['count']['qty']),
                $ch['match']->product_id,
                mb_strimwidth($ch['match']->name, 0, 60, '…')
            ));
        }
        $this->line('UPC NOT FOUND, no confident name match (skipped):');
        foreach ($notFound as $c) {
            $this->line("  {$c['upc']}  qty {$this->fmt($c['qty'])}  {$c['title']}");
            foreach ($c['matches'] ?? [] as $label) {
                $this->line("      {$label}");
            }
        }
        $this->line('UPC ON MORE THAN ONE PRODUCT (skipped):');
        foreach ($ambiguous as $c) {
            $this->line("  {$c['upc']}  qty {$this->fmt($c['qty'])}  {$c['title']}");
            foreach ($c['matches'] as $label) {
                $this->line("      {$label}");
            }
        }
        $this->line('NO UPC ON SHEET, no confident name match (skipped):');
        foreach ($noUpc as $c) {
            $this->line("  qty {$this->fmt($c['qty'])}  {$c['title']}");
            foreach ($c['matches'] ?? [] as $label) {
                $this->line("      {$label}");
            }
        }

        $this->line('');
        if ($commit) {
            $this->info('COMMIT complete.');
        } else {
            $this->info('DRY RUN complete — nothing was written. Re-run with --commit to apply.');
        }

        return 0;
    }

    /**
     * Fuzzy fallback by name. With $pool (UPC hit several products) we only
     * pick among those; otherwise we search all products sharing the artist's
     * most distinctive word. A match is taken only when the best name scores
     * >= 88% similar AND beats the runner-up by >= 6 points AND no other sheet
     * row already claimed it — anything less is reported for a human.
     */
    private function fuzzyOrReport(array $c, $pool, int $businessId, array &$fuzzyQueue, array &$skipped): void
    {
        $target = $this->norm($c['title'] !== '' ? $c['title'] : $c['artist']);
        if ($target === '') {
            $skipped[] = $c;
            return;
        }

        if ($pool === null) {
            $words = preg_split('/\s+/', $this->norm($c['artist'] . ' ' . $c['title']));
            usort($words, function ($a, $b) {
                return strlen($b) <=> strlen($a);
            });
            $needle = $words[0] ?? '';
            if (strlen($needle) < 3) {
                $skipped[] = $c;
                return;
            }
            $pool = DB::table('variations as v')
                ->join('products as p', 'p.id', '=', 'v.product_id')
                ->where('p.business_id', $businessId)
                ->whereNull('v.deleted_at')
                ->where('p.name', 'like', '%' . $needle . '%')
                ->select('v.id as variation_id', 'v.product_id', 'v.product_variation_id', 'p.name', 'p.sku')
                ->limit(500)
                ->get()
                ->unique('variation_id');
        }

        $scored = [];
        foreach ($pool as $m) {
            similar_text($target, $this->norm($m->name), $pct);
            $scored[] = ['m' => $m, 'pct' => $pct];
        }
        usort($scored, function ($a, $b) {
            return $b['pct'] <=> $a['pct'];
        });

        $best = $scored[0] ?? null;
        $second = $scored[1]['pct'] ?? 0;
        if ($best && $best['pct'] >= 88 && ($best['pct'] - $second) >= 6
            && !isset($this->claimedVariations[$best['m']->variation_id])) {
            $this->claimedVariations[$best['m']->variation_id] = true;
            $fuzzyQueue[] = ['count' => $c, 'match' => $best['m'], 'how' => 'name ' . round($best['pct']) . '%'];
            return;
        }

        $c['matches'] = array_map(function ($s) {
            return round($s['pct']) . "%  #{$s['m']->product_id} [{$s['m']->sku}] {$s['m']->name}";
        }, array_slice($scored, 0, 3));
        $skipped[] = $c;
    }

    private function norm(string $s): string
    {
        $s = strtolower($s);
        $s = preg_replace('/[^a-z0-9]+/', ' ', $s);
        return trim(preg_replace('/\s+/', ' ', $s));
    }

    private function readCsv(string $path): array
    {
        $fh = fopen($path, 'r');
        $header = array_map(function ($h) {
            return strtolower(trim($h));
        }, fgetcsv($fh));
        $rows = [];
        while (($r = fgetcsv($fh)) !== false) {
            $r = array_combine($header, array_pad($r, count($header), ''));
            if (trim($r['qty'] ?? '') === '') {
                continue;
            }
            $rows[] = [
                'upc' => preg_replace('/\D/', '', $r['upc'] ?? ''),
                'qty' => (float) $r['qty'],
                'artist' => trim($r['artist'] ?? ''),
                'title' => trim($r['title'] ?? ''),
            ];
        }
        fclose($fh);
        return $rows;
    }

    private function fmt($n): string
    {
        return rtrim(rtrim(number_format((float) $n, 4, '.', ''), '0'), '.');
    }
}
