<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('patient_files', function (Blueprint $table) {
            if (!Schema::hasColumn('patient_files', 'thumbnail_path')) {
                $table->string('thumbnail_path')->nullable();
            }
        });

        Schema::table('patients', function (Blueprint $table) {
            $table->index('name');
            $table->index('phone');
        });
    }

    public function down(): void
    {
        Schema::table('patient_files', function (Blueprint $table) {
            $table->dropColumn('thumbnail_path');
        });

        Schema::table('patients', function (Blueprint $table) {
            $table->dropIndex(['name']);
            $table->dropIndex(['phone']);
        });
    }
};
