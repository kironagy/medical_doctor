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
                    $files = $this->fileRepo->forPatient($p['uuid']);
                    $this->noteRepo->forPatient($p['uuid']);
                    $this->visitRepo->forPatient($p['uuid']);

                    // Download actual files and thumbnails to local disk for offline preview
                    foreach ($files as $fileData) {
                        $uuid = $fileData['uuid'] ?? null;
                        if (!$uuid) continue;

                        $fileModel = \App\Domains\Media\Models\PatientFile::where('uuid', $uuid)->first();
                        if (!$fileModel) continue;

                        $filePath = $fileModel->file_path;
                        $thumbPath = $fileModel->thumbnail_path;

                        // 1. Download file binary if missing
                        if ($filePath && !\Illuminate\Support\Facades\Storage::disk('local')->exists($filePath)) {
                            try {
                                $response = \App\Services\ApiProxy::get('/files/' . $uuid . '/stream');
                                if ($response->successful()) {
                                    \Illuminate\Support\Facades\Storage::disk('local')->put($filePath, $response->body());
                                    Log::info("[FullSyncService] Downloaded file binary: {$filePath}");
                                }
                            } catch (\Throwable $dlErr) {
                                Log::warning("[FullSyncService] Failed to download file binary for {$uuid}: " . $dlErr->getMessage());
                            }
                        }

                        // 2. Download thumbnail binary if missing
                        if ($thumbPath && !\Illuminate\Support\Facades\Storage::disk('local')->exists($thumbPath)) {
                            try {
                                $response = \App\Services\ApiProxy::get('/files/' . $uuid . '/thumbnail');
                                if ($response->successful()) {
                                    \Illuminate\Support\Facades\Storage::disk('local')->put($thumbPath, $response->body());
                                    Log::info("[FullSyncService] Downloaded thumbnail binary: {$thumbPath}");
                                }
                            } catch (\Throwable $dlErr) {
                                Log::warning("[FullSyncService] Failed to download thumbnail for {$uuid}: " . $dlErr->getMessage());
                            }
                        }
                    }
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
