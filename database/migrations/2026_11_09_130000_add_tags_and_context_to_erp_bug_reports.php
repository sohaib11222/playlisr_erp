<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Bug queue: who the reporter tagged (",3,17," for LIKE lookups) and the
 * browser context captured with the report (user agent, screen, recent
 * JS errors) so the fixer can reproduce it.
 */
class AddTagsAndContextToErpBugReports extends Migration
{
    public function up()
    {
        Schema::table('erp_bug_reports', function (Blueprint $table) {
            if (!Schema::hasColumn('erp_bug_reports', 'tagged_user_ids')) {
                $table->string('tagged_user_ids', 500)->nullable()->after('reporter_name');
            }
            if (!Schema::hasColumn('erp_bug_reports', 'client_context')) {
                $table->text('client_context')->nullable()->after('screenshot_path');
            }
        });
    }

    public function down()
    {
        Schema::table('erp_bug_reports', function (Blueprint $table) {
            $table->dropColumn(['tagged_user_ids', 'client_context']);
        });
    }
}
