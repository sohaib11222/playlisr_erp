<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Replaces the upload-a-photo-to-#taskphotos flow (requires_photo + photo
// columns from 2026_09_15_110000) with a simpler confirm-only gate per
// direction: employees post the photo to #taskphotos in Slack themselves,
// the app just requires an explicit acknowledgement before completion —
// no upload, no Slack webhook integration. `photo` stays (unused, no data
// to lose) so this isn't destructive; `photo_confirmed_by`/`_at` mirror
// the existing completed_by/completed_at pattern.
class AddPhotoConfirmedToWeeklyTasksTable extends Migration
{
    public function up()
    {
        if (Schema::hasTable('weekly_tasks') && !Schema::hasColumn('weekly_tasks', 'photo_confirmed_by')) {
            Schema::table('weekly_tasks', function (Blueprint $table) {
                $table->integer('photo_confirmed_by')->unsigned()->nullable()->after('requires_photo');
                $table->foreign('photo_confirmed_by')->references('id')->on('users')->onDelete('set null');
                $table->timestamp('photo_confirmed_at')->nullable()->after('photo_confirmed_by');
            });
        }
    }

    public function down()
    {
        if (Schema::hasTable('weekly_tasks') && Schema::hasColumn('weekly_tasks', 'photo_confirmed_by')) {
            Schema::table('weekly_tasks', function (Blueprint $table) {
                $table->dropForeign(['photo_confirmed_by']);
                $table->dropColumn(['photo_confirmed_by', 'photo_confirmed_at']);
            });
        }
    }
}
