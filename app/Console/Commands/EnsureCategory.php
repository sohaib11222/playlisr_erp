<?php

namespace App\Console\Commands;

use App\Category;
use Illuminate\Console\Command;

/**
 * Create a top-level product category and its subcategories if missing
 * (Sarah 2026-11-09: Autographs > Posters / CD / Vinyl). Idempotent;
 * dry-run by default. Also lists existing categories whose name matches
 * --like so near-duplicates are visible before writing.
 *
 *   php artisan nivessa:ensure-category "Autographs" --sub=Posters --sub=CD --sub=Vinyl --like=autograph --like=signed
 *   ... --commit
 */
class EnsureCategory extends Command
{
    protected $signature = 'nivessa:ensure-category
                            {parent : Top-level category name}
                            {--sub=* : Subcategory name (repeatable)}
                            {--like=* : Also list existing categories containing this text}
                            {--business=1 : business_id}
                            {--commit : Actually write (default: dry-run)}';

    protected $description = 'Create a product category + subcategories if they do not exist.';

    public function handle()
    {
        $bid = (int) $this->option('business');
        $commit = (bool) $this->option('commit');
        $parent = trim((string) $this->argument('parent'));
        $cats = Category::where('business_id', $bid)->where('category_type', 'product')->get();

        foreach ((array) $this->option('like') as $like) {
            $this->info("Existing categories like '{$like}':");
            foreach ($cats as $c) {
                if (stripos($c->name, $like) !== false) {
                    $this->line(sprintf('  id %d  %s  (parent %d)%s', $c->id, $c->name, (int) $c->parent_id, $c->deleted_at ? ' [deleted]' : ''));
                }
            }
        }

        $p = $cats->first(function ($c) use ($parent) {
            return (int) $c->parent_id === 0 && mb_strtolower(trim($c->name)) === mb_strtolower($parent);
        });
        if ($p) {
            $this->line("= {$parent} exists (id {$p->id})");
        } elseif ($commit) {
            $p = Category::create(['business_id' => $bid, 'name' => $parent, 'category_type' => 'product', 'parent_id' => 0, 'created_by' => 1]);
            $this->line("+ created {$parent} (id {$p->id})");
        } else {
            $this->line("· [dry-run] would create {$parent}");
        }

        foreach ((array) $this->option('sub') as $sub) {
            $sub = trim($sub);
            if ($sub === '') {
                continue;
            }
            $s = $p ? $cats->first(function ($c) use ($p, $sub) {
                return (int) $c->parent_id === (int) $p->id && mb_strtolower(trim($c->name)) === mb_strtolower($sub);
            }) : null;
            if ($s) {
                $this->line("  = {$parent} > {$sub} exists (id {$s->id})");
            } elseif ($commit) {
                $s = Category::create(['business_id' => $bid, 'name' => $sub, 'category_type' => 'product', 'parent_id' => $p->id, 'created_by' => 1]);
                $this->line("  + created {$parent} > {$sub} (id {$s->id})");
            } else {
                $this->line("  · [dry-run] would create {$parent} > {$sub}");
            }
        }
        return 0;
    }
}
