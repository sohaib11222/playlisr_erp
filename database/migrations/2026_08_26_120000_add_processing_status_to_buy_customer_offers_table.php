<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Tracks where a purchased collection is in being sorted/priced/shelved,
// and who last touched that status — see
// BuyFromCustomerController@updateProcessingStatus.
class AddProcessingStatusToBuyCustomerOffersTable extends Migration
{
    public function up()
    {
        Schema::table('buy_customer_offers', function (Blueprint $table) {
            if (!Schema::hasColumn('buy_customer_offers', 'processing_status')) {
                $table->string('processing_status', 20)->default('not_started')->after('storage_location_updated_at');
            }
            if (!Schema::hasColumn('buy_customer_offers', 'processing_status_updated_by')) {
                $table->unsignedInteger('processing_status_updated_by')->nullable()->after('processing_status');
            }
            if (!Schema::hasColumn('buy_customer_offers', 'processing_status_updated_at')) {
                $table->timestamp('processing_status_updated_at')->nullable()->after('processing_status_updated_by');
            }
        });
    }

    public function down()
    {
        Schema::table('buy_customer_offers', function (Blueprint $table) {
            if (Schema::hasColumn('buy_customer_offers', 'processing_status')) {
                $table->dropColumn('processing_status');
            }
            if (Schema::hasColumn('buy_customer_offers', 'processing_status_updated_by')) {
                $table->dropColumn('processing_status_updated_by');
            }
            if (Schema::hasColumn('buy_customer_offers', 'processing_status_updated_at')) {
                $table->dropColumn('processing_status_updated_at');
            }
        });
    }
}
