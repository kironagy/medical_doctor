<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Creates the offline_files table for Phase 7.
     * This mirrors the sync_status pattern from Phase 5 (PatientRepository)
     * but applied to file uploads. Each row represents a file captured
     * while offline that needs to be uploaded when connectivity returns.
     */
    public function up(): void
    {
        Schema::create('offline_files', function (Blueprint $table) {
            $table->uuid('uuid')->primary();
            $table->uuid('patient_uuid')->index();
            $table->string('local_path');                          // relative to storage/app/uploads/pending/
            $table->string('original_name');
            $table->string('mime_type', 127)->nullable();
            $table->string('extension', 20)->nullable();
            $table->bigInteger('size')->default(0);
            $table->string('hash', 64)->nullable();                // SHA-256 of file content
            $table->string('sync_status', 20)->default('pending_upload')->index();
            $table->uuid('remote_uuid')->nullable();               // server PatientFile UUID after sync
            $table->text('error_message')->nullable();
            $table->integer('retry_count')->default(0);
            $table->timestamps();
            $table->timestamp('uploaded_at')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('offline_files');
    }
};
