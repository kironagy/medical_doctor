<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sync_queue', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('table_name');
            $table->string('record_uuid')->nullable()->index();
            $table->string('operation', 20);
            $table->json('payload')->nullable();
            $table->unsignedInteger('retry_count')->default(0);
            $table->string('status', 30)->default('pending')->index();
            $table->text('last_error')->nullable();
            $table->timestamp('available_at')->nullable()->index();
            $table->timestamps();
        });

        Schema::create('sync_state', function (Blueprint $table): void {
            $table->string('key')->primary();
            $table->json('value')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sync_state');
        Schema::dropIfExists('sync_queue');
    }
};
