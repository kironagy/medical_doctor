<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('patient_notes', function (Blueprint $table) {
            $table->timestamp('client_updated_at')->nullable()->after('content');
            $table->softDeletes()->after('client_updated_at');
        });
    }

    public function down(): void
    {
        Schema::table('patient_notes', function (Blueprint $table) {
            $table->dropColumn(['client_updated_at', 'deleted_at']);
        });
    }
};
