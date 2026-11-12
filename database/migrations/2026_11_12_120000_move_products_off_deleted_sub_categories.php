<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Products still pointing at a deleted sub-category show up as their own tiny
 * bin on Shelf Count (Sarah 11/12: "why are there repeat genre categories w 1
 * title"), e.g. sealed "Alternative" next to "Alt / Indie Rock". Move each one
 * to the live sub-category of the same name under the same parent (or the
 * genre it was folded into); none left = no sub-category. Old values are
 * snapshotted to storage/app and down() puts them back. updated_at is not
 * touched so no website/Clover re-sync wave.
 */
class MoveProductsOffDeletedSubCategories extends Migration
{
    const FILE = 'deleted-subcat-move-2026-11-12.json';
    const ALIAS = ['alternative' => 'alt / indie rock', 'soul' => 'r&b, soul & funk', 'r&b' => 'r&b, soul & funk',
        'dance' => 'electronic/dance', 'punk / hardcore' => 'punk'];

    public function up()
    {
        $dead = DB::table('categories')->whereNotNull('deleted_at')->where('parent_id', '>', 0)->get(['id', 'name', 'parent_id']);
        $live = DB::table('categories')->whereNull('deleted_at')->where('parent_id', '>', 0)->get(['id', 'name', 'parent_id']);
        $byName = [];
        foreach ($live as $c) $byName[$c->parent_id][strtolower(trim($c->name))] = (int) $c->id;
        $snap = [];
        foreach ($dead as $d) {
            $n = strtolower(trim($d->name));
            $to = $byName[$d->parent_id][$n] ?? $byName[$d->parent_id][self::ALIAS[$n] ?? ''] ?? null;
            $ids = DB::table('products')->where('sub_category_id', $d->id)->pluck('id')->all();
            if (!$ids) continue;
            foreach ($ids as $pid) $snap[] = [(int) $pid, (int) $d->id];
            DB::table('products')->whereIn('id', $ids)->update(['sub_category_id' => $to]);
            echo "sub {$d->id} {$d->name} (parent {$d->parent_id}) -> " . ($to ?: 'none') . ': ' . count($ids) . " products\n";
        }
        if ($snap) file_put_contents(storage_path('app/' . self::FILE), json_encode($snap));
    }

    public function down()
    {
        $f = storage_path('app/' . self::FILE);
        if (!is_file($f)) return;
        foreach (json_decode(file_get_contents($f), true) as [$pid, $sub]) DB::table('products')->where('id', $pid)->update(['sub_category_id' => $sub]);
    }
}
