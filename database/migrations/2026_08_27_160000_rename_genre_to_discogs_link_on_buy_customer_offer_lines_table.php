<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class RenameGenreToDiscogsLinkOnBuyCustomerOfferLinesTable extends Migration
{
    public function up()
    {
        Schema::table('buy_customer_offer_lines', function (Blueprint $table) {
            $table->string('discogs_link')->nullable()->after('genre');
        });

        DB::table('buy_customer_offer_lines')->update([
            'discogs_link' => DB::raw('genre'),
        ]);

        Schema::table('buy_customer_offer_lines', function (Blueprint $table) {
            $table->dropColumn('genre');
        });
    }

    public function down()
    {
        Schema::table('buy_customer_offer_lines', function (Blueprint $table) {
            $table->string('genre')->nullable()->after('discogs_link');
        });

        DB::table('buy_customer_offer_lines')->update([
            'genre' => DB::raw('discogs_link'),
        ]);

        Schema::table('buy_customer_offer_lines', function (Blueprint $table) {
            $table->dropColumn('discogs_link');
        });
    }
}
