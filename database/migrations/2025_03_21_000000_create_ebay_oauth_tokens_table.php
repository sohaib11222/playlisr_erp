<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class CreateEbayOauthTokensTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('ebay_oauth_tokens', function (Blueprint $table) {
            $table->increments('id');
            $table->text('access_token');
            $table->string('token_type');
            $table->integer('expires_in');
            $table->timestamp('expires_at');
            $table->timestamps();

            // Add indexes for better query performance
            $table->index('expires_at');
            $table->index('token_type');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('ebay_oauth_tokens');
    }
} 