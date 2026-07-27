<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('patient_files', function (Blueprint $table) {
            // ═══ SYNC-008 FIX: Add sync_status for file sync support ═══════════
            // This allows the sync engine to track which files need to be
            // synced to the production server (pending_delete, pending_update).
            $table->string('sync_status', 20)->default('synced')->index()->after('upload_status');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('patient_files', function (Blueprint $table) {
            $table->dropColumn('sync_status');
        });
    }
};
