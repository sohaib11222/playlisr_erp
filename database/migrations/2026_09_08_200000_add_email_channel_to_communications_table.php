<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class AddEmailChannelToCommunicationsTable extends Migration
{
    public function up()
    {
        if (Schema::hasTable('communications')) {
            DB::statement("ALTER TABLE communications MODIFY COLUMN channel ENUM('phone_1', 'phone_2', 'instagram', 'whatsapp', 'facebook', 'tiktok', 'email', 'other') NOT NULL DEFAULT 'other'");
        }
    }

    public function down()
    {
        if (Schema::hasTable('communications')) {
            DB::statement("ALTER TABLE communications MODIFY COLUMN channel ENUM('phone_1', 'phone_2', 'instagram', 'whatsapp', 'facebook', 'tiktok', 'other') NOT NULL DEFAULT 'other'");
        }
    }
}
