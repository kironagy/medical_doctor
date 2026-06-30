<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('upload_sessions', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('patient_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('original_name');
            $table->string('mime_type');
            $table->string('extension', 20);
            $table->unsignedBigInteger('total_size');
            $table->unsignedInteger('total_chunks');
            $table->unsignedInteger('chunk_size');
            $table->string('status', 20)->default('pending');
            $table->string('checksum_algorithm', 20)->default('sha256');
            $table->string('final_checksum', 64)->nullable();
            $table->string('disk', 20)->default('local');
            $table->json('metadata')->nullable();
            $table->timestamp('expires_at');
            $table->timestamps();

            $table->index(['user_id', 'status']);
            $table->index('expires_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('upload_sessions');
    }
};
