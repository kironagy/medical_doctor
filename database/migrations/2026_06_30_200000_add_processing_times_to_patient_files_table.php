<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adds a JSON column that stores per-step timing (ms) for every file,
 * enabling the upload pipeline performance report.
 *
 * Example value:
 * {
 *   "merge_ms": 3240,
 *   "file_size_bytes": 1073741824,
 *   "post_merge_action": "dispatched_optimize",
 *   "mark_ready_ms": 0.8,
 *   "thumbnail_ms": 1420
 * }
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('patient_files', function (Blueprint $table) {
            $table->json('processing_times')->nullable()->after('video_metadata');
        });
    }

    public function down(): void
    {
        Schema::table('patient_files', function (Blueprint $table) {
            $table->dropColumn('processing_times');
        });
    }
};
