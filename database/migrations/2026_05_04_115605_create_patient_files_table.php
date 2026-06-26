<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('patient_files', function (Blueprint $table) {
            $table->id();
            $table->foreignId('patient_id')->constrained('patients')->onDelete('cascade');
            $table->string('title');
            $table->text('desc')->nullable();
            $table->string('type'); // image, pdf, video
            $table->date('date');
            $table->string('file_name');
            $table->string('file_path')->nullable(); // the actual path to the file in storage
            $table->longText('data')->nullable(); // For keeping the original Base64 behavior if files are tiny, but we should use file_path
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('patient_files');
    }
};
