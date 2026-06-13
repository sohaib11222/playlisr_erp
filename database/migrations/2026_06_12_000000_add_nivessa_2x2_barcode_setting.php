<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Adds the global "Nivessa 2\" x 2\"" continuous-roll barcode setting so it
 * appears in the Barcode Setting dropdown on /labels/show. Done as a migration
 * (not just a seeder) because production deploys are git-sync only and never
 * run seeders. Idempotent: only inserts if the named row is missing.
 */
class AddNivessa2x2BarcodeSetting extends Migration
{
    private $name = 'Nivessa 2" x 2" (Large Barcode + Logo)';

    public function up()
    {
        $exists = DB::table('barcodes')
            ->where('name', $this->name)
            ->whereNull('business_id')
            ->exists();

        if (! $exists) {
            DB::table('barcodes')->insert([
                'name' => $this->name,
                'description' => 'Continuous Roll, Label Size: 2" x 2", large barcode with Nivessa logo',
                'width' => 2,
                'height' => 2,
                'paper_width' => 2,
                'paper_height' => 0,
                'top_margin' => 0,
                'left_margin' => 0,
                'row_distance' => 0,
                'col_distance' => 0,
                'stickers_in_one_row' => 1,
                'is_default' => 0,
                'is_continuous' => 1,
                'stickers_in_one_sheet' => null,
                'business_id' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down()
    {
        DB::table('barcodes')
            ->where('name', $this->name)
            ->whereNull('business_id')
            ->delete();
    }
}
