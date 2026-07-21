<?php

namespace App\Services\Sync;

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
use App\Repositories\Api\ApiUserRepository;
use App\Services\FullSyncService;
use App\Services\NetworkStatusService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class SyncManager
{
    /** Semaphore to prevent concurrent sync operations */
    private static bool $syncInProgress = false;

    public function __construct(
        private PatientRepositoryInterface $patientRepo,
        private PatientFileRepositoryInterface $fileRepo,
        private PatientNoteRepositoryInterface $noteRepo,
        private PatientVisitRepositoryInterface $visitRepo,
        private UserRepositoryInterface $userRepo,
        private PendingOperationsService $pendingOps,
        private ApiPatientRepository $apiPatientRepo,
        private ApiPatientFileRepository $apiFileRepo,
        private ApiPatientNoteRepository $apiNoteRepo,
        private ApiPatientVisitRepository $apiVisitRepo,
        private ApiUserRepository $apiUserRepo
    ) {}

    /**
     * Check if a sync operation is already in progress.
     */
    public static function isSyncInProgress(): bool
    {
        return self::$syncInProgress;
    }

    /**
     * Push pending local changes to the remote API.
     */
    public function pushPending(): void
    {
        Log::info('[SyncManager] Pushing pending operations to remote.');

        $items = $this->pendingOps->processPending();

        foreach ($items as $item) {
            try {
                $this->pushItem($item);
                $this->pendingOps->markResult($item, true);
            } catch (\Exception $e) {
                $this->pendingOps->markResult($item, false, $e->getMessage());
            }
        }
    }

    /**
     * Pull remote data into local SQLite cache.
     * Handles paginated API responses to ensure all patients sync.
     */
    public function pullMetadata(): void
    {
        if (self::$syncInProgress) {
            Log::info('[SyncManager] Sync already in progress, skipping.');
            return;
        }
        self::$syncInProgress = true;

        Log::info('[SyncManager] Starting metadata pull...');

        try {
            DB::statement('PRAGMA foreign_keys = OFF');

            // Push pending first
            $this->pushPending();

            // Sync users
            try {
                $remoteDoctors = $this->apiUserRepo->doctors();
                app(FullSyncService::class)->syncUsersLocally($remoteDoctors);
            } catch (\Throwable $e) {
                Log::warning('[SyncManager] Users sync failed: ' . $e->getMessage());
            }

            // Sync patients with pagination support
            $syncedPatients = $this->pullPaginatedPatients();

            // Sync child resources for each patient
            $syncedFilesCount = 0;
            $syncedNotesCount = 0;
            $syncedVisitsCount = 0;

            foreach ($syncedPatients as $p) {
                if (empty($p['uuid'])) continue;

                try {
                    // Files
                    $files = $this->pullPaginatedPatientFiles($p['uuid']);
                    if (!empty($files)) {
                        $syncedFilesCount += count($files);
                        app(FullSyncService::class)->syncFilesWithLocalPatientId($p['uuid'], $files);
                    }

                    // Notes
                    $notes = $this->pullPaginatedPatientNotes($p['uuid']);
                    if (!empty($notes)) {
                        $syncedNotesCount += count($notes);
                        FullSyncService::syncChildRecordsWithLocalPatientId($p['uuid'], $notes, PatientNote::class);
                    }

                    // Visits
                    $visits = $this->pullPaginatedPatientVisits($p['uuid']);
                    if (!empty($visits)) {
                        $syncedVisitsCount += count($visits);
                        FullSyncService::syncChildRecordsWithLocalPatientId($p['uuid'], $visits, PatientVisit::class);
                    }
                } catch (\Throwable $e) {
                    Log::warning("[SyncManager] Failed to sync resources for patient {$p['uuid']}: " . $e->getMessage());
                }
            }

            Log::info("[SyncManager] Pull complete. Synced files: {$syncedFilesCount}, notes: {$syncedNotesCount}, visits: {$syncedVisitsCount}.");
        } catch (\Throwable $e) {
            Log::error('[SyncManager] Metadata pull failed: ' . $e->getMessage());
        } finally {
            self::$syncInProgress = false;
            try { DB::statement('PRAGMA foreign_keys = ON'); } catch (\Throwable $e) {}
        }
    }

    /**
     * Pull patients with pagination support.
     */
    private function pullPaginatedPatients(): array
    {
        $allPatients = [];
        $page = 1;
        $perPage = 100;
        $hasMore = true;

        while ($hasMore) {
            try {
                $body = $this->apiPatientRepo->paginated($perPage, $page);
                $patients = $body['data'] ?? $body['patients'] ?? [];

                if (empty($patients)) {
                    $hasMore = false;
                    break;
                }

                // Save page immediately to avoid memory bloat
                foreach ($patients as $item) {
                    if (!is_array($item) || !isset($item['uuid'])) continue;
                    $cleanData = \Illuminate\Support\Arr::except($item, [
                        'id', 'primary_doctor', 'visits', 'shares', 'files', 'notes'
                    ]);
                    try {
                        Patient::unguard();
                        Patient::withoutGlobalScopes()->updateOrCreate(['uuid' => $item['uuid']], $cleanData);
                        Patient::reguard();
                    } catch (\Exception $e) {
                        Patient::reguard();
                    }
                }

                $allPatients = array_merge($allPatients, $patients);

                // Check meta for pagination info
                $meta = $body['meta'] ?? [];
                $currentPage = $meta['current_page'] ?? $page;
                $lastPage = $meta['last_page'] ?? $page;

                if ($currentPage >= $lastPage) {
                    $hasMore = false;
                } else {
                    $page++;
                }
            } catch (\Throwable $e) {
                Log::warning("[SyncManager] Failed to fetch patients page {$page}: " . $e->getMessage());
                $hasMore = false;
            }
        }

        Log::info('[SyncManager] Synced ' . count($allPatients) . ' patients across all pages.');
        return $allPatients;
    }

    /**
     * Pull patient files with pagination.
     */
    private function pullPaginatedPatientFiles(string $patientUuid): array
    {
        $allFiles = [];
        $page = 1;
        $perPage = 100;
        $hasMore = true;

        while ($hasMore) {
            try {
                $response = app(\App\Services\Mobile\ApiService::class)->get(
                    '/patients/' . $patientUuid . '/files',
                    ['per_page' => $perPage, 'page' => $page]
                );
                $files = $response['data'] ?? $response ?? [];

                if (empty($files)) {
                    $hasMore = false;
                    break;
                }

                $allFiles = array_merge($allFiles, $files);

                $meta = $response['meta'] ?? [];
                $currentPage = $meta['current_page'] ?? $page;
                $lastPage = $meta['last_page'] ?? $page;

                if ($currentPage >= $lastPage) {
                    $hasMore = false;
                } else {
                    $page++;
                }
            } catch (\Throwable $e) {
                Log::warning("[SyncManager] Failed to fetch files page {$page} for patient {$patientUuid}: " . $e->getMessage());
                $hasMore = false;
            }
        }

        return $allFiles;
    }

    /**
     * Pull patient notes with pagination.
     */
    private function pullPaginatedPatientNotes(string $patientUuid): array
    {
        $allNotes = [];
        $page = 1;
        $perPage = 100;
        $hasMore = true;

        while ($hasMore) {
            try {
                $response = app(\App\Services\Mobile\ApiService::class)->get(
                    '/patients/' . $patientUuid . '/notes',
                    ['per_page' => $perPage, 'page' => $page]
                );
                $notes = $response['data'] ?? $response ?? [];

                if (empty($notes)) {
                    $hasMore = false;
                    break;
                }

                $allNotes = array_merge($allNotes, $notes);

                $meta = $response['meta'] ?? [];
                $currentPage = $meta['current_page'] ?? $page;
                $lastPage = $meta['last_page'] ?? $page;

                if ($currentPage >= $lastPage) {
                    $hasMore = false;
                } else {
                    $page++;
                }
            } catch (\Throwable $e) {
                Log::warning("[SyncManager] Failed to fetch notes page {$page} for patient {$patientUuid}: " . $e->getMessage());
                $hasMore = false;
            }
        }

        return $allNotes;
    }

    /**
     * Pull patient visits with pagination.
     */
    private function pullPaginatedPatientVisits(string $patientUuid): array
    {
        $allVisits = [];
        $page = 1;
        $perPage = 100;
        $hasMore = true;

        while ($hasMore) {
            try {
                $response = app(\App\Services\Mobile\ApiService::class)->get(
                    '/patients/' . $patientUuid . '/visits',
                    ['per_page' => $perPage, 'page' => $page]
                );
                $visits = $response['data'] ?? $response ?? [];

                if (empty($visits)) {
                    $hasMore = false;
                    break;
                }

                $allVisits = array_merge($allVisits, $visits);

                $meta = $response['meta'] ?? [];
                $currentPage = $meta['current_page'] ?? $page;
                $lastPage = $meta['last_page'] ?? $page;

                if ($currentPage >= $lastPage) {
                    $hasMore = false;
                } else {
                    $page++;
                }
            } catch (\Throwable $e) {
                Log::warning("[SyncManager] Failed to fetch visits page {$page} for patient {$patientUuid}: " . $e->getMessage());
                $hasMore = false;
            }
        }

        return $allVisits;
    }

    /**
     * Bulk upsert patients from API into local SQLite.
     */
    private function bulkUpsertPatients(array $patients): void
    {
        foreach ($patients as $item) {
            if (!is_array($item) || !isset($item['uuid'])) continue;

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
                Log::warning("[SyncManager] Failed to cache patient {$item['uuid']}: " . $e->getMessage());
            }
        }
    }

    private function syncChildRecords(string $patientUuid, array $records, string $modelClass): void
    {
        // Delegate to FullSyncService to avoid code duplication
        FullSyncService::syncChildRecordsWithLocalPatientId($patientUuid, $records, $modelClass);
    }

    /**
     * Push a single queue item to the remote API.
     */
    private function pushItem(SyncQueueItem $item): void
    {
        $payload = $item->payload ?? [];

        match ($item->entity) {
            'Patient' => $this->pushPatient($item, $payload),
            'PatientVisit' => $this->pushVisit($item, $payload),
            'PatientNote' => $this->pushNote($item, $payload),
            'PatientFile' => $this->pushFile($item, $payload),
            default => Log::warning("[SyncManager] Unsupported queue entity: {$item->entity}"),
        };
    }

    private function pushPatient(SyncQueueItem $item, array $payload): void
    {
        match ($item->operation) {
            'create' => $this->apiPatientRepo->create($payload),
            'update' => $this->apiPatientRepo->update($item->record_uuid, $payload),
            'delete' => $item->record_uuid ? $this->apiPatientRepo->delete($item->record_uuid) : null,
        };
    }

    private function pushVisit(SyncQueueItem $item, array $payload): void
    {
        $patientUuid = $payload['patient_uuid'] ?? null;
        if (!$patientUuid) return;

        match ($item->operation) {
            'create' => $this->apiVisitRepo->create($patientUuid, $payload),
            'update' => $item->record_uuid ? $this->apiVisitRepo->update((int)$item->record_uuid, $payload) : null,
            'delete' => $item->record_uuid ? $this->apiVisitRepo->delete((int)$item->record_uuid) : null,
        };
    }

    private function pushNote(SyncQueueItem $item, array $payload): void
    {
        $patientUuid = $payload['patient_uuid'] ?? null;
        if (!$patientUuid) return;

        match ($item->operation) {
            'create' => $this->apiNoteRepo->create($patientUuid, $payload),
            'update' => $item->record_uuid ? $this->apiNoteRepo->update($patientUuid, $item->record_uuid, $payload) : null,
            'delete' => $item->record_uuid ? $this->apiNoteRepo->delete($patientUuid, $item->record_uuid) : null,
        };
    }

    private function pushFile(SyncQueueItem $item, array $payload): void
    {
        $patientUuid = $payload['patient_uuid'] ?? null;

        if ($item->operation === 'delete' && $item->record_uuid) {
            $this->apiFileRepo->delete($item->record_uuid);
            return;
        }

        if ($item->operation === 'create' && $patientUuid) {
            $localPath = $payload['local_path'] ?? null;
            if (!$localPath) {
                Log::warning("[SyncManager] PatientFile create: no local_path for {$item->record_uuid}");
                return;
            }

            $fullPath = \Illuminate\Support\Facades\Storage::disk('local')->path($localPath);
            if (!file_exists($fullPath)) {
                Log::warning("[SyncManager] PatientFile create: file not found at {$fullPath}");
                return;
            }

            $apiUrl = config('app.mobile_api_url', 'https://prof-hosam-fekry.online/api/v1/mobile');
            $url = $apiUrl . '/patients/' . $patientUuid . '/files';

            $token = null;
            try {
                $token = app(\App\Services\Mobile\ApiService::class)->getToken();
            } catch (\Throwable $e) {
                try { $token = session('api_token_raw'); } catch (\Throwable $se) {}
            }

            $mimeType = $payload['mime_type'] ?? (mime_content_type($fullPath) ?: 'application/octet-stream');
            $fileName = $payload['file_name'] ?? basename($fullPath);

            $http = \Illuminate\Support\Facades\Http::timeout(120)
                ->when($token, fn($c) => $c->withToken($token))
                ->withHeaders(['Accept' => 'application/json'])
                ->attach('file', file_get_contents($fullPath), $fileName, ['Content-Type' => $mimeType]);

            $data = \Illuminate\Support\Arr::except($payload, ['patient_uuid', 'local_path', 'file', 'file_path', 'file_name', 'mime_type', 'size', 'upload_status']);
            foreach ($data as $k => $v) {
                if ($v !== null) $http = $http->attach($k, (string) $v);
            }

            $response = $http->post($url);
            if ($response->failed()) {
                throw new \RuntimeException("File upload failed: " . ($response->json('message') ?? $response->body()));
            }

            Log::info("[SyncManager] Uploaded offline file {$fileName} for patient {$patientUuid}");

            $responseData = $response->json();
            $remoteFileUuid = $responseData['data']['uuid'] ?? null;
            if ($remoteFileUuid && $item->record_uuid && $remoteFileUuid !== $item->record_uuid) {
                try {
                    PatientFile::where('uuid', $item->record_uuid)
                        ->update(['uuid' => $remoteFileUuid]);
                    Log::info("[SyncManager] Updated local file UUID from {$item->record_uuid} to {$remoteFileUuid}");
                } catch (\Throwable $e) {
                    Log::warning("[SyncManager] Failed to update local file UUID: " . $e->getMessage());
                }
            }
        }
    }
}
