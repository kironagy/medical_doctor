<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sync_queue', function (Blueprint $table) {
            $table->uuid('record_uuid')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('sync_queue', function (Blueprint $table) {
            $table->uuid('record_uuid')->nullable(false)->change();
        });
    }
};
