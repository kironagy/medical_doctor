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
        Schema::table('patient_files', function (Blueprint $table) {
            $table->double('duration')->nullable();
            $table->string('resolution')->nullable();
            $table->integer('processing_progress')->default(0);
            $table->string('processing_stage')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('patient_files', function (Blueprint $table) {
            $table->dropColumn(['duration', 'resolution', 'processing_progress', 'processing_stage']);
        });
    }
};
