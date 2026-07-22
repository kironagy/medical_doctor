<?php

namespace App\Services\Sync;

use App\Domains\Patients\Models\Patient;
use App\Domains\Patients\Models\PatientNote;
use App\Domains\Patients\Models\PatientVisit;
use App\Services\FullSyncService;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class IncrementalSyncService
{
    /**
     * Perform an incremental sync — only pull records updated since the last sync.
     * Reduces bandwidth and speeds up sync cycles.
     */
    public function incrementalPull(): void
    {
        $lastSyncAt = $this->getLastSyncTimestamp();
        $now = now();

        Log::info("[IncrementalSync] Starting incremental pull since {$lastSyncAt}");

        try {
            DB::statement('PRAGMA foreign_keys = OFF');

            // Pull patients updated since last sync
            $this->pullIncrementalPatients($lastSyncAt);

            // Pull files, notes, visits updated since last sync
            $this->pullIncrementalChildResources($lastSyncAt);

            // Update the last sync timestamp
            $this->setLastSyncTimestamp($now);

            Log::info("[IncrementalSync] Incremental pull complete.");
        } catch (\Throwable $e) {
            Log::error('[IncrementalSync] Incremental pull failed: ' . $e->getMessage());
        } finally {
            try { DB::statement('PRAGMA foreign_keys = ON'); } catch (\Throwable $e) {}
        }
    }

    /**
     * Pull patients updated since the given timestamp using paginated API calls.
     */
    private function pullIncrementalPatients(?Carbon $since): void
    {
        try {
            $apiPatientRepo = app(\App\Repositories\Api\ApiPatientRepository::class);

            $updatedSinceStr = $since?->toIso8601String();
            $allPatients = [];
            $page = 1;
            $perPage = 100;
            $hasMore = true;

            while ($hasMore) {
                try {
                    $body = $apiPatientRepo->paginated($perPage, $page, null, null, $updatedSinceStr);
                    $patients = $body['data'] ?? $body['patients'] ?? [];

                    if (empty($patients)) {
                        $hasMore = false;
                        break;
                    }

                    foreach ($patients as $item) {
                        if (empty($item['uuid'])) continue;

                        $cleanData = \Illuminate\Support\Arr::except($item, [
                            'id', 'primary_doctor', 'visits', 'shares', 'files', 'notes'
                        ]);

                        try {
                            Patient::unguard();
                            Patient::withoutGlobalScopes()->updateOrCreate(
                                ['uuid' => $item['uuid']],
                                $cleanData
                            );
                            Patient::reguard();
                        } catch (\Exception $e) {
                            Patient::reguard();
                            Log::warning("[IncrementalSync] Failed to sync patient {$item['uuid']}: " . $e->getMessage());
                        }
                    }

                    $allPatients = array_merge($allPatients, $patients);

                    $meta = $body['meta'] ?? [];
                    $currentPage = $meta['current_page'] ?? $page;
                    $lastPage = $meta['last_page'] ?? $page;

                    if ($currentPage >= $lastPage) {
                        $hasMore = false;
                    } else {
                        $page++;
                    }
                } catch (\Throwable $e) {
                    Log::warning("[IncrementalSync] Failed to fetch patients page {$page}: " . $e->getMessage());
                    $hasMore = false;
                }
            }

            Log::info("[IncrementalSync] Incremental patient sync: " . count($allPatients) . " patients (since {$updatedSinceStr}).");
        } catch (\Throwable $e) {
            Log::warning('[IncrementalSync] Incremental patient pull failed: ' . $e->getMessage());
        }
    }

    /**
     * Pull child resources (files, notes, visits) updated since the timestamp.
     * Calls API repos directly with updated_since filter.
     */
    private function pullIncrementalChildResources(?Carbon $since): void
    {
        $patients = Patient::withoutGlobalScopes()->pluck('uuid');
        $updatedSinceStr = $since?->toIso8601String();

        $syncedFiles = 0;
        $syncedNotes = 0;
        $syncedVisits = 0;

        foreach ($patients as $patientUuid) {
            try {
                // Files
                $apiFileRepo = app(\App\Repositories\Api\ApiPatientFileRepository::class);
                $files = $apiFileRepo->forPatient($patientUuid, $updatedSinceStr) ?? [];
                if (!empty($files)) {
                    FullSyncService::syncFilesWithLocalPatientId($patientUuid, $files);
                    $syncedFiles += count($files);
                }

                // Notes
                $apiNoteRepo = app(\App\Repositories\Api\ApiPatientNoteRepository::class);
                $notes = $apiNoteRepo->forPatient($patientUuid, $updatedSinceStr) ?? [];
                if (!empty($notes)) {
                    FullSyncService::syncChildRecordsWithLocalPatientId($patientUuid, $notes, PatientNote::class);
                    $syncedNotes += count($notes);
                }

                // Visits
                $apiVisitRepo = app(\App\Repositories\Api\ApiPatientVisitRepository::class);
                $visits = $apiVisitRepo->forPatient($patientUuid, $updatedSinceStr) ?? [];
                if (!empty($visits)) {
                    FullSyncService::syncChildRecordsWithLocalPatientId($patientUuid, $visits, PatientVisit::class);
                    $syncedVisits += count($visits);
                }
            } catch (\Throwable $e) {
                Log::warning("[IncrementalSync] Failed to sync child resources for patient {$patientUuid}: " . $e->getMessage());
            }
        }

        Log::info("[IncrementalSync] Incremental child sync: {$syncedFiles} files, {$syncedNotes} notes, {$syncedVisits} visits (since {$updatedSinceStr}).");
    }

    /**
     * Get the last successful sync timestamp from the sync_states table.
     */
    private function getLastSyncTimestamp(): ?Carbon
    {
        try {
            $row = DB::table('sync_states')
                ->where('key', 'last_incremental_sync_at')
                ->first();
            if (!$row || !$row->value) return null;

            $value = json_decode($row->value, true);
            $timestamp = is_array($value) ? ($value['timestamp'] ?? $row->value) : $row->value;
            if (is_string($timestamp)) {
                return new Carbon($timestamp);
            }
            return null;
        } catch (\Exception $e) {
            return null;
        }
    }

    /**
     * Seed the incremental sync timestamp without performing a sync.
     * Used after a full sync to ensure the next cycle uses incremental.
     */
    public function seedTimestamp(): void
    {
        $this->setLastSyncTimestamp(now());
        Log::info('[IncrementalSync] Timestamp seeded after full sync.');
    }

    /**
     * Store the last successful sync timestamp.
     */
    private function setLastSyncTimestamp(Carbon $timestamp): void
    {
        try {
            $value = json_encode(['timestamp' => $timestamp->toIso8601String()]);
            $exists = DB::table('sync_states')->where('key', 'last_incremental_sync_at')->exists();

            if ($exists) {
                DB::table('sync_states')
                    ->where('key', 'last_incremental_sync_at')
                    ->update(['value' => $value, 'updated_at' => now()]);
            } else {
                DB::table('sync_states')->insert([
                    'key' => 'last_incremental_sync_at',
                    'value' => $value,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        } catch (\Exception $e) {
            Log::warning('[IncrementalSync] Failed to save last sync timestamp: ' . $e->getMessage());
        }
    }
}
