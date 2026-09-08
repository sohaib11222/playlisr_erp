<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * "Replied" used to mean "resolution_notes is not null" — but Quo's
 * automated missed-call auto-text ("Sorry we missed your call - how
 * can we help?") lands in resolution_notes the same way a genuine
 * staff reply does, so inquiries nobody actually answered were
 * showing up as handled. has_real_reply is kept in sync by
 * Communication's saving() hook (see app/Communication.php) and is
 * backfilled here from existing data using the same auto-reply
 * detection logic.
 */
class AddHasRealReplyToCommunicationsTable extends Migration
{
    public function up()
    {
        if (Schema::hasTable('communications') && !Schema::hasColumn('communications', 'has_real_reply')) {
            Schema::table('communications', function (Blueprint $table) {
                $table->boolean('has_real_reply')->default(false)->after('resolution_notes');
            });
        }

        if (Schema::hasTable('communications')) {
            DB::table('communications')->whereNotNull('resolution_notes')->orderBy('id')->chunkById(100, function ($rows) {
                foreach ($rows as $row) {
                    $notes = preg_replace('/<!--.*?-->/', '', (string) $row->resolution_notes);
                    $entries = \App\Communication::parseReplyEntries($notes);
                    $hasReal = false;
                    foreach ($entries as $e) {
                        if (!\App\Communication::isAutoReplyText($e['text'])) {
                            $hasReal = true;
                            break;
                        }
                    }
                    DB::table('communications')->where('id', $row->id)->update(['has_real_reply' => $hasReal]);
                }
            });
        }
    }

    public function down()
    {
        if (Schema::hasTable('communications') && Schema::hasColumn('communications', 'has_real_reply')) {
            Schema::table('communications', function (Blueprint $table) {
                $table->dropColumn('has_real_reply');
            });
        }
    }
}
