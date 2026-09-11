<?php

namespace App\Console\Commands;

use App\Category;
use App\SourcingTarget;
use Illuminate\Console\Command;

/**
 * Sarah 2026-09-11: seeds the starter category/sub-category taxonomy for
 * the new Sourcing module (toys, magazines, comics, trading cards, candy &
 * chips, drinks, apparel, electronics, trading card packs) plus a default
 * medium-priority sourcing_targets row for each. Reuses the existing
 * `categories` table (category_type=product) so sourced items can also be
 * tagged into these categories on the product record itself.
 *
 * Idempotent (firstOrCreate-style checks by name/parent) — safe to re-run.
 * Purely additive, but dry-run by default anyway to match this repo's
 * one-off-command convention.
 *
 *   php artisan sourcing:seed-categories            # dry run
 *   php artisan sourcing:seed-categories --commit    # actually create
 */
class SeedSourcingCategories extends Command
{
    protected $signature = 'sourcing:seed-categories
                            {--business=1 : business_id}
                            {--commit : Actually create the rows (default: dry-run)}';

    protected $description = 'Seed starter Sourcing categories/sub-categories and default sourcing_targets rows. Dry-run by default.';

    const TAXONOMY = [
        'Toys' => ['Squishies', 'Keychains', 'Magnets', 'Pins'],
        'Magazines' => ['Playboy', 'Mad Magazine', 'Rolling Stone'],
        'Comics' => ['Spider-Man', 'Batman', 'Superman'],
        'Trading Cards' => ['Michael Jordan Cards', 'Kobe Bryant Cards', 'Pokemon 1st Edition', 'Pokemon Slabs'],
        'Candy & Chips' => [],
        'Drinks' => [],
        'Apparel' => ['Shirts', 'Sweatshirts'],
        'Electronics' => ['Record Players', 'Guitars', 'DVD Players', 'CD Players', 'Portable CD Players', 'Walkmen'],
        'Trading Card Packs' => ['Pokemon Packs', 'One Piece Packs'],
    ];

    public function handle()
    {
        $businessId = (int) $this->option('business');
        $commit = (bool) $this->option('commit');

        $this->line('Mode: ' . ($commit ? 'COMMIT' : 'DRY RUN (no changes)'));
        $this->line(str_repeat('-', 64));

        $created = 0;
        $skipped = 0;

        foreach (self::TAXONOMY as $parentName => $subNames) {
            $parent = $this->ensureCategory($businessId, $parentName, 0, $commit, $created, $skipped);
            $this->ensureTarget($businessId, $parent, $commit, $parentName);

            foreach ($subNames as $subName) {
                $sub = $this->ensureCategory($businessId, $subName, $parent ? $parent->id : 0, $commit, $created, $skipped, "  ", $parentName);
                $this->ensureTarget($businessId, $sub, $commit, "  {$subName}");
            }
        }

        $this->line(str_repeat('-', 64));
        $this->line('Categories ' . ($commit ? 'created' : 'that WOULD be created') . ": {$created}");
        $this->line("Already existed: {$skipped}");
        $this->line('');
        if ($commit) {
            $this->info('COMMIT complete.');
        } else {
            $this->info('DRY RUN complete — nothing was written. Re-run with --commit to apply.');
        }

        return 0;
    }

    private function ensureCategory($businessId, $name, $parentId, $commit, &$created, &$skipped, $indent = '', $parentLabel = null)
    {
        $existing = Category::where('business_id', $businessId)
            ->where('category_type', 'product')
            ->where('parent_id', $parentId)
            ->where('name', $name)
            ->first();

        if ($existing) {
            $skipped++;
            return $existing;
        }

        $suffix = $parentLabel ? " (under {$parentLabel})" : '';
        $this->line($indent . ($commit ? 'Creating category: ' : 'Would create category: ') . $name . $suffix);
        $created++;

        if (!$commit) {
            return null;
        }

        return Category::create([
            'business_id' => $businessId,
            'name' => $name,
            'parent_id' => $parentId,
            'category_type' => 'product',
            'created_by' => auth()->id() ?? 1,
        ]);
    }

    private function ensureTarget($businessId, $category, $commit, $label)
    {
        if (!$category) {
            return;
        }

        $exists = SourcingTarget::where('business_id', $businessId)
            ->where('category_id', $category->id)
            ->exists();

        if ($exists) {
            return;
        }

        $this->line("  -> " . ($commit ? 'Creating' : 'Would create') . " sourcing_target for: {$label}");

        if ($commit) {
            SourcingTarget::create([
                'business_id' => $businessId,
                'category_id' => $category->id,
                'priority' => 'medium',
            ]);
        }
    }
}
