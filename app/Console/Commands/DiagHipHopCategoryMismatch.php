<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

/**
 * One-off, read-only diagnostic: Sarah says she already has a "Hip Hop"
 * sub-category, but the genre backfill's matcher (scoped to
 * parent_id = the specific product's format category_id) reported 101
 * unmatched "Hip Hop" hits. Find out why — most likely "Hip Hop" exists
 * under only SOME format categories (e.g. Vinyl) but not others (CD,
 * Cassette, etc.), since matchExistingSubCategory only ever looks at the
 * product's own parent category. Writes nothing.
 */
class DiagHipHopCategoryMismatch extends Command
{
    protected $signature = 'diag:hip-hop-category {--business=1}';
    protected $description = 'Read-only: find every "Hip Hop"-ish category row and which format categories are missing one.';

    public function handle()
    {
        $businessId = (int) $this->option('business');

        $this->info('All categories matching "hip hop" / "hip-hop" (any parent):');
        $rows = \DB::table('categories')
            ->where('business_id', $businessId)
            ->where('category_type', 'product')
            ->whereNull('deleted_at')
            ->whereRaw("LOWER(name) LIKE ?", ['%hip%hop%'])
            ->select('id', 'name', 'parent_id')
            ->get();
        foreach ($rows as $r) {
            $parentName = $r->parent_id
                ? (\DB::table('categories')->where('id', $r->parent_id)->value('name') ?? "id {$r->parent_id} (not found)")
                : '(top-level)';
            $this->line("  id={$r->id}  name=\"{$r->name}\"  parent_id={$r->parent_id}  parent=\"{$parentName}\"");
        }
        if ($rows->isEmpty()) {
            $this->line('  none found');
        }

        $this->info('');
        $this->info('Format (top-level) categories, and whether each has a Hip Hop child:');
        $hipHopParentIds = $rows->pluck('parent_id')->filter()->unique();
        $formats = \DB::table('categories')
            ->where('business_id', $businessId)
            ->where('category_type', 'product')
            ->whereNull('deleted_at')
            ->where(function ($q) { $q->whereNull('parent_id')->orWhere('parent_id', 0); })
            ->select('id', 'name')
            ->get();
        foreach ($formats as $f) {
            $has = $hipHopParentIds->contains($f->id) ? 'YES' : 'no';
            $productCount = \DB::table('products')->where('business_id', $businessId)->where('category_id', $f->id)->count();
            $this->line("  {$has}  id={$f->id}  \"{$f->name}\"  ({$productCount} products)");
        }

        return 0;
    }
}
