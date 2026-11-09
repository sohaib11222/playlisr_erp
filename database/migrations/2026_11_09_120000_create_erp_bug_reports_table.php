<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Bug queue (Sarah 2026-11-09): staff report ERP bugs from any page, the
 * bugs routine opens a fix PR for each, and Sarah or Jon approve on
 * /bug-fixes, which merges the PR (and so deploys it).
 */
class CreateErpBugReportsTable extends Migration
{
    public function up()
    {
        if (Schema::hasTable('erp_bug_reports')) {
            return;
        }
        Schema::create('erp_bug_reports', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('business_id')->nullable();
            $table->unsignedInteger('reported_by')->nullable();   // users.id (null when from Slack)
            $table->string('reporter_name', 191)->nullable();
            $table->string('source', 16)->default('erp');         // erp | slack
            $table->string('kind', 16)->default('bug');           // bug | request (a fix / change someone wants)
            $table->string('slack_ts', 64)->nullable()->index();  // dedupe Slack imports
            $table->string('page_url', 1000)->nullable();
            $table->string('location_name', 191)->nullable();
            $table->text('description');
            $table->string('screenshot_path', 255)->nullable();
            // new -> fix_ready -> merged ; also needs_info, rework, wont_fix
            $table->string('status', 20)->default('new')->index();
            $table->text('fix_summary')->nullable();
            $table->text('test_steps')->nullable();
            $table->unsignedInteger('pr_number')->nullable();
            $table->string('pr_url', 255)->nullable();
            $table->string('branch', 191)->nullable();
            $table->text('question')->nullable();        // routine asks reporter (needs_info)
            $table->text('answer')->nullable();          // reporter's reply
            $table->text('review_note')->nullable();     // Sarah/Jon send-back note
            $table->unsignedInteger('decided_by')->nullable();
            $table->string('decided_by_name', 191)->nullable();
            $table->timestamp('decided_at')->nullable();
            $table->timestamp('merged_at')->nullable();
            $table->timestamps();
        });
    }

    public function down()
    {
        Schema::dropIfExists('erp_bug_reports');
    }
}
