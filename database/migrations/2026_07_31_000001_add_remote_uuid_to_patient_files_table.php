<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Add remote_uuid to patient_files.
     *
     * On the embedded SQLite (NativePHP App), every file uploaded via the
     * chunk upload system gets saved locally with a local UUID. When the
     * SyncEngine pushes the file to the production server, the server
     * assigns a different (remote) UUID. Without storing the remote UUID
     * locally, the SyncEngine cannot know which files have already been
     * synced to production and which still need to be uploaded.
     *
     * remote_uuid = null  → file exists locally only, needs to be pushed to production
     * remote_uuid = *     → file was accepted by production, sync complete
     */
    public function up(): void
    {
        Schema::table('patient_files', function (Blueprint $table) {
            // The UUID assigned by the production server after successful sync.
            // NULL means the file has not been synced to production yet.
            $table->uuid('remote_uuid')->nullable()->after('uuid')->index();
        });
    }

    public function down(): void
    {
        Schema::table('patient_files', function (Blueprint $table) {
            $table->dropColumn('remote_uuid');
        });
    }
};
