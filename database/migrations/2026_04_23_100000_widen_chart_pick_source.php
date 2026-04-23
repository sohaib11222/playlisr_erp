<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * chart_picks.source + chart_pick_imports.source started as ENUM
 * ('street_pulse','universal_top'). We're adding 'apple_music_top' and
 * likely more chart sources later (Spotify, Billboard). Switch both
 * columns to VARCHAR so adding new sources no longer needs a migration.
 */
class WidenChartPickSource extends Migration
{
    public function up()
    {
        if (Schema::hasTable('chart_picks')) {
            DB::statement("ALTER TABLE chart_picks MODIFY COLUMN source VARCHAR(64) NOT NULL");
        }
        if (Schema::hasTable('chart_pick_imports')) {
            DB::statement("ALTER TABLE chart_pick_imports MODIFY COLUMN source VARCHAR(64) NOT NULL");
        }
    }

    public function down()
    {
        if (Schema::hasTable('chart_picks')) {
            DB::statement("ALTER TABLE chart_picks MODIFY COLUMN source ENUM('street_pulse','universal_top') NOT NULL");
        }
        if (Schema::hasTable('chart_pick_imports')) {
            DB::statement("ALTER TABLE chart_pick_imports MODIFY COLUMN source ENUM('street_pulse','universal_top') NOT NULL");
        }
    }
}
