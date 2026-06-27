<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sync_queue', function (Blueprint $table): void {
            // Human-readable entity name (e.g. 'Patient', 'Visit', 'File')
            if (!Schema::hasColumn('sync_queue', 'entity')) {
                $table->string('entity', 50)->nullable()->after('table_name');
            }

            // Priority: 1=highest (files/large data), 5=normal, 10=lowest
            if (!Schema::hasColumn('sync_queue', 'priority')) {
                $table->unsignedTinyInteger('priority')->default(5)->after('entity');
            }

            // Timestamp of the most recent processing attempt
            if (!Schema::hasColumn('sync_queue', 'last_attempt_at')) {
                $table->timestamp('last_attempt_at')->nullable()->after('available_at');
            }

            // Composite index for efficient queue polling ordered by priority
            $table->index(['status', 'available_at', 'priority'], 'sync_queue_polling_index');
        });
    }

    public function down(): void
    {
        Schema::table('sync_queue', function (Blueprint $table): void {
            $table->dropIndex('sync_queue_polling_index');

            if (Schema::hasColumn('sync_queue', 'last_attempt_at')) {
                $table->dropColumn('last_attempt_at');
            }
            if (Schema::hasColumn('sync_queue', 'priority')) {
                $table->dropColumn('priority');
            }
            if (Schema::hasColumn('sync_queue', 'entity')) {
                $table->dropColumn('entity');
            }
        });
    }
};
