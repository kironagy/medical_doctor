<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Adds offline sync support columns to patient_notes, mirroring the
     * same pattern used by patients and offline_files tables.
     *
     * sync_status values:
     *   - 'synced' (default): note exists on the server
     *   - 'pending_create': note created offline, needs upload
     *   - 'pending_delete': note deleted offline, needs sync
     */
    public function up(): void
    {
        Schema::table('patient_notes', function (Blueprint $table) {
            $table->string('sync_status', 20)->default('synced')->index()->after('content');
            $table->uuid('remote_uuid')->nullable()->after('sync_status');
            $table->text('error_message')->nullable()->after('remote_uuid');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('patient_notes', function (Blueprint $table) {
            $table->dropColumn(['sync_status', 'remote_uuid', 'error_message']);
        });
    }
};
