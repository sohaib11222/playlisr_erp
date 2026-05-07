<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateHelpSystemTables extends Migration
{
    public function up()
    {
        if (!Schema::hasTable('help_articles')) {
            Schema::create('help_articles', function (Blueprint $table) {
                $table->increments('id');
                $table->string('slug')->unique();
                $table->string('title');
                $table->string('section')->nullable();
                $table->text('summary')->nullable();
                $table->longText('body_html');
                $table->json('page_keys')->nullable();
                $table->unsignedInteger('sort_order')->default(0);
                $table->boolean('is_published')->default(true);
                $table->timestamps();

                $table->index('section');
                $table->index('is_published');
            });
        }

        if (!Schema::hasTable('help_search_log')) {
            Schema::create('help_search_log', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->integer('business_id')->unsigned()->nullable();
                $table->integer('user_id')->unsigned()->nullable();
                $table->string('query', 255);
                $table->unsignedInteger('result_count')->default(0);
                $table->string('clicked_slug')->nullable();
                $table->string('page_key')->nullable();
                $table->timestamp('created_at')->useCurrent();

                $table->index(['business_id', 'created_at']);
                $table->index('query');
                $table->index('result_count');
            });
        }
    }

    public function down()
    {
        Schema::dropIfExists('help_search_log');
        Schema::dropIfExists('help_articles');
    }
}
