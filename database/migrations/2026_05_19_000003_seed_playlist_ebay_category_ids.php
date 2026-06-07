<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class SeedPlaylistEbayCategoryIds extends Migration
{
    /**
     * Default eBay US category IDs for common Playlist product types.
     * Sarah can override per category in Taxonomies admin.
     */
    private $defaults = [
        'Vinyl' => '176985',
        'Used Vinyl' => '176985',
        'New Vinyl' => '176985',
        'CD' => '176984',
        'CDs' => '176984',
        'Cassette' => '176983',
        'Cassettes' => '176983',
        'DVD' => '617',
        'Blu-ray' => '617',
        'Merch' => '11450',
        'Accessories' => '11450',
        'Turntables' => '48458',
        'Equipment' => '48458',
    ];

    public function up()
    {
        if (!Schema::hasColumn('categories', 'ebay_category_ids')) {
            return;
        }

        foreach ($this->defaults as $name => $ebayId) {
            DB::table('categories')
                ->where('category_type', 'product')
                ->whereNull('ebay_category_ids')
                ->where(function ($q) use ($name) {
                    $q->where('name', $name)
                        ->orWhere('name', 'like', $name . '%');
                })
                ->update(['ebay_category_ids' => $ebayId]);
        }
    }

    public function down()
    {
        // Non-destructive: leave seeded values in place on rollback.
    }
}
