<?php

namespace App\Services;

use App\Contracts\Repositories\PatientRepositoryInterface;
use App\Contracts\Repositories\PatientFileRepositoryInterface;
use App\Contracts\Repositories\PatientNoteRepositoryInterface;
use App\Contracts\Repositories\PatientVisitRepositoryInterface;
use App\Contracts\Repositories\UserRepositoryInterface;
use Illuminate\Support\Facades\Log;

class FullSyncService
{
    public function __construct(
        private readonly PatientRepositoryInterface $patientRepo,
        private readonly PatientFileRepositoryInterface $fileRepo,
        private readonly PatientNoteRepositoryInterface $noteRepo,
        private readonly PatientVisitRepositoryInterface $visitRepo,
        private readonly UserRepositoryInterface $userRepo
    ) {}

    /**
     * Pulls all patients, patient files, notes, visits, and users from the remote API
     * and caches them into the local SQLite database.
     */
    public function syncAll(): void
    {
        Log::info('[FullSyncService] Starting full database synchronization...');

        try {
            // 1. Sync all patients (this automatically runs syncLocalCache in Hybrid Patient Repo)
            $patients = $this->patientRepo->all();
            Log::info('[FullSyncService] Synchronized ' . count($patients) . ' patients.');

            // 2. Sync child resources for each patient
            foreach ($patients as $p) {
                if (empty($p['uuid'])) continue;

                try {
                    // This pulls child files, notes, visits from API and caches them locally
                    $this->fileRepo->forPatient($p['uuid']);
                    $this->noteRepo->forPatient($p['uuid']);
                    $this->visitRepo->forPatient($p['uuid']);
                } catch (\Throwable $e) {
                    Log::warning("[FullSyncService] Failed to sync child resources for patient {$p['uuid']}: " . $e->getMessage());
                }
            }

            // 3. Sync doctors
            $this->userRepo->doctors();

            Log::info('[FullSyncService] Full database synchronization completed successfully.');
        } catch (\Throwable $e) {
            Log::error('[FullSyncService] Full database synchronization failed: ' . $e->getMessage());
        }
    }
}
