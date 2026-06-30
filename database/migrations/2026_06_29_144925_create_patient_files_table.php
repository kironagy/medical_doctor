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
        Schema::create('patient_files', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('patient_id')->constrained()->cascadeOnDelete();
            $table->foreignId('uploaded_by_id')->constrained('users')->cascadeOnDelete();
            $table->string('title')->nullable();
            $table->text('desc')->nullable();
            $table->string('type'); // video, image, document
            $table->string('category')->nullable();
            $table->date('date')->nullable();
            $table->string('file_name');
            $table->string('file_path');
            $table->string('mime_type');
            $table->bigInteger('size');
            $table->string('thumbnail_path')->nullable();
            $table->string('upload_status')->default('ready'); // Simplified: ready or failed
            $table->timestamp('client_updated_at')->nullable();
            $table->softDeletes();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('patient_files');
    }
};
