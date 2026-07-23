<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('file_cache', function (Blueprint $table) {
            $table->string('file_uuid')->primary();
            $table->string('patient_uuid')->index();
            $table->string('file_name');
            $table->string('mime_type');
            $table->bigInteger('size');
            $table->string('local_path');
            $table->string('checksum')->nullable();
            $table->timestamp('cached_at')->nullable();
            $table->timestamp('last_accessed_at')->nullable()->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('file_cache');
    }
};
