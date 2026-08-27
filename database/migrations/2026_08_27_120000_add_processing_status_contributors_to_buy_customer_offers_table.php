<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Every employee who has ever touched a collection's processing status,
// so the Storage Locations page can list everyone who worked on it once
// it's Complete — see BuyFromCustomerController@updateProcessingStatus.
class AddProcessingStatusContributorsToBuyCustomerOffersTable extends Migration
{
    public function up()
    {
        Schema::table('buy_customer_offers', function (Blueprint $table) {
            if (!Schema::hasColumn('buy_customer_offers', 'processing_status_contributors')) {
                $table->text('processing_status_contributors')->nullable()->after('processing_status_updated_at');
            }
        });
    }

    public function down()
    {
        Schema::table('buy_customer_offers', function (Blueprint $table) {
            if (Schema::hasColumn('buy_customer_offers', 'processing_status_contributors')) {
                $table->dropColumn('processing_status_contributors');
            }
        });
    }
}
