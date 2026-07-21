<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $tableName = 'patients';

        // Deduplicate codes before adding unique index
        $duplicates = DB::table($tableName)
            ->select('code', DB::raw('MIN(id) as keep_id'))
            ->whereNotNull('code')
            ->groupBy('code')
            ->having(DB::raw('COUNT(*)'), '>', 1)
            ->get();

        foreach ($duplicates as $dup) {
            DB::table($tableName)
                ->where('code', $dup->code)
                ->where('id', '!=', $dup->keep_id)
                ->update(['code' => null]);
        }

        Schema::table($tableName, function (Blueprint $table) {
            $table->unique('code', 'patients_code_unique');
        });
    }

    public function down(): void
    {
        Schema::table('patients', function (Blueprint $table) {
            $table->dropUnique('patients_code_unique');
        });
    }
};
