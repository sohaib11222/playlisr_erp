<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class CreateUserReportFavoritesTable extends Migration
{
    public function up()
    {
        if (Schema::hasTable('user_report_favorites')) {
            return;
        }
        Schema::create('user_report_favorites', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedInteger('user_id');
            $table->unsignedInteger('business_id');
            $table->string('report_key', 80);
            $table->integer('sort_order')->default(0);
            $table->timestamps();

            $table->unique(['user_id', 'report_key']);
            $table->index(['user_id', 'business_id']);
        });
    }

    public function down()
    {
        Schema::dropIfExists('user_report_favorites');
    }
}
