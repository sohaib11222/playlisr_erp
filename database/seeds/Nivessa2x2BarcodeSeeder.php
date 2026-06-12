<?php

use Illuminate\Database\Seeder;
use App\Barcode;

/**
 * Adds the "Nivessa 2\" x 2\"" continuous-roll label setting that prints a
 * large barcode with the Nivessa logo. Idempotent: matches on name so it can
 * be run safely on production without duplicating the row or colliding with
 * existing auto-increment ids.
 *
 *   php artisan db:seed --class=Nivessa2x2BarcodeSeeder
 */
class Nivessa2x2BarcodeSeeder extends Seeder
{
    public function run()
    {
        Barcode::updateOrCreate(
            ['name' => 'Nivessa 2" x 2" (Large Barcode + Logo)', 'business_id' => null],
            [
                'description' => 'Continuous Roll, Label Size: 2" x 2", large barcode with Nivessa logo',
                'width' => 2,
                'height' => 2,
                'paper_width' => 2,
                'paper_height' => 0.00,
                'top_margin' => 0.00,
                'left_margin' => 0.00,
                'row_distance' => 0.00,
                'col_distance' => 0.00,
                'stickers_in_one_row' => 1,
                'is_default' => 0,
                'is_continuous' => 1,
                'stickers_in_one_sheet' => null,
            ]
        );
    }
}
