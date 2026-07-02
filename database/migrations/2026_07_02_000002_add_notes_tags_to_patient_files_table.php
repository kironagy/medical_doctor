<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('patient_files', function (Blueprint $table) {
            if (!Schema::hasColumn('patient_files', 'notes')) {
                $table->text('notes')->nullable()->after('desc');
            }
            if (!Schema::hasColumn('patient_files', 'tags')) {
                $table->text('tags')->nullable()->after('notes');
            }
        });
    }

    public function down(): void
    {
        Schema::table('patient_files', function (Blueprint $table) {
            $table->dropColumn(['notes', 'tags']);
        });
    }
};
