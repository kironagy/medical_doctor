<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    private array $tables = ['patients', 'patient_files', 'patient_visits', 'file_categories'];

    public function up(): void
    {
        foreach ($this->tables as $tableName) {
            Schema::table($tableName, function (Blueprint $table) use ($tableName): void {
                if (! Schema::hasColumn($tableName, 'uuid')) {
                    $table->uuid('uuid')->nullable()->unique()->after('id');
                }
                if (! Schema::hasColumn($tableName, 'client_updated_at')) {
                    $table->timestamp('client_updated_at')->nullable()->after('updated_at');
                }
                if (! Schema::hasColumn($tableName, 'deleted_at')) {
                    $table->softDeletes();
                }
            });

            DB::table($tableName)
                ->whereNull('uuid')
                ->orderBy('id')
                ->chunkById(100, function ($rows) use ($tableName): void {
                    foreach ($rows as $row) {
                        DB::table($tableName)->where('id', $row->id)->update([
                            'uuid' => (string) Str::uuid(),
                            'client_updated_at' => $row->updated_at ?? now(),
                        ]);
                    }
                });
        }
    }

    public function down(): void
    {
        foreach (array_reverse($this->tables) as $tableName) {
            Schema::table($tableName, function (Blueprint $table) use ($tableName): void {
                if (Schema::hasColumn($tableName, 'deleted_at')) {
                    $table->dropSoftDeletes();
                }
                if (Schema::hasColumn($tableName, 'client_updated_at')) {
                    $table->dropColumn('client_updated_at');
                }
                if (Schema::hasColumn($tableName, 'uuid')) {
                    $table->dropColumn('uuid');
                }
            });
        }
    }
};
