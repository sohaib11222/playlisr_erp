<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Where the physical box a purchased collection came in is being kept until
// it's sorted/priced. Free text (not a foreign key to any location table) so
// any employee can jot down "shelf B3" or "back room, top of stack" without
// needing a predefined list — see BuyFromCustomerController@storageLocations.
class AddStorageLocationToBuyCustomerOffersTable extends Migration
{
    public function up()
    {
        Schema::table('buy_customer_offers', function (Blueprint $table) {
            if (!Schema::hasColumn('buy_customer_offers', 'storage_location')) {
                $table->string('storage_location', 255)->nullable()->after('notes');
            }
            if (!Schema::hasColumn('buy_customer_offers', 'storage_location_updated_by')) {
                $table->unsignedInteger('storage_location_updated_by')->nullable()->after('storage_location');
            }
            if (!Schema::hasColumn('buy_customer_offers', 'storage_location_updated_at')) {
                $table->timestamp('storage_location_updated_at')->nullable()->after('storage_location_updated_by');
            }
        });
    }

    public function down()
    {
        Schema::table('buy_customer_offers', function (Blueprint $table) {
            if (Schema::hasColumn('buy_customer_offers', 'storage_location')) {
                $table->dropColumn('storage_location');
            }
            if (Schema::hasColumn('buy_customer_offers', 'storage_location_updated_by')) {
                $table->dropColumn('storage_location_updated_by');
            }
            if (Schema::hasColumn('buy_customer_offers', 'storage_location_updated_at')) {
                $table->dropColumn('storage_location_updated_at');
            }
        });
    }
}
