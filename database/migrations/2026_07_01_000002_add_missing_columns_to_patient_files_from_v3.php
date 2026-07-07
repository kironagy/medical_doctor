<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('patient_files', function (Blueprint $table) {
            if (!Schema::hasColumn('patient_files', 'uploaded_by_id')) {
                $table->unsignedBigInteger('uploaded_by_id')->nullable()->after('patient_id');
            }
            if (!Schema::hasColumn('patient_files', 'mime_type')) {
                $table->string('mime_type')->nullable()->after('file_path');
            }
            if (!Schema::hasColumn('patient_files', 'size')) {
                $table->bigInteger('size')->nullable()->after('mime_type');
            }
            // v3 uses these; keep if missing
            if (!Schema::hasColumn('patient_files', 'upload_status')) {
                $table->string('upload_status')->default('ready')->after('thumbnail_path');
            }
            if (!Schema::hasColumn('patient_files', 'client_updated_at')) {
                $table->timestamp('client_updated_at')->nullable()->after('updated_at');
            }
        });
    }

    public function down(): void
    {
        Schema::table('patient_files', function (Blueprint $table) {
            if (Schema::hasColumn('patient_files', 'client_updated_at')) {
                $table->dropColumn('client_updated_at');
            }
            if (Schema::hasColumn('patient_files', 'upload_status')) {
                $table->dropColumn('upload_status');
            }
            if (Schema::hasColumn('patient_files', 'size')) {
                $table->dropColumn('size');
            }
            if (Schema::hasColumn('patient_files', 'mime_type')) {
                $table->dropColumn('mime_type');
            }
            if (Schema::hasColumn('patient_files', 'uploaded_by_id')) {
                $table->dropColumn('uploaded_by_id');
            }
        });
    }
};
