<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class AddBuyCustomerOfferTransactionDetails extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('buy_customer_offers', function (Blueprint $table) {
            $table->string('seller_first_name', 120)->nullable()->after('seller_name');
            $table->string('seller_last_name', 120)->nullable()->after('seller_first_name');
            $table->string('seller_email', 191)->nullable()->after('seller_phone');
            $table->string('seller_id_type', 60)->nullable()->after('seller_email');
            $table->string('seller_id_last_four', 4)->nullable()->after('seller_id_type');
            $table->longText('seller_signature_data')->nullable()->after('notes');
            $table->longText('collection_summary_json')->nullable()->after('seller_signature_data');
            $table->text('price_override_reason')->nullable()->after('collection_summary_json');
            $table->boolean('compliance_items_owned')->default(false)->after('price_override_reason');
            $table->boolean('compliance_sales_final')->default(false)->after('compliance_items_owned');
            $table->string('payment_method', 40)->nullable()->after('payout_type');
            $table->timestamp('accepted_at')->nullable()->after('accepted_purchase_id');
        });

        $driver = Schema::getConnection()->getDriverName();
        if ($driver === 'mysql') {
            DB::statement("ALTER TABLE buy_customer_offers MODIFY payout_type VARCHAR(40) NOT NULL DEFAULT 'cash'");
        }

        foreach (DB::table('buy_customer_offers')->whereNull('payment_method')->get(['id', 'payout_type']) as $row) {
            $pm = ($row->payout_type === 'store_credit') ? 'store_credit' : 'cash_in_store';
            DB::table('buy_customer_offers')->where('id', $row->id)->update(['payment_method' => $pm]);
        }
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('buy_customer_offers', function (Blueprint $table) {
            $table->dropColumn([
                'seller_first_name',
                'seller_last_name',
                'seller_email',
                'seller_id_type',
                'seller_id_last_four',
                'seller_signature_data',
                'collection_summary_json',
                'price_override_reason',
                'compliance_items_owned',
                'compliance_sales_final',
                'payment_method',
                'accepted_at',
            ]);
        });

        $driver = Schema::getConnection()->getDriverName();
        if ($driver === 'mysql') {
            DB::statement("ALTER TABLE buy_customer_offers MODIFY payout_type ENUM('cash','store_credit') NOT NULL DEFAULT 'cash'");
        }
    }
}
