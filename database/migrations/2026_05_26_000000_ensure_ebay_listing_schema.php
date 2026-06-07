<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Safety-net migration: ensures eBay listing schema exists even if the
 * 2026_05_19_* migrations were skipped or recorded without applying changes.
 */
class EnsureEbayListingSchema extends Migration
{
    private $categoryDefaults = [
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
            Schema::table('categories', function (Blueprint $table) {
                $table->string('ebay_category_ids', 1000)->nullable()->after('description');
            });
        }

        if (!Schema::hasColumn('products', 'ebay_offer_id')) {
            Schema::table('products', function (Blueprint $table) {
                $after = Schema::hasColumn('products', 'ebay_listing_id')
                    ? 'ebay_listing_id'
                    : 'listing_location';
                $table->string('ebay_offer_id')->nullable()->after($after);
            });
        }

        if (!Schema::hasColumn('products', 'ebay_listing_id')) {
            Schema::table('products', function (Blueprint $table) {
                $after = Schema::hasColumn('products', 'listing_location') ? 'listing_location' : 'id';
                $table->string('ebay_listing_id')->nullable()->after($after);
            });
        }

        if (!Schema::hasColumn('products', 'listing_status')) {
            Schema::table('products', function (Blueprint $table) {
                $after = Schema::hasColumn('products', 'discogs_listing_id')
                    ? 'discogs_listing_id'
                    : (Schema::hasColumn('products', 'ebay_listing_id') ? 'ebay_listing_id' : 'id');
                $table->enum('listing_status', ['not_listed', 'listed', 'error'])
                    ->default('not_listed')
                    ->after($after);
                $table->index('listing_status');
            });
        }

        foreach ($this->categoryDefaults as $name => $ebayId) {
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
        // Non-destructive rollback — leave columns and seeds in place.
    }
}
