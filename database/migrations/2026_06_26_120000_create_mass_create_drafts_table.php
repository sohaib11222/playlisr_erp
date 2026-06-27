<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-employee "save for next time" draft of the mass-create (mass-add)
 * product form. When an operator has several in-progress rows and clicks out
 * of /product/mass-create, their rows are stashed here so they can pick them
 * back up later from any machine (the form used to only keep them in the
 * browser). One draft per user per business — re-saving overwrites it.
 */
class CreateMassCreateDraftsTable extends Migration
{
    public function up()
    {
        if (!Schema::hasTable('mass_create_drafts')) {
            Schema::create('mass_create_drafts', function (Blueprint $table) {
                $table->increments('id');
                $table->unsignedInteger('business_id');
                $table->unsignedInteger('user_id');
                // How many product rows the draft holds (for the restore prompt).
                $table->unsignedInteger('item_count')->default(0);
                // JSON array of the in-progress rows (field-suffix => value).
                $table->longText('payload')->nullable();
                $table->timestamps();

                // One draft per user — saving again upserts this row.
                $table->unique(['business_id', 'user_id'], 'mcd_biz_user_uniq');
            });
        }
    }

    public function down()
    {
        Schema::dropIfExists('mass_create_drafts');
    }
}
