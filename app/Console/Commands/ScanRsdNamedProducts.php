<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * READ-ONLY — Sarah asked: beyond the 3 distributor grids (846 rows —
 * AMS RSD 2026, AMS RSD Black Friday 2024, Alliance RSD 2025), are
 * there other ERP purchases whose title says "RSD" / "Record Store
 * Day" that we haven't accounted for?
 *
 * Scans EVERY product whose name mentions RSD/Record Store Day
 * (regardless of sku shape — not limited to numeric barcodes, unlike
 * the grid-matching commands), keeps only ones that were actually
 * purchased (a purchase_lines row exists — i.e. real received stock,
 * not just a Discogs-synced listing), and separates:
 *   - already covered by the grid (leading-zero-normalized UPC match)
 *   - NOT in any of the 3 grids — an RSD title we have no distributor
 *     sheet for at all (different year, different distributor, direct
 *     from label, etc.)
 * For both groups, reports current qty_available and purchase date(s),
 * so it's clear what's zeroed already and what a purchase date this
 * title could be cross-checked against.
 *
 * No writes.
 *
 * Usage:
 *   php artisan nivessa:scan-rsd-named-products
 */
class ScanRsdNamedProducts extends Command
{
    protected $signature = 'nivessa:scan-rsd-named-products';

    protected $description = 'Read-only: find purchased products whose name says RSD/Record Store Day, in or out of the 3 known grids.';

    const EXCLUDE_NORMALIZED_UPC = '75678602399';

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

        $named = DB::table('products')
            ->where(function ($q) {
                $q->where('name', 'like', '%RSD%')
                  ->orWhere('name', 'like', '%RECORD STORE DAY%')
                  ->orWhere('name', 'like', '%Record Store Day%');
            })
            ->select('id', 'sku', 'name')
            ->get();

        $this->info("Scanned products with RSD/Record Store Day in the name: {$named->count()}");

        // Keep only ones with at least one purchase_lines row (actually received).
        $ids = $named->pluck('id')->all();
        $purchaseDates = DB::table('purchase_lines as pl')
            ->join('transactions as t', 't.id', '=', 'pl.transaction_id')
            ->whereIn('pl.product_id', $ids)
            ->select('pl.product_id', 't.transaction_date')
            ->get()
            ->groupBy('product_id');

        $purchased = $named->filter(fn ($p) => $purchaseDates->has($p->id));
        $this->line("Of those, actually purchased (has a purchase_lines row): {$purchased->count()}\n");

        $inGrid = [];
        $notInGrid = [];
        foreach ($purchased as $p) {
            $norm = ltrim($p->sku ?? '', '0');
            if ($norm === '') $norm = '0';
            $target = isset($gridByNorm[$norm]) ? $inGrid : $notInGrid;
            $total = DB::table('variation_location_details as vld')
                ->join('variations as v', 'v.id', '=', 'vld.variation_id')
                ->where('v.product_id', $p->id)
                ->sum('vld.qty_available');
            $dates = $purchaseDates->get($p->id)->pluck('transaction_date')->map(fn ($d) => substr($d, 0, 10))->unique()->implode(', ');

            if (isset($gridByNorm[$norm])) {
                $inGrid[] = (object) ['p' => $p, 'total' => $total, 'dates' => $dates];
            } else {
                $notInGrid[] = (object) ['p' => $p, 'total' => $total, 'dates' => $dates];
            }
        }

        $this->line(str_repeat('=', 70));
        $this->info('IN one of the 3 known grids (' . count($inGrid) . '):');
        $inGridNonzero = 0;
        foreach ($inGrid as $r) {
            if ($r->total > 0) {
                $inGridNonzero++;
                $this->line("  ❌ sku={$r->p->sku}  qty={$r->total}  \"{$r->p->name}\"  purchased={$r->dates}");
            }
        }
        $this->line($inGridNonzero === 0 ? '  ✅ all zeroed already' : "  → {$inGridNonzero} still showing real stock");

        $this->line(str_repeat('=', 70));
        $this->info('NOT in any of the 3 known grids (' . count($notInGrid) . ') — RSD-named but no distributor sheet for these:');
        foreach ($notInGrid as $r) {
            $flag = $r->total > 0 ? '❌ STILL SHOWING STOCK' : '✅ zeroed';
            $this->line("  sku={$r->p->sku}  qty={$r->total}  \"{$r->p->name}\"  purchased={$r->dates}  {$flag}");
        }

        $this->line("\n(Read-only — no changes made.)");
        return 0;
    }
}
