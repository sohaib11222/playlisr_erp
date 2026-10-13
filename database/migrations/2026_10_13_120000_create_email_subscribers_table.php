<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Email Lists (Sarah 10/13): every Brevo contact + which lists they were on,
// kept in the ERP so Brevo itself can be trimmed to a cheaper plan.
class CreateEmailSubscribersTable extends Migration
{
    public function up()
    {
        if (Schema::hasTable('email_subscribers')) return;
        Schema::create('email_subscribers', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('business_id')->index();
            $table->string('email', 191);
            $table->string('first_name', 120)->nullable();
            $table->string('last_name', 120)->nullable();
            $table->string('phone', 60)->nullable();
            $table->text('lists')->nullable(); // list names, "|" separated
            $table->boolean('unsubscribed')->default(false);
            $table->string('source', 40)->default('brevo');
            $table->unsignedBigInteger('brevo_id')->nullable();
            $table->timestamp('brevo_created_at')->nullable();
            $table->timestamps();
            $table->unique(['business_id', 'email']);
        });
    }

    public function down()
    {
        Schema::dropIfExists('email_subscribers');
    }
}
