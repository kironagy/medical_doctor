<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Add offline sync columns to patient_visits table.
 *
 * These columns are required for the offline-first architecture:
 *   - sync_status: tracks whether the visit needs to be synced to the server
 *   - remote_uuid: the UUID assigned by the production server after sync
 *   - client_updated_at: timestamp of the last local modification (for conflict resolution)
 *
 * Without sync_status, the SyncEngineService cannot find visits that were
 * created/updated/deleted while offline, meaning those changes are NEVER
 * uploaded to the production server.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('patient_visits', function (Blueprint $table) {
            // sync_status tracks the local sync state:
            //   'synced'         → already on the server
            //   'pending_create' → created offline, not yet uploaded
            //   'pending_update' → edited offline, not yet uploaded
            //   'pending_delete' → deleted offline, not yet removed from server
            //   'syncing'        → currently being synced (atomic claim)
            //   'failed'         → sync attempted but failed
            if (!Schema::hasColumn('patient_visits', 'sync_status')) {
                $table->string('sync_status', 50)->default('synced')->after('cost');
            }

            // remote_uuid: the UUID on the production server.
            // May differ from local UUID if server assigns a new one.
            // Used by SyncEngine to know where to PUT/DELETE on the server.
            if (!Schema::hasColumn('patient_visits', 'remote_uuid')) {
                $table->string('remote_uuid', 36)->nullable()->after('sync_status');
            }

            // client_updated_at: when the client last modified this visit.
            // Used for conflict detection during bidirectional sync.
            if (!Schema::hasColumn('patient_visits', 'client_updated_at')) {
                $table->timestamp('client_updated_at')->nullable()->after('remote_uuid');
            }
        });

        // Index for fast lookup of pending visits during sync
        try {
            Schema::table('patient_visits', function (Blueprint $table) {
                $table->index('sync_status', 'patient_visits_sync_status_idx');
            });
        } catch (\Throwable $e) {
            // Index may already exist — not fatal
        }
    }

    public function down(): void
    {
        Schema::table('patient_visits', function (Blueprint $table) {
            try { $table->dropIndex('patient_visits_sync_status_idx'); } catch (\Throwable $e) {}
            $table->dropColumnIfExists('sync_status');
            $table->dropColumnIfExists('remote_uuid');
            $table->dropColumnIfExists('client_updated_at');
        });
    }
};
