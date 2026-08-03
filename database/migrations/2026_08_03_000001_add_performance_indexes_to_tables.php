<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 1. Patients indexes
        Schema::table('patients', function (Blueprint $table) {
            try {
                $table->index(['sync_status', 'created_at'], 'patients_sync_status_created_idx');
            } catch (\Throwable $e) {}
            try {
                $table->index('uuid', 'patients_uuid_idx');
            } catch (\Throwable $e) {}
        });

        // 2. Patient Files indexes
        Schema::table('patient_files', function (Blueprint $table) {
            try {
                $table->index(['patient_id', 'category'], 'patient_files_patient_category_idx');
            } catch (\Throwable $e) {}
            try {
                $table->index(['uuid', 'sync_status'], 'patient_files_uuid_sync_idx');
            } catch (\Throwable $e) {}
            try {
                $table->index('remote_uuid', 'patient_files_remote_uuid_idx');
            } catch (\Throwable $e) {}
        });

        // 3. Patient Notes indexes
        Schema::table('patient_notes', function (Blueprint $table) {
            try {
                $table->index(['patient_id', 'category'], 'patient_notes_patient_category_idx');
            } catch (\Throwable $e) {}
            try {
                $table->index(['uuid', 'sync_status'], 'patient_notes_uuid_sync_idx');
            } catch (\Throwable $e) {}
        });

        // 4. Patient Visits indexes
        Schema::table('patient_visits', function (Blueprint $table) {
            try {
                $table->index(['patient_id', 'visit_date'], 'patient_visits_patient_date_idx');
            } catch (\Throwable $e) {}
        });

        // 5. Offline Files indexes
        Schema::table('offline_files', function (Blueprint $table) {
            try {
                $table->index(['patient_uuid', 'sync_status'], 'offline_files_patient_sync_idx');
            } catch (\Throwable $e) {}
        });
    }

    public function down(): void
    {
        Schema::table('patients', function (Blueprint $table) {
            try { $table->dropIndex('patients_sync_status_created_idx'); } catch (\Throwable $e) {}
            try { $table->dropIndex('patients_uuid_idx'); } catch (\Throwable $e) {}
        });
        Schema::table('patient_files', function (Blueprint $table) {
            try { $table->dropIndex('patient_files_patient_category_idx'); } catch (\Throwable $e) {}
            try { $table->dropIndex('patient_files_uuid_sync_idx'); } catch (\Throwable $e) {}
            try { $table->dropIndex('patient_files_remote_uuid_idx'); } catch (\Throwable $e) {}
        });
        Schema::table('patient_notes', function (Blueprint $table) {
            try { $table->dropIndex('patient_notes_patient_category_idx'); } catch (\Throwable $e) {}
            try { $table->dropIndex('patient_notes_uuid_sync_idx'); } catch (\Throwable $e) {}
        });
        Schema::table('patient_visits', function (Blueprint $table) {
            try { $table->dropIndex('patient_visits_patient_date_idx'); } catch (\Throwable $e) {}
        });
        Schema::table('offline_files', function (Blueprint $table) {
            try { $table->dropIndex('offline_files_patient_sync_idx'); } catch (\Throwable $e) {}
        });
    }
};
