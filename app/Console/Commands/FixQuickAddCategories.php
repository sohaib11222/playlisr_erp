<?php

namespace App\Console\Commands;

use App\Category;
use App\Product;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Reassign existing Quick Add preset products to the correct category.
 *
 * Context: until 2026-04-21 the POS Quick Add tiles (Soda, Gatorade,
 * Sticker, Pin, …) created manual-item products without passing a
 * category, so the backend fell back to the first top-level category
 * by id — which on Sarah's install happened to be "Children's CDs".
 * The tiles now declare their own category ("Snacks & Drinks" / "Swag"),
 * but every product created before the fix is still stuck in the wrong
 * bucket. This command backfills the fix across historical data.
 *
 * Safe by design:
 *   - Matches only products whose name is EXACTLY one of the preset
 *     names (case-insensitive). Regular catalog items with incidental
 *     matches are left alone.
 *   - Dry-run default; --commit to write.
 *   - Creates the target category if it doesn't exist yet.
 *   - Idempotent: rerunning is a no-op once everything's in place.
 *
 * Usage:
 *   php artisan nivessa:fix-quickadd-categories
 *   php artisan nivessa:fix-quickadd-categories --commit
 */
class FixQuickAddCategories extends Command
{
    protected $signature = 'nivessa:fix-quickadd-categories
                            {--business=1 : business_id to scope to}
                            {--commit : Actually write (default: dry-run)}';

    protected $description = 'Move Quick Add preset products (Soda, Sticker, etc.) into their correct category.';

    /**
     * Preset product name → target top-level category.
     * Keys are lower-cased for comparison. Values are the canonical
     * category names we want to land everything in.
     */
    const PRESET_MAP = [
        'soda'         => 'Snacks & Drinks',
        'soda (can)'   => 'Snacks & Drinks',
        'ginger beer'  => 'Snacks & Drinks',
        'energy drink' => 'Snacks & Drinks',
        'gatorade'     => 'Snacks & Drinks',
        'arizona'      => 'Snacks & Drinks',
        'iced coffee'  => 'Snacks & Drinks',
        'airheads'     => 'Snacks & Drinks',
        'candy'        => 'Snacks & Drinks',
        'chips'        => 'Snacks & Drinks',
        'pin'          => 'Swag',
        'sticker'      => 'Swag',
        'patch'        => 'Swag',
    ];

    public function handle()
    {
        $businessId = (int) $this->option('business');
        $commit = (bool) $this->option('commit');
        $userId = request()->session()->get('user.id') ?? 1;

        if (!$businessId) {
            $this->error('--business is required.');
            return 1;
        }

        // Step 1 — resolve or (if writing) create the target categories.
        $targetNames = array_values(array_unique(self::PRESET_MAP));
        $categoryIds = [];
        foreach ($targetNames as $name) {
            $cat = Category::where('business_id', $businessId)
                ->where('category_type', 'product')
                ->where('parent_id', 0)
                ->whereRaw('LOWER(name) = ?', [mb_strtolower($name)])
                ->first();
            if (!$cat) {
                if ($commit) {
                    $cat = Category::create([
                        'business_id' => $businessId,
                        'name' => $name,
                        'category_type' => 'product',
                        'parent_id' => 0,
                        'created_by' => $userId,
                    ]);
                    $this->line("  + created category '{$name}' (id {$cat->id})");
                } else {
                    $this->line("  · [dry-run] would create category '{$name}'");
                }
            }
            $categoryIds[$name] = $cat->id ?? null;
        }

        // Step 2 — find misfiled preset products.
        $summary = ['checked' => 0, 'already_correct' => 0, 'moved' => 0, 'skipped_no_cat' => 0];
        $moves = [];  // [ [id, name, from_cat, to_cat], ... ]

        foreach (self::PRESET_MAP as $presetName => $targetCatName) {
            $targetCatId = $categoryIds[$targetCatName] ?? null;
            // Dry-run may leave target ids null because we skipped category
            // creation — in that case we still want to count what WOULD move.

            $products = Product::where('business_id', $businessId)
                ->whereRaw('LOWER(name) = ?', [mb_strtolower($presetName)])
                ->get();

            foreach ($products as $p) {
                $summary['checked']++;
                $currentCatId = $p->category_id;
                if ($targetCatId && $currentCatId == $targetCatId) {
                    $summary['already_correct']++;
                    continue;
                }

                $fromLabel = $currentCatId
                    ? (optional(Category::find($currentCatId))->name ?? ('#' . $currentCatId))
                    : '(none)';

                $moves[] = [
                    'id' => $p->id,
                    'name' => $p->name,
                    'from' => $fromLabel,
                    'to' => $targetCatName,
                    'target_id' => $targetCatId,
                ];

                if ($commit && $targetCatId) {
                    $p->category_id = $targetCatId;
                    // Clear sub_category_id if the new parent has no children
                    // that match — we don't auto-remap sub-categories.
                    $p->sub_category_id = null;
                    $p->save();
                    $summary['moved']++;
                } elseif (!$targetCatId) {
                    $summary['skipped_no_cat']++;
                }
            }
        }

        $this->newLine();
        $this->info($commit ? '✅ Category fix complete.' : '🧪 DRY RUN — no rows written. Re-run with --commit.');
        $this->line(sprintf(
            "Checked: %d · Already correct: %d · %s: %d · Skipped (no target cat): %d",
            $summary['checked'], $summary['already_correct'],
            $commit ? 'Moved' : 'Would move', count($moves),
            $summary['skipped_no_cat']
        ));

        if (!empty($moves)) {
            $this->newLine();
            $this->line(str_pad('Product id', 12) . str_pad('Name', 24) . str_pad('From', 28) . 'To');
            $this->line(str_repeat('-', 88));
            foreach (array_slice($moves, 0, 30) as $m) {
                $this->line(
                    str_pad((string) $m['id'], 12)
                    . str_pad($this->truncate($m['name'], 22), 24)
                    . str_pad($this->truncate($m['from'], 26), 28)
                    . $m['to']
                );
            }
            if (count($moves) > 30) {
                $this->line("... + " . (count($moves) - 30) . " more");
            }
        }
        return 0;
    }

    private function truncate($s, $n)
    {
        if (mb_strlen($s) <= $n) return $s;
        return mb_substr($s, 0, $n - 1) . '…';
    }
}
