<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('upload_chunk_receipts', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('session_id');
            $table->unsignedInteger('chunk_index');
            $table->timestamp('received_at')->default(DB::raw('CURRENT_TIMESTAMP'));
            $table->unique(['session_id', 'chunk_index']);
            $table->index(['session_id', 'received_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('upload_chunk_receipts');
    }
};
