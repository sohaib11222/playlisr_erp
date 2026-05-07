<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateLabelPrintLogsTable extends Migration
{
    public function up()
    {
        if (!Schema::hasTable('label_print_logs')) {
            Schema::create('label_print_logs', function (Blueprint $table) {
                $table->increments('id');
                $table->integer('business_id')->unsigned();
                $table->integer('user_id')->unsigned()->nullable();
                $table->unsignedInteger('qty')->default(0);
                $table->timestamp('created_at')->useCurrent();

                $table->index(['business_id', 'created_at']);
                $table->index(['business_id', 'user_id', 'created_at']);
            });
        }
    }

    public function down()
    {
        Schema::dropIfExists('label_print_logs');
    }
}
