<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * DownloadSyncService used to write remote ISO-8601 timestamps
 * ("2026-08-04T16:02:46.000000Z") straight into created_at/updated_at via raw
 * DB::table() writes, while locally-created rows use Eloquent's default
 * "Y-m-d H:i:s" format. SQLite stores both as TEXT, so "ORDER BY created_at
 * DESC" compared them lexicographically — ' ' < 'T' meant every synced-format
 * row outranked local uploads regardless of actual date, making fresh
 * uploads vanish from "newest first" listings. This backfills existing rows
 * to the consistent format; the write path itself is fixed separately.
 */
return new class extends Migration
{
    private array $tables = ['patient_files', 'patient_notes', 'patient_visits'];

    public function up(): void
    {
        foreach ($this->tables as $table) {
            if (!Schema::hasTable($table)) {
                continue;
            }

            foreach (['created_at', 'updated_at'] as $column) {
                if (!Schema::hasColumn($table, $column)) {
                    continue;
                }

                DB::table($table)
                    ->where($column, 'like', '%T%')
                    ->orderBy('id')
                    ->chunkById(200, function ($rows) use ($table, $column) {
                        foreach ($rows as $row) {
                            $raw = $row->{$column};
                            if (!$raw || !str_contains($raw, 'T')) {
                                continue;
                            }

                            try {
                                $normalized = Carbon::parse($raw)->format('Y-m-d H:i:s');
                            } catch (Throwable) {
                                continue;
                            }

                            DB::table($table)->where('id', $row->id)->update([$column => $normalized]);
                        }
                    });
            }
        }
    }

    public function down(): void
    {
        // Format normalization is not reversible (original precision/offset is discarded).
    }
};
