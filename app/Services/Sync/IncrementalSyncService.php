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
     * Pull patients updated since the given timestamp.
     */
    private function pullIncrementalPatients(?Carbon $since): void
    {
        try {
            $apiPatientRepo = app(\App\Repositories\Api\ApiPatientRepository::class);

            $params = ['per_page' => 100];
            if ($since) {
                $params['updated_since'] = $since->toIso8601String();
            }

            $body = $apiPatientRepo->all(); // With per_page=1000
            $patients = $body['data'] ?? $body['patients'] ?? $body ?? [];

            if (empty($patients)) {
                Log::info('[IncrementalSync] No new/updated patients to sync.');
                return;
            }

            $count = 0;
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
                    $count++;
                } catch (\Exception $e) {
                    Patient::reguard();
                    Log::warning("[IncrementalSync] Failed to sync patient {$item['uuid']}: " . $e->getMessage());
                }
            }

            Log::info("[IncrementalSync] Synced {$count} patients incrementally.");
        } catch (\Throwable $e) {
            Log::warning('[IncrementalSync] Incremental patient pull failed: ' . $e->getMessage());
        }
    }

    /**
     * Pull child resources (files, notes, visits) updated since the timestamp.
     */
    private function pullIncrementalChildResources(?Carbon $since): void
    {
        $patients = Patient::withoutGlobalScopes()->pluck('uuid');

        $syncedFiles = 0;
        $syncedNotes = 0;
        $syncedVisits = 0;

        foreach ($patients as $patientUuid) {
            try {
                // Files — using FullSyncService's static method which handles patient_id resolution
                $apiFileRepo = app(\App\Repositories\Api\ApiPatientFileRepository::class);
                $files = $apiFileRepo->forPatient($patientUuid);
                if (!empty($files)) {
                    FullSyncService::syncFilesWithLocalPatientId($patientUuid, $files);
                    $syncedFiles += count($files);
                }

                // Notes — using FullSyncService's static method
                $apiNoteRepo = app(\App\Repositories\Api\ApiPatientNoteRepository::class);
                $notes = $apiNoteRepo->forPatient($patientUuid);
                if (!empty($notes)) {
                    FullSyncService::syncChildRecordsWithLocalPatientId($patientUuid, $notes, PatientNote::class);
                    $syncedNotes += count($notes);
                }

                // Visits — using FullSyncService's static method
                $apiVisitRepo = app(\App\Repositories\Api\ApiPatientVisitRepository::class);
                $visits = $apiVisitRepo->forPatient($patientUuid);
                if (!empty($visits)) {
                    FullSyncService::syncChildRecordsWithLocalPatientId($patientUuid, $visits, PatientVisit::class);
                    $syncedVisits += count($visits);
                }
            } catch (\Throwable $e) {
                Log::warning("[IncrementalSync] Failed to sync child resources for patient {$patientUuid}: " . $e->getMessage());
            }
        }

        Log::info("[IncrementalSync] Incremental child sync: {$syncedFiles} files, {$syncedNotes} notes, {$syncedVisits} visits.");
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
