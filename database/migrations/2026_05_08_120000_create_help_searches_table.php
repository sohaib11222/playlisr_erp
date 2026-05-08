<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateHelpSearchesTable extends Migration
{
    public function up()
    {
        if (Schema::hasTable('help_searches')) {
            return;
        }

        Schema::create('help_searches', function (Blueprint $table) {
            $table->bigIncrements('id');

            $table->unsignedInteger('business_id')->index();
            $table->unsignedInteger('user_id')->nullable()->index();

            // Query stored normalized (trimmed, lowercased) so aggregates
            // group "Discogs" and "discogs " together. The raw form isn't
            // worth keeping — the report shows the normalized term.
            $table->string('query', 191)->index();
            $table->unsignedInteger('result_count')->default(0);

            $table->timestamp('created_at')->nullable()->index();

            $table->index(['business_id', 'created_at']);
            $table->index(['business_id', 'query']);
        });
    }

    public function down()
    {
        Schema::dropIfExists('help_searches');
    }
}
