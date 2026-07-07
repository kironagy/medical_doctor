<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sync_queue', function (Blueprint $table) {
            if (!Schema::hasColumn('sync_queue', 'priority')) {
                $table->unsignedInteger('priority')->default(5)->after('operation');
            }
        });
    }

    public function down(): void
    {
        Schema::table('sync_queue', function (Blueprint $table) {
            if (Schema::hasColumn('sync_queue', 'priority')) {
                $table->dropColumn('priority');
            }
        });
    }
};
