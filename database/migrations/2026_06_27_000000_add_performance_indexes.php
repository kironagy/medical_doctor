<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('patient_files', function (Blueprint $table) {
            $table->index('patient_id');
            $table->index('category');
            $table->index('type');
            $table->index('client_updated_at');
        });

        Schema::table('patient_visits', function (Blueprint $table) {
            $table->index('patient_id');
            $table->index('visit_date');
            $table->index('visit_time');
            $table->index('client_updated_at');
        });

        Schema::table('patients', function (Blueprint $table) {
            $table->index('client_updated_at');
        });

        Schema::table('file_categories', function (Blueprint $table) {
            $table->index('client_updated_at');
        });
    }

    public function down(): void
    {
        Schema::table('patient_files', function (Blueprint $table) {
            $table->dropIndex(['patient_id']);
            $table->dropIndex(['category']);
            $table->dropIndex(['type']);
            $table->dropIndex(['client_updated_at']);
        });

        Schema::table('patient_visits', function (Blueprint $table) {
            $table->dropIndex(['patient_id']);
            $table->dropIndex(['visit_date']);
            $table->dropIndex(['visit_time']);
            $table->dropIndex(['client_updated_at']);
        });

        Schema::table('patients', function (Blueprint $table) {
            $table->dropIndex(['client_updated_at']);
        });

        Schema::table('file_categories', function (Blueprint $table) {
            $table->dropIndex(['client_updated_at']);
        });
    }
};
