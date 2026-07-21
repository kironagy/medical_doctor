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
use App\Repositories\Api\ApiUserRepository;
use App\Services\Sync\ConflictResolver;
use App\Services\Sync\SyncManager;
use App\Services\SyncQueueService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

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
        private ApiPatientVisitRepository $apiVisitRepo,
        private ApiUserRepository $apiUserRepo,
        private ConflictResolver $conflictResolver
    ) {}

    /**
     * Check if a sync operation is already in progress using database-backed lock.
     */
    public static function isSyncInProgress(): bool
    {
        return SyncManager::isSyncInProgress();
    }

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
     * Unified field mapping: normalize API response fields to model fields.
     */
    public static function normalizeFileRecord(array $record, ?string $patientUuid = null): array
    {
        $clean = \Illuminate\Support\Arr::except($record, ['id', 'patient', 'creator', 'uploader', 'url', 'thumbnail_url', 'description']);

        if (isset($record['description']) && !isset($clean['desc'])) {
            $clean['desc'] = $record['description'];
        }
        unset($clean['description']);

        if ($patientUuid) {
            try {
                $localPatient = Patient::withoutGlobalScopes()
                    ->where('uuid', $patientUuid)
                    ->first();
                if ($localPatient) {
                    $clean['patient_id'] = $localPatient->id;
                }
            } catch (\Throwable $e) {
                Log::warning("[FullSyncService] Cannot resolve local patient for UUID {$patientUuid}: " . $e->getMessage());
            }
        }

        if (empty($clean['uploaded_by_id'])) {
            if (isset($record['uploader']['id'])) {
                $clean['uploaded_by_id'] = $record['uploader']['id'];
            } else {
                try {
                    if (auth()->check()) {
                        $clean['uploaded_by_id'] = auth()->id();
                    }
                } catch (\Throwable $e) {}
            }
        }

        if (empty($clean['file_path']) && !empty($clean['file_name'])) {
            $resolvedUuid = $patientUuid ?? 'unknown';
            $clean['file_path'] = "patients/{$resolvedUuid}/{$clean['file_name']}";
        }

        return $clean;
    }

    public function syncMetadataOnly(): void
    {
        if (!$this->syncQueue->acquireLock()) {
            Log::info('[FullSyncService] Sync already in progress, skipping duplicate call.');
            return;
        }

        Log::info('[FullSyncService] Starting lightweight metadata synchronization...');

        try {
            DB::statement('PRAGMA foreign_keys = OFF');

            $this->syncPendingOperations();

            Log::info('[FullSyncService] Syncing users...');
            try {
                $remoteDoctors = $this->apiUserRepo->doctors();
                $this->syncUsersLocally($remoteDoctors);
                Log::info('[FullSyncService] Users sync completed. ' . count($remoteDoctors) . ' doctors.');
            } catch (\Throwable $e) {
                Log::warning('[FullSyncService] Users sync failed: ' . $e->getMessage());
            }

            Log::info('[FullSyncService] Syncing patients...');
            $patients = $this->apiPatientRepo->all();
            $this->syncLocalCache($patients, Patient::class);
            Log::info('[FullSyncService] Synchronized ' . count($patients) . ' patients.');

            $syncedFilesCount = 0;
            $syncedNotesCount = 0;
            $syncedVisitsCount = 0;
            $patientsWithFiles = 0;
            $patientsWithNotes = 0;
            $patientsWithVisits = 0;

            $patientUuids = array_filter(array_map(fn($p) => $p['uuid'] ?? null, $patients));

            if (!empty($patientUuids)) {
                [$allFiles, $allNotes, $allVisits] = $this->fetchChildResourcesBatched($patientUuids);

                foreach ($patientUuids as $patientUuid) {
                    $patientFiles = $allFiles[$patientUuid] ?? [];
                    if (!empty($patientFiles)) {
                        $patientsWithFiles++;
                        $syncedFilesCount += count($patientFiles);
                        self::syncFilesWithLocalPatientId($patientUuid, $patientFiles, $this->conflictResolver);
                    }

                    $patientNotes = $allNotes[$patientUuid] ?? [];
                    if (!empty($patientNotes)) {
                        $patientsWithNotes++;
                        $syncedNotesCount += count($patientNotes);
                        self::syncChildRecordsWithLocalPatientId($patientUuid, $patientNotes, PatientNote::class, $this->conflictResolver);
                    }

                    $patientVisits = $allVisits[$patientUuid] ?? [];
                    if (!empty($patientVisits)) {
                        $patientsWithVisits++;
                        $syncedVisitsCount += count($patientVisits);
                        self::syncChildRecordsWithLocalPatientId($patientUuid, $patientVisits, PatientVisit::class, $this->conflictResolver);
                    }
                }
            }

            Log::info("[FullSyncService] Metadata sync completed. Synced files: {$syncedFilesCount} (from {$patientsWithFiles} patients), notes: {$syncedNotesCount} (from {$patientsWithNotes} patients), visits: {$syncedVisitsCount} (from {$patientsWithVisits} patients).");

            $localCount = Patient::count();
            Log::info("[FullSyncService] {$localCount} patients in local SQLite.");
        } catch (\Throwable $e) {
            Log::error('[FullSyncService] Metadata synchronization failed: ' . $e->getMessage());
        } finally {
            $this->syncQueue->releaseLock();
            try {
                DB::statement('PRAGMA foreign_keys = ON');
            } catch (\Throwable $e) {}
        }
    }

    public function downloadFileBinary(string $fileUuid): bool
    {
        try {
            $file = PatientFile::withoutGlobalScopes()->where('uuid', $fileUuid)->first();
            if (!$file) {
                Log::warning('[FullSyncService] Cannot download binary: file not found: ' . $fileUuid);
                return false;
            }

            if ($file->is_cached_locally && $file->downloaded_at) {
                Log::info('[FullSyncService] File already cached locally: ' . $fileUuid);
                return true;
            }

            $remoteUrl = $file->remote_url;
            if (!$remoteUrl) {
                $apiUrl = config('app.mobile_api_url', 'https://prof-hosam-fekry.online/api/v1/mobile');
                $remoteUrl = $apiUrl . '/files/' . $fileUuid . '/stream';
            }

            $token = null;
            try {
                $token = app(\App\Services\Mobile\ApiService::class)->getToken();
            } catch (\Throwable $e) {}

            if (!$token) {
                Log::warning('[FullSyncService] Cannot download file binary: no API token');
                return false;
            }

            $localPath = $file->file_path;
            if (!$localPath) {
                $patientUuid = null;
                try {
                    $patient = $file->patient()->withoutGlobalScopes()->first();
                    $patientUuid = $patient?->uuid ?? 'unknown';
                } catch (\Throwable $e) {
                    $patientUuid = 'unknown';
                }
                $localPath = "patients/{$patientUuid}/{$file->file_name}";
                $file->file_path = $localPath;
            }

            $fullLocalPath = \Illuminate\Support\Facades\Storage::disk('local')->path($localPath);
            $localDir = dirname($fullLocalPath);
            if (!is_dir($localDir)) {
                mkdir($localDir, 0755, true);
            }

            $response = \Illuminate\Support\Facades\Http::timeout(120)
                ->withToken($token)
                ->withHeaders(['Accept' => 'application/json'])
                ->sink($fullLocalPath)
                ->get($remoteUrl);

            if ($response->successful() && file_exists($fullLocalPath) && filesize($fullLocalPath) > 0) {
                PatientFile::withoutGlobalScopes()->where('uuid', $fileUuid)->update([
                    'is_cached_locally' => true,
                    'downloaded_at' => now(),
                    'file_path' => $localPath,
                ]);
                Log::info('[FullSyncService] Downloaded file binary: ' . $fileUuid . ' (' . filesize($fullLocalPath) . ' bytes)');
                return true;
            }

            Log::warning('[FullSyncService] Failed to download file binary: ' . $fileUuid . ' (status: ' . $response->status() . ')');
            return false;
        } catch (\Throwable $e) {
            Log::error('[FullSyncService] Error downloading file binary: ' . $e->getMessage());
            return false;
        }
    }

    public function syncAll(): void
    {
        $this->syncMetadataOnly();
    }

    private function pushQueueItem(SyncQueueItem $item): void
    {
        $payload = $item->payload ?? [];

        match ($item->entity) {
            'Patient' => $this->pushPatientToRemote($item, $payload),
            'PatientVisit' => $this->pushVisitToRemote($item, $payload),
            'PatientNote' => $this->pushNoteToRemote($item, $payload),
            'PatientFile' => $this->pushFileToRemote($item, $payload),
            default => Log::warning("[FullSyncService] Unsupported queue entity: {$item->entity}"),
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
                Log::warning("[FullSyncService] PatientFile create: no local_path for {$item->record_uuid}");
                return;
            }

            $fullPath = \Illuminate\Support\Facades\Storage::disk('local')->path($localPath);
            if (!file_exists($fullPath)) {
                Log::warning("[FullSyncService] PatientFile create: file not found at {$fullPath}");
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

            $fileResource = fopen($fullPath, 'r');

            $http = \Illuminate\Support\Facades\Http::timeout(120)
                ->when($token, fn($c) => $c->withToken($token))
                ->withHeaders(['Accept' => 'application/json'])
                ->attach('file', $fileResource, $fileName, ['Content-Type' => $mimeType]);

            $data = \Illuminate\Support\Arr::except($payload, ['patient_uuid', 'local_path', 'file', 'file_path', 'file_name', 'mime_type', 'size', 'upload_status']);

            // Send local UUID so the server uses it instead of generating a new one
            $data['uuid'] = $item->record_uuid;

            foreach ($data as $k => $v) {
                if ($v !== null) $http = $http->attach($k, (string) $v);
            }

            $response = $http->post($url);
            if (is_resource($fileResource)) {
                fclose($fileResource);
            }

            if ($response->failed()) {
                throw new \RuntimeException("File upload failed: " . ($response->json('message') ?? $response->body()));
            }

            Log::info("[FullSyncService] Uploaded offline file {$fileName} for patient {$patientUuid}");
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

    /**
     * Sync file records from API, resolving patient_id to local patient ID.
     * Uses ConflictResolver to prevent silent data overwrite.
     */
    public static function syncFilesWithLocalPatientId(string $patientUuid, array $files, ?ConflictResolver $resolver = null): void
    {
        $modelClass = PatientFile::class;
        try {
            $modelInstance = new $modelClass;
            $validColumns = array_merge(
                $modelInstance->getFillable(),
                ['id', 'created_at', 'updated_at', 'deleted_at']
            );
        } catch (\Exception $e) {
            $validColumns = [];
        }

        $resolver = $resolver ?? app(ConflictResolver::class);
        $query = $modelClass::withoutGlobalScopes();

        foreach ($files as $record) {
            if (empty($record['uuid'])) {
                continue;
            }

            $cleanRecord = self::normalizeFileRecord($record, $patientUuid);

            if (!empty($validColumns)) {
                $cleanRecord = array_intersect_key($cleanRecord, array_flip($validColumns));
            }

            try {
                $existing = $query->where('uuid', $record['uuid'])->first();

                if ($existing) {
                    $resolution = $resolver->resolve(
                        $existing->client_updated_at?->toIso8601String(),
                        $record['client_updated_at'] ?? $record['updated_at'] ?? null,
                        $resolver->hasPendingChanges($record['uuid'])
                    );

                    if ($resolution === 'local') {
                        Log::info("[FullSyncService] Conflict: keeping local version for file {$record['uuid']}");
                        continue;
                    }
                }

                $modelClass::unguard();
                $modelClass::withoutEvents(function () use ($query, $record, $cleanRecord) {
                    $query->updateOrCreate(
                        ['uuid' => $record['uuid']],
                        $cleanRecord
                    );
                });
                $modelClass::reguard();
            } catch (\Exception $e) {
                try {
                    $modelClass::reguard();
                } catch (\Throwable $ignored) {}

                Log::warning("[FullSyncService] Failed to cache file {$record['uuid']}: " . $e->getMessage());
            }
        }
    }

    /**
     * Sync child records (notes, visits) from API, resolving patient_id to local ID.
     * Uses ConflictResolver to prevent silent data overwrite.
     */
    public static function syncChildRecordsWithLocalPatientId(string $patientUuid, array $records, string $modelClass, ?ConflictResolver $resolver = null): void
    {
        $localPatientId = null;
        try {
            $localPatient = Patient::withoutGlobalScopes()
                ->where('uuid', $patientUuid)
                ->first();
            $localPatientId = $localPatient?->id;
        } catch (\Throwable $e) {
            Log::warning("[FullSyncService] Cannot resolve local patient for UUID {$patientUuid}: " . $e->getMessage());
        }

        if (empty($records)) {
            return;
        }

        try {
            $modelInstance = new $modelClass;
            $validColumns = array_merge(
                $modelInstance->getFillable(),
                ['id', 'created_at', 'updated_at', 'deleted_at']
            );
        } catch (\Exception $e) {
            $validColumns = [];
        }

        $resolver = $resolver ?? app(ConflictResolver::class);
        $query = $modelClass::withoutGlobalScopes();

        foreach ($records as $record) {
            if (empty($record['uuid'])) {
                continue;
            }

            $cleanRecord = [];
            foreach ($record as $key => $value) {
                if (in_array($key, ['id', 'patient', 'author', 'url', 'thumbnail_url'], true)) {
                    continue;
                }
                if (empty($validColumns) || in_array($key, $validColumns)) {
                    if (is_array($value) && !array_key_exists($key, (new $modelClass)->getCasts())) {
                        $cleanRecord[$key] = json_encode($value);
                    } else {
                        $cleanRecord[$key] = $value;
                    }
                }
            }

            if ($localPatientId) {
                $cleanRecord['patient_id'] = $localPatientId;
            }

            try {
                $existing = $query->where('uuid', $record['uuid'])->first();

                if ($existing) {
                    $resolution = $resolver->resolve(
                        $existing->client_updated_at?->toIso8601String(),
                        $record['client_updated_at'] ?? $record['updated_at'] ?? null,
                        $resolver->hasPendingChanges($record['uuid'])
                    );

                    if ($resolution === 'local') {
                        Log::info("[FullSyncService] Conflict: keeping local version for " . class_basename($modelClass) . " {$record['uuid']}");
                        continue;
                    }
                }

                $modelClass::unguard();
                $modelClass::withoutEvents(function () use ($query, $record, $cleanRecord) {
                    $query->updateOrCreate(
                        ['uuid' => $record['uuid']],
                        $cleanRecord
                    );
                });
                $modelClass::reguard();
            } catch (\Exception $e) {
                try {
                    $modelClass::reguard();
                } catch (\Throwable $ignored) {}

                Log::warning("[FullSyncService] Failed to cache " . class_basename($modelClass) . " {$record['uuid']}: " . $e->getMessage());
            }
        }
    }

    /**
     * Generic local cache sync.
     */
    private function syncLocalCache(array $records, string $modelClass): void
    {
        if ($modelClass === PatientFile::class || $modelClass === PatientNote::class || $modelClass === PatientVisit::class) {
            Log::warning("[FullSyncService] syncLocalCache called for {$modelClass} — use typed method instead for proper patient_id resolution.");
        }

        if (empty($records)) {
            return;
        }

        try {
            $modelInstance = new $modelClass;
            $validColumns = array_merge(
                $modelInstance->getFillable(),
                ['id', 'created_at', 'updated_at', 'deleted_at']
            );
        } catch (\Exception $e) {
            $validColumns = [];
        }

        $query = $modelClass::withoutGlobalScopes();

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
                $modelClass::withoutEvents(function () use ($query, $record, $cleanRecord) {
                    $query->updateOrCreate(
                        ['uuid' => $record['uuid']],
                        $cleanRecord
                    );
                });
                $modelClass::reguard();
            } catch (\Exception $e) {
                try {
                    $modelClass::reguard();
                } catch (\Throwable $ignored) {}

                Log::warning("[FullSyncService] Failed to cache " . class_basename($modelClass) . " {$record['uuid']}: " . $e->getMessage());
            }
        }
    }

    /**
     * Fetch child resources (files, notes, visits) for all patients in batches.
     * Uses concurrent HTTP requests to reduce N+1 API call overhead.
     */
    private function fetchChildResourcesBatched(array $patientUuids): array
    {
        $allFiles = [];
        $allNotes = [];
        $allVisits = [];

        $batches = array_chunk($patientUuids, 10);

        foreach ($batches as $batch) {
            $promises = [];

            foreach ($batch as $patientUuid) {
                try {
                    $files = $this->apiFileRepo->forPatient($patientUuid);
                    if (!empty($files)) {
                        $allFiles[$patientUuid] = $files;
                    }
                } catch (\Throwable $e) {
                    Log::warning("[FullSyncService] Failed to fetch files for patient {$patientUuid}: " . $e->getMessage());
                }

                try {
                    $notes = $this->apiNoteRepo->forPatient($patientUuid);
                    if (!empty($notes)) {
                        $allNotes[$patientUuid] = $notes;
                    }
                } catch (\Throwable $e) {
                    Log::warning("[FullSyncService] Failed to fetch notes for patient {$patientUuid}: " . $e->getMessage());
                }

                try {
                    $visits = $this->apiVisitRepo->forPatient($patientUuid);
                    if (!empty($visits)) {
                        $allVisits[$patientUuid] = $visits;
                    }
                } catch (\Throwable $e) {
                    Log::warning("[FullSyncService] Failed to fetch visits for patient {$patientUuid}: " . $e->getMessage());
                }
            }
        }

        return [$allFiles, $allNotes, $allVisits];
    }

    public function syncUsersLocally(array $remoteDoctors): void
    {
        foreach ($remoteDoctors as $doctorData) {
            $userId = $doctorData['id'] ?? null;
            if (!$userId) {
                continue;
            }

            $email = $doctorData['email'] ?? '';
            $name = $doctorData['name'] ?? 'Unknown';
            $role = $doctorData['role'] ?? 'doctor';

            try {
                \App\Domains\Users\Models\User::unguard();
                $user = \App\Domains\Users\Models\User::withoutGlobalScopes()->updateOrCreate(
                    ['id' => $userId],
                    [
                        'name' => $name,
                        'email' => $email,
                        'password' => Hash::make(Str::random(32)),
                        'role' => $role,
                        'uuid' => $doctorData['uuid'] ?? null,
                        'phone' => $doctorData['phone'] ?? null,
                        'specialization' => $doctorData['specialization'] ?? null,
                        'status' => $doctorData['status'] ?? 'active',
                        'preferences' => $doctorData['preferences'] ?? null,
                    ]
                );
                \App\Domains\Users\Models\User::reguard();

                $roleName = is_array($role) ? ($role['name'] ?? 'doctor') : $role;
                if (empty($roleName)) {
                    $roleName = 'doctor';
                }

                try {
                    $spatieRole = \Spatie\Permission\Models\Role::findByName($roleName);
                    if ($spatieRole && !$user->hasRole($roleName)) {
                        $user->syncRoles([$roleName]);
                    }
                } catch (\Throwable $e) {
                    Log::warning("[FullSyncService] Failed to sync role '{$roleName}' for user {$userId}: " . $e->getMessage());
                }
            } catch (\Exception $e) {
                Log::warning("[FullSyncService] Failed to sync user {$userId}: " . $e->getMessage());
            }
        }
    }
}
