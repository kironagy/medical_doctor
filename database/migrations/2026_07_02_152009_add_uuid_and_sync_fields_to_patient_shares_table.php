<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('patient_shares', function (Blueprint $table) {
            $table->uuid('uuid')->nullable()->unique()->after('id');
            $table->timestamp('client_updated_at')->nullable()->after('expires_at');
            $table->softDeletes()->after('client_updated_at');
        });
    }

    public function down(): void
    {
        Schema::table('patient_shares', function (Blueprint $table) {
            $table->dropColumn(['uuid', 'client_updated_at', 'deleted_at']);
        });
    }
};
