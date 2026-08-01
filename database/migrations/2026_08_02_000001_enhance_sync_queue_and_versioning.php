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
        // ── Ensure sync_queue table has exact schema required ────────────
        if (!Schema::hasTable('sync_queue')) {
            Schema::create('sync_queue', function (Blueprint $table) {
                $table->id();
                $table->uuid('uuid')->unique();
                $table->string('entity_type')->index();
                $table->uuid('entity_uuid')->index();
                $table->string('operation'); // create, update, delete
                $table->integer('payload_version')->default(1);
                $table->longText('payload')->nullable();
                $table->string('status')->default('pending')->index(); // pending, processing, synced, failed, conflict
                $table->integer('retry_count')->default(0);
                $table->text('last_error')->nullable();
                $table->timestamp('last_attempt_at')->nullable();
                $table->timestamps();
            });
        } else {
            Schema::table('sync_queue', function (Blueprint $table) {
                if (!Schema::hasColumn('sync_queue', 'entity_type')) {
                    $table->string('entity_type')->nullable()->index();
                }
                if (!Schema::hasColumn('sync_queue', 'entity_uuid')) {
                    $table->uuid('entity_uuid')->nullable()->index();
                }
                if (!Schema::hasColumn('sync_queue', 'payload_version')) {
                    $table->integer('payload_version')->default(1);
                }
            });
        }

        // ── Add versioning and sync columns to entity tables ─────────────
        $tables = ['patients', 'patient_notes', 'patient_files', 'patient_visits'];

        foreach ($tables as $tableName) {
            if (Schema::hasTable($tableName)) {
                Schema::table($tableName, function (Blueprint $table) use ($tableName) {
                    if (!Schema::hasColumn($tableName, 'version')) {
                        $table->integer('version')->default(1);
                    }
                    if (!Schema::hasColumn($tableName, 'server_updated_at')) {
                        $table->timestamp('server_updated_at')->nullable();
                    }
                    if (!Schema::hasColumn($tableName, 'client_updated_at')) {
                        $table->timestamp('client_updated_at')->nullable();
                    }
                });
            }
        }

        // ── Add sha256 hash column for media file deduplication ──────────
        foreach (['patient_files', 'offline_files'] as $fileTable) {
            if (Schema::hasTable($fileTable)) {
                Schema::table($fileTable, function (Blueprint $table) use ($fileTable) {
                    if (!Schema::hasColumn($fileTable, 'sha256')) {
                        $table->string('sha256', 64)->nullable()->index();
                    }
                });
            }
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        $tables = ['patients', 'patient_notes', 'patient_files', 'patient_visits'];

        foreach ($tables as $tableName) {
            if (Schema::hasTable($tableName)) {
                Schema::table($tableName, function (Blueprint $table) use ($tableName) {
                    $table->dropColumn(['version', 'server_updated_at', 'client_updated_at']);
                });
            }
        }

        Schema::dropIfExists('sync_queue');
    }
};
