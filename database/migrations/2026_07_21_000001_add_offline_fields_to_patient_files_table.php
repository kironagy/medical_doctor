<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('patient_files', function (Blueprint $table) {
            if (!Schema::hasColumn('patient_files', 'remote_url')) {
                $table->text('remote_url')->nullable()->after('file_path');
            }
            if (!Schema::hasColumn('patient_files', 'is_cached_locally')) {
                $table->boolean('is_cached_locally')->default(false)->after('remote_url');
            }
            if (!Schema::hasColumn('patient_files', 'downloaded_at')) {
                $table->timestamp('downloaded_at')->nullable()->after('is_cached_locally');
            }
        });
    }

    public function down(): void
    {
        Schema::table('patient_files', function (Blueprint $table) {
            $columns = ['remote_url', 'is_cached_locally', 'downloaded_at'];
            foreach ($columns as $col) {
                if (Schema::hasColumn('patient_files', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
