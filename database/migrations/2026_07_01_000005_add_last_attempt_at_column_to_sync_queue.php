<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sync_queue', function (Blueprint $table) {
            if (!Schema::hasColumn('sync_queue', 'last_attempt_at')) {
                $table->timestamp('last_attempt_at')->nullable()->after('available_at');
            }
        });
    }

    public function down(): void
    {
        Schema::table('sync_queue', function (Blueprint $table) {
            if (Schema::hasColumn('sync_queue', 'last_attempt_at')) {
                $table->dropColumn('last_attempt_at');
            }
        });
    }
};
