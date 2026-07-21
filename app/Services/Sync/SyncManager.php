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
use App\Services\SyncQueueService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class SyncManager
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
        private ApiPatientVisitRepository $apiVisitRepo,
        private ApiUserRepository $apiUserRepo
    ) {}

    /**
     * Check if a sync operation is already in progress using database-backed lock.
     */
    public static function isSyncInProgress(): bool
    {
        $inProgress = DB::table('sync_states')->where('key', 'sync_in_progress')->first();
        if (!$inProgress) {
            return false;
        }

        $value = json_decode($inProgress->value);
        if ($value !== true) {
            return false;
        }

        $lockTime = DB::table('sync_states')->where('key', 'sync_lock_acquired_at')->first();
        if (!$lockTime) {
            return false;
        }

        $acquiredAt = json_decode($lockTime->value);
        if (!$acquiredAt) {
            return false;
        }

        if (now()->diffInSeconds(new \Carbon\Carbon($acquiredAt)) > 300) {
            return false;
        }

        return true;
    }

    /**
     * Push pending local changes to the remote API.
     * Uses SyncQueueService with dependency ordering.
     */
    public function pushPending(): void
    {
        Log::info('[SyncManager] Pushing pending operations to remote.');

        $items = $this->syncQueue->processPendingOperations();

        foreach ($items as $item) {
            try {
                $this->pushItem($item);
                $this->syncQueue->markItemResult($item, true);
            } catch (\Exception $e) {
                $this->syncQueue->markItemResult($item, false, $e->getMessage());
            }
        }
    }

    /**
     * Push all pending items respecting dependency order.
     * Processes in batches: first all patients, then child records.
     */
    public function pushPendingWithDependencyOrder(): void
    {
        Log::info('[SyncManager] Pushing pending operations with dependency ordering.');

        $grouped = $this->syncQueue->getItemsGroupedByDependency();

        foreach ($grouped as $level => $items) {
            Log::info("[SyncManager] Processing dependency level {$level}: {$items->count()} items");

            foreach ($items as $item) {
                if (!in_array($item->status, ['pending', 'failed'])) {
                    continue;
                }
                if ($item->retry_count >= 5) {
                    continue;
                }

                try {
                    $item->update(['status' => 'pending']);
                    $this->pushItem($item);
                    $this->syncQueue->markItemResult($item, true);
                } catch (\Exception $e) {
                    $this->syncQueue->markItemResult($item, false, $e->getMessage());
                }
            }
        }
    }

    /**
     * Pull remote data into local SQLite cache.
     * Handles paginated API responses to ensure all patients sync.
     */
    public function pullMetadata(): void
    {
        if (!$this->syncQueue->acquireLock()) {
            Log::info('[SyncManager] Sync already in progress, skipping.');
            return;
        }

        Log::info('[SyncManager] Starting metadata pull...');

        try {
            try { DB::statement('PRAGMA foreign_keys = OFF'); } catch (\Throwable $e) {}

            // Push pending first using dependency ordering
            $this->pushPendingWithDependencyOrder();

            // Sync users
            try {
                $remoteDoctors = $this->apiUserRepo->doctors();
                app(FullSyncService::class)->syncUsersLocally($remoteDoctors);
            } catch (\Throwable $e) {
                Log::warning('[SyncManager] Users sync failed: ' . $e->getMessage());
            }

            // Sync patients with pagination support
            $syncedPatients = $this->pullPaginatedPatients();

            // Sync child resources using batched fetching
            $syncedFilesCount = 0;
            $syncedNotesCount = 0;
            $syncedVisitsCount = 0;

            $resolver = app(ConflictResolver::class);
            $patientUuids = array_filter(array_map(fn($p) => $p['uuid'] ?? null, $syncedPatients));

            if (!empty($patientUuids)) {
                [$allFiles, $allNotes, $allVisits] = $this->fetchChildResourcesBatched($patientUuids);

                foreach ($patientUuids as $patientUuid) {
                    $patientFiles = $allFiles[$patientUuid] ?? [];
                    if (!empty($patientFiles)) {
                        $syncedFilesCount += count($patientFiles);
                        FullSyncService::syncFilesWithLocalPatientId($patientUuid, $patientFiles, $resolver);
                    }

                    $patientNotes = $allNotes[$patientUuid] ?? [];
                    if (!empty($patientNotes)) {
                        $syncedNotesCount += count($patientNotes);
                        FullSyncService::syncChildRecordsWithLocalPatientId($patientUuid, $patientNotes, PatientNote::class, $resolver);
                    }

                    $patientVisits = $allVisits[$patientUuid] ?? [];
                    if (!empty($patientVisits)) {
                        $syncedVisitsCount += count($patientVisits);
                        FullSyncService::syncChildRecordsWithLocalPatientId($patientUuid, $patientVisits, PatientVisit::class, $resolver);
                    }
                }
            }

            Log::info("[SyncManager] Pull complete. Synced files: {$syncedFilesCount}, notes: {$syncedNotesCount}, visits: {$syncedVisitsCount}.");
        } catch (\Throwable $e) {
            Log::error('[SyncManager] Metadata pull failed: ' . $e->getMessage());
        } finally {
            $this->syncQueue->releaseLock();
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
     * Fetch child resources (files, notes, visits) for all patients in batches.
     */
    private function fetchChildResourcesBatched(array $patientUuids): array
    {
        $allFiles = [];
        $allNotes = [];
        $allVisits = [];

        $batches = array_chunk($patientUuids, 10);

        foreach ($batches as $batch) {
            foreach ($batch as $patientUuid) {
                try {
                    $files = $this->pullPaginatedPatientFiles($patientUuid);
                    if (!empty($files)) {
                        $allFiles[$patientUuid] = $files;
                    }
                } catch (\Throwable $e) {
                    Log::warning("[SyncManager] Failed to fetch files for patient {$patientUuid}: " . $e->getMessage());
                }

                try {
                    $notes = $this->pullPaginatedPatientNotes($patientUuid);
                    if (!empty($notes)) {
                        $allNotes[$patientUuid] = $notes;
                    }
                } catch (\Throwable $e) {
                    Log::warning("[SyncManager] Failed to fetch notes for patient {$patientUuid}: " . $e->getMessage());
                }

                try {
                    $visits = $this->pullPaginatedPatientVisits($patientUuid);
                    if (!empty($visits)) {
                        $allVisits[$patientUuid] = $visits;
                    }
                } catch (\Throwable $e) {
                    Log::warning("[SyncManager] Failed to fetch visits for patient {$patientUuid}: " . $e->getMessage());
                }
            }
        }

        return [$allFiles, $allNotes, $allVisits];
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
                Log::warning("[SyncManager] Failed to fetch files for patient {$patientUuid} page {$page}: " . $e->getMessage());
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
                Log::warning("[SyncManager] Failed to fetch notes for patient {$patientUuid} page {$page}: " . $e->getMessage());
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
                Log::warning("[SyncManager] Failed to fetch visits for patient {$patientUuid} page {$page}: " . $e->getMessage());
                $hasMore = false;
            }
        }

        return $allVisits;
    }

    /**
     * Push a single sync queue item to the remote API.
     */
    private function pushItem(SyncQueueItem $item): void
    {
        $payload = $item->payload ?? [];

        match ($item->entity) {
            'Patient' => $this->pushPatientToRemote($item, $payload),
            'PatientVisit' => $this->pushVisitToRemote($item, $payload),
            'PatientNote' => $this->pushNoteToRemote($item, $payload),
            'PatientFile' => $this->pushFileToRemote($item, $payload),
            default => Log::warning("[SyncManager] Unsupported queue entity: {$item->entity}"),
        };
    }

    private function pushFileToRemote(SyncQueueItem $item, array $payload): void
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
                try {
                    $token = session('api_token_raw');
                } catch (\Throwable $se) {}
            }

            $mimeType = $payload['mime_type'] ?? (mime_content_type($fullPath) ?: 'application/octet-stream');
            $fileName = $payload['file_name'] ?? basename($fullPath);

            $http = \Illuminate\Support\Facades\Http::timeout(120)
                ->when($token, fn($c) => $c->withToken($token))
                ->withHeaders(['Accept' => 'application/json']);

            $fileResource = fopen($fullPath, 'r');

            $http = $http->attach(
                'file',
                $fileResource,
                $fileName,
                ['Content-Type' => $mimeType]
            );

            $data = \Illuminate\Support\Arr::except($payload, ['patient_uuid', 'local_path', 'file', 'file_path', 'file_name', 'mime_type', 'size', 'upload_status']);

            // Include local UUID so the server uses it instead of generating a new one
            $data['uuid'] = $item->record_uuid;

            foreach ($data as $k => $v) {
                if ($v !== null) {
                    $http = $http->attach($k, (string) $v);
                }
            }

            $response = $http->post($url);
            if (is_resource($fileResource)) {
                fclose($fileResource);
            }

            if ($response->failed()) {
                throw new \RuntimeException("File upload failed: " . ($response->json('message') ?? $response->body()));
            }

            Log::info("[SyncManager] Uploaded offline file {$fileName} for patient {$patientUuid}");
        }
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
        if (!$patientUuid) {
            return;
        }

        if ($item->operation === 'create') {
            $this->apiVisitRepo->create($patientUuid, $item->payload);
        } elseif ($item->operation === 'update' && $item->record_uuid) {
            $this->apiVisitRepo->update((int) $item->record_uuid, $item->payload);
        } elseif ($item->operation === 'delete' && $item->record_uuid) {
            $this->apiVisitRepo->delete((int) $item->record_uuid);
        }
    }

    private function pushNoteToRemote(SyncQueueItem $item, array $payload): void
    {
        $patientUuid = $payload['patient_uuid'] ?? null;
        if (!$patientUuid) {
            return;
        }

        if ($item->operation === 'create') {
            $this->apiNoteRepo->create($patientUuid, $item->payload);
        } elseif ($item->operation === 'update' && $item->record_uuid) {
            $this->apiNoteRepo->update($patientUuid, $item->record_uuid, $item->payload);
        } elseif ($item->operation === 'delete' && $item->record_uuid) {
            $this->apiNoteRepo->delete($patientUuid, $item->record_uuid);
        }
    }
}
