<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class CreateQuickbooksIntegrationTables extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        if (!Schema::hasTable('quickbooks_connections')) {
            Schema::create('quickbooks_connections', function (Blueprint $table) {
                $table->increments('id');
                $table->integer('business_id')->unsigned();
                $table->string('realm_id')->nullable();
                $table->text('access_token')->nullable();
                $table->text('refresh_token')->nullable();
                $table->dateTime('token_expires_at')->nullable();
                $table->dateTime('refresh_expires_at')->nullable();
                $table->string('environment', 30)->default('production');
                $table->boolean('is_active')->default(0);
                $table->timestamps();

                $table->index('business_id');
                $table->index('realm_id');
                $table->index('is_active');
                $table->unique('business_id', 'quickbooks_connections_business_unique');
            });
        }

        if (!Schema::hasTable('quickbooks_entity_maps')) {
            Schema::create('quickbooks_entity_maps', function (Blueprint $table) {
                $table->increments('id');
                $table->integer('business_id')->unsigned();
                $table->string('entity_type', 50);
                $table->integer('erp_id')->unsigned();
                $table->string('qbo_id');
                $table->string('qbo_sync_token')->nullable();
                $table->dateTime('last_synced_at')->nullable();
                $table->timestamps();

                $table->index('business_id');
                $table->index('entity_type');
                $table->index('erp_id');
                $table->index('qbo_id');
                $table->unique(['business_id', 'entity_type', 'erp_id'], 'quickbooks_entity_maps_unique_map');
            });
        }

        if (!Schema::hasTable('quickbooks_sync_logs')) {
            Schema::create('quickbooks_sync_logs', function (Blueprint $table) {
                $table->increments('id');
                $table->integer('business_id')->unsigned();
                $table->string('erp_entity_type', 50)->nullable();
                $table->integer('erp_entity_id')->unsigned()->nullable();
                $table->string('direction', 20)->nullable();
                $table->string('operation', 50)->nullable();
                $table->string('status', 30)->nullable();
                $table->longText('request_payload')->nullable();
                $table->longText('response_payload')->nullable();
                $table->text('error_message')->nullable();
                $table->string('idempotency_key')->nullable();
                $table->integer('attempts')->default(0);
                $table->dateTime('processed_at')->nullable();
                $table->timestamps();

                $table->index('business_id');
                $table->index('status');
                $table->index('processed_at');
                $table->index('idempotency_key');
            });
        }

        if (!Schema::hasTable('quickbooks_account_mappings')) {
            Schema::create('quickbooks_account_mappings', function (Blueprint $table) {
                $table->increments('id');
                $table->integer('business_id')->unsigned();
                $table->string('payment_method', 60);
                $table->string('qbo_account_id')->nullable();
                $table->string('qbo_account_name')->nullable();
                $table->timestamps();

                $table->index('business_id');
                $table->index('payment_method');
                $table->unique(['business_id', 'payment_method'], 'quickbooks_account_mappings_business_payment_unique');
            });
        }
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('quickbooks_account_mappings');
        Schema::dropIfExists('quickbooks_sync_logs');
        Schema::dropIfExists('quickbooks_entity_maps');
        Schema::dropIfExists('quickbooks_connections');
    }
}

