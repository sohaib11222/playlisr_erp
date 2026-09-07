<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use App\Product;
use App\Services\NivessaStockNotifier;

/**
 * Zero out qty_available (all locations) for the RSD-exclusive titles that
 * scripts/audit-rsd-grid-titles.js / nivessa:audit-rsd-grid-stock found
 * still showing real stock, exact-matched by UPC/sku against three
 * distributor order grids (AMS RSD 2026, AMS RSD Black Friday 2024,
 * Alliance RSD 2025 — 846 rows, resources/data/rsd-grid-titles.json).
 * These are RSD-exclusive titles from pre-order grids, not confirmed
 * inventory — they shouldn't be showing as buyable stock.
 *
 * Matches by whereIn()->get() (not first()) since some SKUs here are
 * shared by more than one product row (e.g. 613365096917 / Juice Crew).
 *
 * Dry-run by default (reports current stock per SKU/location). --commit
 * writes qty_available = 0 and pushes to the website via
 * NivessaStockNotifier, same as ZeroOutRsdSoldStock.
 *
 * Usage:
 *   php artisan nivessa:zero-rsd-grid-stock
 *   php artisan nivessa:zero-rsd-grid-stock --commit
 */
class ZeroOutRsdGridStock extends Command
{
    protected $signature = 'nivessa:zero-rsd-grid-stock {--commit : Actually write (default: dry-run)}';

    protected $description = 'Zero qty_available for RSD grid titles confirmed still showing real ERP stock, and push to the website.';

    const SKUS = [
        '075678611582', // qty=1 — OhGeesy - GeezyWorld — OHGEESY / GEEZYWORLD (WHIRLPOOL (CLEAR W/ BLUE MIXED) VINYL) (RSD) [AMS RSD Black Friday 2024]
        '198704890710', // qty=1 — Live at the Ritz NYC 1981 — JETT,JOAN & THE BLACKHEARTS / LIVE AT THE RITZ NYC 1981 (RSD) [AMS RSD 2026]
        '3700477837457', // qty=14 — Snoop Dogg - Live at Forest National 2005 [AMS RSD Black Friday 2024]
        '3700477837921', // qty=5 — Cypress Hill - Live at Rock Im Park 1999 [AMS RSD Black Friday 2024]
        '3700477838454', // qty=16 — Scott-Heron,Gil & Brian Jackson / From South Africa To South Carolina [AMS RSD Black Friday 2024]
        '4099964043556', // qty=1 — Dokken / Beast From The East (Live) [Alliance RSD 2025]
        '4099964048537', // qty=1 — Harrison, George / All Things Must Pass [Zoetrope 3 LP] [Alliance RSD 2025]
        '4099964206951', // qty=1 — Harrison,George / Dark Horse (50th Anniversary/Zoetrope) (RSD) [AMS RSD 2026]
        '4099964206968', // qty=1 — Harrison,George / Extra Texture (50th Anniversary/Zoetrope) (RSD) [AMS RSD 2026]
        '4099964216356', // qty=5 — Modern Lovers / Modern Lovers (Picture Disc/50th Anniversary) (RSD) [AMS RSD 2026]
        '4099964223729', // qty=3 — Puscifer / Normal Isn't: Live At The Pacific Exchange (RSD) [AMS RSD 2026]
        '4251981712499', // qty=1 — Meshuggah / Destroy Erase Improve: 30th Anniversary Edition (RSD) [AMS RSD 2026]
        '5014797912434', // qty=2 — Pixies / Bossanova / Trompe Le Mode - Live From Europe 2023 [Alliance RSD 2025]
        '5014797913363', // qty=3 — Siffre,Labi / Crying Laughing Loving Lying: Expanded Edition (RSD) [AMS RSD 2026]
        '5021732526007', // qty=4 — Gorillaz / Demon Days Live From The Apollo Theater [Alliance RSD 2025]
        '5021732546197', // qty=3 — Charli xcx / Number 1 Angel [Alliance RSD 2025]
        '5021732551757', // qty=2 — Fred Again... / Actual Life Piano Live (20th November 2024) IRL003 [Alliance RSD 2025]
        '5021732551764', // qty=2 — Fred Again... / Actual Life 2 Piano Live (20th March, 2022) IRL004 [Alliance RSD 2025]
        '5021732943996', // qty=1 — Bowie,David / Excerpts From Outside (Clear Vinyl/Half Speed) (RSD) [AMS RSD 2026]
        '5026328302560', // qty=2 — Soul Jazz Records Presents / Studio One Sound (Transparent Green/2LP) (RSD) [AMS RSD 2026]
        '5056167180241', // qty=10 — Beaches / Blame Jocelyn (Cloudy Clear 7Inch) (RSD) [AMS RSD Black Friday 2024]
        '5056167180258', // qty=1 — DJO / Decide (Deluxe/Picture Disc) (RSD) [AMS RSD Black Friday 2024]
        '5056167180265', // qty=5 — Jungle - Back on 74 [AMS RSD Black Friday 2024]
        '5061110805072', // qty=2 — Gong / Flying Teapot (RSD) [AMS RSD 2026]
        '613365096917', // qty=1+1 (2 product rows) — RZA/Juice Crew/Big Daddy Kane/Craig G/Bobby Digital Presents: Juice Crew (RSD) [AMS RSD 2026]
        '780518324590', // qty=1 — Henderson,Joe / Consonance: Live At The Jazz Showcase (RSD) [AMS RSD 2026]
        '850054327154', // qty=1 — My Life With The Thrill Kill Kult / Sinister Whispers: The Wax Trax! Remixes (RSD) [AMS RSD 2026]
    ];

    public function handle()
    {
        $commit = (bool) $this->option('commit');
        $this->info($commit ? '🟢 COMMIT — writing changes' : '🔵 DRY RUN — no writes');
        $this->line('Targeting ' . count(self::SKUS) . " SKU(s).\n");

        $products = Product::whereIn('sku', self::SKUS)->get();

        $foundSkus = $products->pluck('sku')->unique();
        $missing = collect(self::SKUS)->diff($foundSkus);

        $pushIds = [];

        foreach ($products as $product) {
            $rows = DB::table('variation_location_details as vld')
                ->join('variations as v', 'v.id', '=', 'vld.variation_id')
                ->where('v.product_id', $product->id)
                ->select('vld.id', 'vld.location_id', 'vld.qty_available')
                ->get();

            $this->line("  SKU {$product->sku} — product #{$product->id} \"{$product->name}\"");
            foreach ($rows as $row) {
                $this->line("      location {$row->location_id}: qty_available {$row->qty_available} → 0");
            }

            if ($commit) {
                DB::table('variation_location_details')
                    ->join('variations as v', 'variation_location_details.variation_id', '=', 'v.id')
                    ->where('v.product_id', $product->id)
                    ->update(['variation_location_details.qty_available' => 0]);
                $pushIds[] = (int) $product->id;
            }
        }

        if ($missing->isNotEmpty()) {
            $this->error("\n  " . $missing->count() . ' SKU(s) not found in ERP: ' . $missing->implode(', '));
        }

        if ($commit && !empty($pushIds)) {
            (new NivessaStockNotifier())->push($pushIds);
            $this->info("\n✅ Zeroed " . count($pushIds) . ' product row(s) and pushed to the website.');
        } elseif (!$commit) {
            $this->info("\nRe-run with --commit to write and push.");
        } else {
            $this->info("\nNothing to commit — no matching products found.");
        }

        return 0;
    }
}
