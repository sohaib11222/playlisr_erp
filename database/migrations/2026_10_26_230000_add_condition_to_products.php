<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Record + sleeve grade (Discogs wording) set from mass-add's Bulk Discogs IDs
// box, synced to the website listing (Sarah, 2026-10-26).
class AddConditionToProducts extends Migration
{
    public function up()
    {
        Schema::table('products', function (Blueprint $table) {
            if (!Schema::hasColumn('products', 'condition')) $table->string('condition', 40)->nullable();
            if (!Schema::hasColumn('products', 'sleeve_condition')) $table->string('sleeve_condition', 40)->nullable();
        });
    }

    public function down()
    {
        Schema::table('products', function (Blueprint $table) {
            if (Schema::hasColumn('products', 'condition')) $table->dropColumn('condition');
            if (Schema::hasColumn('products', 'sleeve_condition')) $table->dropColumn('sleeve_condition');
        });
    }
}
