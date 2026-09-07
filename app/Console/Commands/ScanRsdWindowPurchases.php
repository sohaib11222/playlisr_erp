<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

/**
 * READ-ONLY — Sarah asked: within 3-4 weeks of any Black Friday RSD or
 * Spring RSD date, are there other purchases that might be RSD
 * exclusives the internet would recognize, even though the title
 * doesn't say "RSD" and the UPC isn't in any of our 3 distributor
 * grids?
 *
 * RSD dates used (Black Friday = day after US Thanksgiving; 2026
 * Black Friday hasn't happened yet as of this repo's current date, so
 * it's excluded):
 *   2024-04-20 (Spring), 2024-11-29 (Black Friday)
 *   2025-04-12 (Spring), 2025-11-28 (Black Friday)
 *   2026-04-18 (Spring)
 *
 * Window: 28 days before through 7 days after each date (distributor
 * deliveries land 2-3 weeks ahead per the batches already found; a
 * few days of after-date slack covers late receiving).
 *
 * For every purchase_lines row in any window, EXCLUDES:
 *   - products already covered by one of the 3 grids (leading-zero-
 *     normalized UPC match — already confirmed/handled)
 *   - products whose name already says RSD/Record Store Day (already
 *     confirmed/handled by nivessa:scan-rsd-named-products)
 * What's left is genuinely unaccounted-for: purchased near an RSD
 * date, no grid match, no RSD in the name. Flags each as a "plausible"
 * candidate if the name contains common limited-pressing signals
 * (color vinyl, live, anniversary, deluxe, limited, exclusive,
 * picture disc, etc.) — worth a manual/Discogs check — vs. "other"
 * (probably just ordinary stock that happened to land near the date).
 *
 * No writes.
 *
 * Usage:
 *   php artisan nivessa:scan-rsd-window-purchases
 */
class ScanRsdWindowPurchases extends Command
{
    protected $signature = 'nivessa:scan-rsd-window-purchases';

    protected $description = 'Read-only: purchases near RSD dates not already covered by a grid or RSD-named.';

    const EXCLUDE_NORMALIZED_UPC = '75678602399';

    const WINDOWS = [
        ['label' => 'RSD Spring 2024', 'date' => '2024-04-20'],
        ['label' => 'RSD Black Friday 2024', 'date' => '2024-11-29'],
        ['label' => 'RSD Spring 2025', 'date' => '2025-04-12'],
        ['label' => 'RSD Black Friday 2025', 'date' => '2025-11-28'],
        ['label' => 'RSD Spring 2026', 'date' => '2026-04-18'],
    ];

    const PLAUSIBLE_KEYWORDS = [
        'live', 'anniversary', 'deluxe', 'limited', 'exclusive', 'reissue',
        'picture disc', 'colou?red vinyl', 'color vinyl', 'splatter', 'marble',
        'swirl', 'translucent', 'opaque', 'etch', 'numbered', 'box set',
        'remaster', 'demo', 'outtake', 'rarit', 'unreleased', '\d+(th|st|nd|rd) ann',
        'clear vinyl', 'gold vinyl', 'silver vinyl', 'red vinyl', 'blue vinyl',
        'green vinyl', 'purple vinyl', 'pink vinyl', 'orange vinyl', 'yellow vinyl',
        'glow in the dark', 'picture vinyl', 'variant',
    ];

    public function handle()
    {
        $grid = json_decode(file_get_contents(resource_path('data/rsd-grid-titles.json')), true);
        $gridByNorm = [];
        foreach ($grid as $row) {
            $norm = ltrim($row['upc'], '0');
            if ($norm === '') $norm = '0';
            if (!isset($gridByNorm[$norm])) $gridByNorm[$norm] = $row;
        }
        unset($gridByNorm[self::EXCLUDE_NORMALIZED_UPC]);

        $keywordRegex = '(' . implode('|', self::PLAUSIBLE_KEYWORDS) . ')';

        $today = Carbon::now();
        $totalCandidates = 0;
        $totalPlausible = 0;

        foreach (self::WINDOWS as $w) {
            $center = Carbon::parse($w['date']);
            if ($center->gt($today)) {
                $this->line("Skipping {$w['label']} ({$w['date']}) — in the future.");
                continue;
            }
            $start = $center->copy()->subDays(28)->toDateString();
            $end = $center->copy()->addDays(7)->toDateString();

            $this->line(str_repeat('=', 70));
            $this->info("{$w['label']} — window {$start} to {$end}");

            $lines = DB::table('purchase_lines as pl')
                ->join('transactions as t', 't.id', '=', 'pl.transaction_id')
                ->join('products as p', 'p.id', '=', 'pl.product_id')
                ->whereBetween(DB::raw('DATE(t.transaction_date)'), [$start, $end])
                ->select('p.id', 'p.sku', 'p.name', 'pl.quantity', 't.transaction_date')
                ->get()
                ->unique('id');

            $this->line("  {$lines->count()} distinct product(s) purchased in this window.");

            $candidates = [];
            foreach ($lines as $l) {
                $norm = ltrim($l->sku ?? '', '0');
                if ($norm === '') $norm = '0';
                if (isset($gridByNorm[$norm])) continue; // already grid-covered
                if (preg_match('/RSD|RECORD STORE DAY/i', $l->name)) continue; // already RSD-named-scan-covered
                $candidates[] = $l;
            }

            $plausible = array_filter($candidates, fn ($l) => preg_match('/' . $keywordRegex . '/i', $l->name));

            $this->line('  ' . count($candidates) . ' not grid-covered / not RSD-named. ' . count($plausible) . ' look plausible (limited-pressing keywords):');
            $totalCandidates += count($candidates);
            $totalPlausible += count($plausible);

            foreach ($plausible as $l) {
                $total = DB::table('variation_location_details as vld')
                    ->join('variations as v', 'v.id', '=', 'vld.variation_id')
                    ->where('v.product_id', $l->id)
                    ->sum('vld.qty_available');
                $flag = $total > 0 ? '❌ qty=' . $total : '✅ zeroed';
                $this->line("    sku={$l->sku}  \"{$l->name}\"  purchased=" . substr($l->transaction_date, 0, 10) . "  {$flag}");
            }
        }

        $this->line("\n" . str_repeat('=', 70));
        $this->info("Total not grid-covered/not RSD-named across all windows: {$totalCandidates}");
        $this->info("Total flagged plausible (limited-pressing keywords): {$totalPlausible}");
        $this->line("\n(Read-only — no changes made.)");
        return 0;
    }
}
