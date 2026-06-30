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
        // Drop columns only if they exist (may have been added by previous HLS migration)
        $dropColumns = [];
        foreach (['hls_path', 'duration', 'width', 'height', 'video_metadata', 'processing_times'] as $col) {
            if (Schema::hasColumn('patient_files', $col)) {
                $dropColumns[] = $col;
            }
        }
        if (!empty($dropColumns)) {
            Schema::table('patient_files', function (Blueprint $table) use ($dropColumns) {
                $table->dropColumn($dropColumns);
            });
        }
        
        // Add required columns for simplified system (only if missing)
        Schema::table('patient_files', function (Blueprint $table) {
            if (!Schema::hasColumn('patient_files', 'mime_type')) {
                $table->string('mime_type')->after('file_path');
            }
            
            if (!Schema::hasColumn('patient_files', 'size')) {
                $table->bigInteger('size')->after('mime_type');
            }
        });
        
        Schema::table('patient_files', function (Blueprint $table) {
            // Make required fields non-nullable
            $table->uuid('uuid')->nullable(false)->change();
            $table->string('type')->nullable(false)->change();
            $table->string('file_name')->nullable(false)->change();
            $table->string('file_path')->nullable(false)->change();
            
            // Simplify upload_status - only 'ready' or 'failed' needed
            $table->string('upload_status')->default('ready')->change();
        });
        
        // Update existing records to have 'ready' status if they're in old states
        DB::table('patient_files')
            ->whereIn('upload_status', ['pending', 'queued', 'processing', 'completed'])
            ->update(['upload_status' => 'ready']);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('patient_files', function (Blueprint $table) {
            // Add back the removed columns (though data will be lost)
            $table->string('hls_path')->nullable()->after('thumbnail_path');
            $table->integer('duration')->nullable()->after('hls_path');
            $table->integer('width')->nullable()->after('duration');
            $table->integer('height')->nullable()->after('width');
            $table->json('video_metadata')->nullable()->after('height');
            $table->json('processing_times')->nullable()->after('video_metadata');
            
            // Revert upload status default
            $table->string('upload_status')->default('pending')->change();
            
            // Make fields nullable again
            $table->uuid('uuid')->nullable()->change();
            $table->string('type')->nullable()->change();
            $table->string('file_name')->nullable()->change();
            $table->string('file_path')->nullable()->change();
        });
    }
};