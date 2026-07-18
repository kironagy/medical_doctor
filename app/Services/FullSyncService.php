<?php

namespace App\Services;

use App\Contracts\Repositories\PatientFileRepositoryInterface;
use App\Contracts\Repositories\PatientNoteRepositoryInterface;
use App\Contracts\Repositories\PatientRepositoryInterface;
use App\Contracts\Repositories\PatientVisitRepositoryInterface;
use App\Contracts\Repositories\UserRepositoryInterface;
use App\Domains\Media\Models\PatientFile;
use App\Domains\Patients\Models\Patient;
use App\Domains\Patients\Models\PatientNote;
use App\Domains\Patients\Models\PatientVisit;
use App\Models\SyncQueueItem;
use App\Repositories\Api\ApiPatientFileRepository;
use App\Repositories\Api\ApiPatientNoteRepository;
use App\Repositories\Api\ApiPatientRepository;
use App\Repositories\Api\ApiPatientVisitRepository;
use App\Services\SyncQueueService;
use Illuminate\Support\Facades\Log;

class FullSyncService
{
    public function __construct(
        private PatientRepositoryInterface $patientRepo,
        private PatientFileRepositoryInterface $fileRepo,
        private PatientNoteRepositoryInterface $noteRepo,
        private PatientVisitRepositoryInterface $visitRepo,
        private UserRepositoryInterface $userRepo,
        private SyncQueueService $syncQueue,
        private ApiPatientRepository $apiPatientRepo,
        private ApiPatientFileRepository $apiFileRepo,
        private ApiPatientNoteRepository $apiNoteRepo,
        private ApiPatientVisitRepository $apiVisitRepo
    ) {}

    /**
     * Push pending SyncQueueItem records to the remote API,
     * then pull fresh remote data into the local SQLite database.
     */
    public function syncPendingOperations(): void
    {
        Log::info('[FullSyncService] Pushing pending sync_queue operations to remote.');

        $items = $this->syncQueue->processPendingOperations();

        foreach ($items as $item) {
            try {
                $this->pushQueueItem($item);

                $this->syncQueue->markItemResult($item, true);
            } catch (\Exception $e) {
                $this->syncQueue->markItemResult($item, false, $e->getMessage());
            }
        }
    }

    /**
     * Pull all patients and their child resources from the remote API
     * and cache them into the local SQLite database.
     *
     * Uses dedicated API repositories (not Hybrid) so the pull path
     * always hits the remote server directly with the API token.
     */
    public function syncAll(): void
    {
        $this->syncPendingOperations();

        Log::info('[FullSyncService] Starting full database synchronization...');

        try {
            // 1. Pull all patients from remote API and cache them locally
            $patients = $this->apiPatientRepo->all();
            $this->syncLocalCache($patients, Patient::class);
            Log::info('[FullSyncService] Synchronized ' . count($patients) . ' patients.');

            // 2. Sync child resources for each patient
            foreach ($patients as $p) {
                if (empty($p['uuid'])) {
                    continue;
                }

                try {
                    $files  = $this->apiFileRepo->forPatient($p['uuid']);
                    $notes  = $this->apiNoteRepo->forPatient($p['uuid']);
                    $visits = $this->apiVisitRepo->forPatient($p['uuid']);

                    $this->syncLocalCache($files, PatientFile::class);
                    $this->syncLocalCache($notes, PatientNote::class);
                    $this->syncLocalCache($visits, PatientVisit::class);

                    // Download file binaries and thumbnails for offline preview
                    foreach ($files as $fileData) {
                        $uuid = $fileData['uuid'] ?? null;
                        if (!$uuid) {
                            continue;
                        }

                        $fileModel = PatientFile::where('uuid', $uuid)->first();
                        if (!$fileModel) {
                            continue;
                        }

                        $filePath = $fileModel->file_path;
                        $thumbPath = $fileModel->thumbnail_path;

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

    /**
     * Push a single SyncQueueItem to the remote using the appropriate API repository.
     */
    private function pushQueueItem(SyncQueueItem $item): void
    {
        $payload = $item->payload ?? [];

        match ($item->entity) {
            'Patient'      => $this->pushPatientToRemote($item, $payload),
            'PatientVisit' => $this->pushVisitToRemote($item, $payload),
            'PatientNote'  => $this->pushNoteToRemote($item, $payload),
            default        => Log::warning("[FullSyncService] Unsupported queue entity: {$item->entity}"),
        };
    }

    private function pushPatientToRemote(SyncQueueItem $item, array $payload): void
    {
        if ($item->operation === 'create') {
            $this->apiPatientRepo->create($payload);
        } elseif ($item->operation === 'update') {
            $this->apiPatientRepo->update($item->record_uuid, $payload);
        } elseif ($item->operation === 'delete' && $item->record_uuid) {
            $this->apiPatientRepo->delete($item->record_uuid);
        }
    }

    private function pushVisitToRemote(SyncQueueItem $item, array $payload): void
    {
        $patientUuid = $payload['patient_uuid'] ?? null;
        if (! $patientUuid) {
            return;
        }

        if ($item->operation === 'create') {
            $this->apiVisitRepo->create($patientUuid, $item->payload);
        } elseif ($item->operation === 'update' && $item->record_uuid) {
            $this->apiVisitRepo->update((int) $item->record_uuid, $item->payload);
        } elseif ($item->operation === 'delete' && $item->record_uuid) {
            $this->apiVisitRepo->delete((int) $item->record_uuid, $item->payload);
        }
    }

    private function pushNoteToRemote(SyncQueueItem $item, array $payload): void
    {
        $patientUuid = $payload['patient_uuid'] ?? null;
        if (! $patientUuid) {
            return;
        }

        if ($item->operation === 'create') {
            $this->apiNoteRepo->create($patientUuid, $item->payload);
        } elseif ($item->operation === 'update' && $item->record_uuid) {
            $this->apiNoteRepo->update($patientUuid, $item->record_uuid, $item->payload);
        } elseif ($item->operation === 'delete' && $item->record_uuid) {
            $this->apiNoteRepo->delete($patientUuid, $item->record_uuid, $item->payload);
        }
    }

    /**
     * Upsert remote API data into the local Eloquent model table by uuid.
     */
    private function syncLocalCache(array $records, string $modelClass): void
    {
        if (empty($records)) return;

        try {
            $modelInstance = new $modelClass;
            $validColumns = array_merge(
                $modelInstance->getFillable(),
                ['id', 'created_at', 'updated_at', 'deleted_at']
            );
        } catch (\Exception $e) {
            $validColumns = [];
        }

        foreach ($records as $record) {
            if (empty($record['uuid'])) {
                continue;
            }

            $cleanRecord = [];
            foreach ($record as $key => $value) {
                if (empty($validColumns) || in_array($key, $validColumns)) {
                    if (is_array($value) && !array_key_exists($key, (new $modelClass)->getCasts())) {
                         $cleanRecord[$key] = json_encode($value);
                    } else {
                         $cleanRecord[$key] = $value;
                    }
                }
            }

            try {
                $modelClass::unguard();
                $modelClass::updateOrCreate(
                    ['uuid' => $record['uuid']],
                    $cleanRecord
                );
                $modelClass::reguard();
            } catch (\Exception $e) {
                try {
                    $modelClass::reguard();
                } catch (\Throwable $ignored) {}

                Log::warning(
                    "[FullSyncService] Failed to cache " . class_basename($modelClass) .
                    " {$record['uuid']}: " . $e->getMessage()
                );
            }
        }
    }
}
