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
use App\Services\SyncQueueService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class FullSyncService
{
    /** Semaphore to prevent concurrent sync operations */
    private static bool $syncInProgress = false;

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
     * Check if a sync operation is already in progress.
     */
    public static function isSyncInProgress(): bool
    {
        return self::$syncInProgress;
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
     * Lightweight metadata-only sync. Syncs patients, notes, visits, and file
     * METADATA (no binary downloads). This is the primary sync method called
     * by the frontend after the UI renders.
     *
     * File binaries are downloaded on-demand when the user opens a file.
     */
    /**
     * Unified field mapping: normalize API response fields to model fields.
     * Shared across all sync methods to ensure consistent data.
     */
    public static function normalizeFileRecord(array $record, ?string $patientUuid = null): array
    {
        $clean = \Illuminate\Support\Arr::except($record, ['id', 'patient', 'creator', 'uploader', 'url', 'thumbnail_url', 'description']);

        // Map description → desc (API response uses 'description', model uses 'desc')
        if (isset($record['description']) && !isset($clean['desc'])) {
            $clean['desc'] = $record['description'];
        }
        unset($clean['description']);

        // Resolve local patient_id from UUID
        if ($patientUuid) {
            try {
                $localPatient = \App\Domains\Patients\Models\Patient::withoutGlobalScopes()
                    ->where('uuid', $patientUuid)
                    ->first();
                if ($localPatient) {
                    $clean['patient_id'] = $localPatient->id;
                }
            } catch (\Throwable $e) {
                Log::warning("[FullSyncService] Cannot resolve local patient for UUID {$patientUuid}: " . $e->getMessage());
            }
        }

        // Ensure uploaded_by_id has a fallback
        if (empty($clean['uploaded_by_id'])) {
            if (isset($record['uploader']['id'])) {
                $clean['uploaded_by_id'] = $record['uploader']['id'];
            } else {
                try {
                    if (auth()->check()) {
                        $clean['uploaded_by_id'] = auth()->id();
                    }
                } catch (\Throwable $e) {
                    // Auth guard may not be available in console/queue context
                }
            }
        }

        // Generate a local file path if missing
        if (empty($clean['file_path']) && !empty($clean['file_name'])) {
            $resolvedUuid = $patientUuid ?? 'unknown';
            $clean['file_path'] = "patients/{$resolvedUuid}/{$clean['file_name']}";
        }

        return $clean;
    }

    public function syncMetadataOnly(): void
    {
        // Prevent concurrent sync operations (race condition fix)
        if (self::$syncInProgress) {
            Log::info('[FullSyncService] Sync already in progress, skipping duplicate call.');
            return;
        }
        self::$syncInProgress = true;

        Log::info('[FullSyncService] Starting lightweight metadata synchronization...');

        try {
            DB::statement('PRAGMA foreign_keys = OFF');

            // Step A: Push pending local changes first
            $this->syncPendingOperations();

            // Step B: Sync doctors
            Log::info('[FullSyncService] Syncing users...');
            try {
                $remoteDoctors = $this->apiUserRepo->doctors();
                $this->syncUsersLocally($remoteDoctors);
                Log::info('[FullSyncService] Users sync completed. ' . count($remoteDoctors) . ' doctors.');
            } catch (\Throwable $e) {
                Log::warning('[FullSyncService] Users sync failed: ' . $e->getMessage());
            }

            // Step C: Sync patients metadata
            Log::info('[FullSyncService] Syncing patients...');
            $patients = $this->apiPatientRepo->all();
            $this->syncLocalCache($patients, Patient::class);
            Log::info('[FullSyncService] Synchronized ' . count($patients) . ' patients.');

            // Step D: Sync child resources metadata only (no file binary downloads)
            $syncedFilesCount = 0;
            $syncedNotesCount = 0;
            $syncedVisitsCount = 0;
            $patientsWithFiles = 0;
            $patientsWithNotes = 0;
            $patientsWithVisits = 0;

            foreach ($patients as $p) {
                if (empty($p['uuid'])) {
                    continue;
                }

                try {
                    $files = $this->apiFileRepo->forPatient($p['uuid']);
                    if (!empty($files)) {
                        $patientsWithFiles++;
                        $syncedFilesCount += count($files);
                        // CRITICAL: Resolve local patient_id from UUID before saving
                        // The API returns the REMOTE patient_id, but local SQLite
                        // has different auto-increment IDs. Without this mapping,
                        // files would be orphaned — $patient->files() would return
                        // nothing because patient_files.patient_id wouldn't match
                        // patients.id locally.
                        $this->syncFilesWithLocalPatientId($p['uuid'], $files);
                    }

                    $notes = $this->apiNoteRepo->forPatient($p['uuid']);
                    if (!empty($notes)) {
                        $patientsWithNotes++;
                        $syncedNotesCount += count($notes);
                        $this->syncChildRecordsWithLocalPatientId($p['uuid'], $notes, PatientNote::class);
                    }

                    $visits = $this->apiVisitRepo->forPatient($p['uuid']);
                    if (!empty($visits)) {
                        $patientsWithVisits++;
                        $syncedVisitsCount += count($visits);
                        $this->syncChildRecordsWithLocalPatientId($p['uuid'], $visits, PatientVisit::class);
                    }
                } catch (\Throwable $e) {
                    Log::warning("[FullSyncService] Failed to sync child resources for patient {$p['uuid']}: " . $e->getMessage());
                }
            }

            Log::info("[FullSyncService] Metadata sync completed. Synced files: {$syncedFilesCount} (from {$patientsWithFiles} patients), notes: {$syncedNotesCount} (from {$patientsWithNotes} patients), visits: {$syncedVisitsCount} (from {$patientsWithVisits} patients).");

            $localCount = Patient::count();
            Log::info("[FullSyncService] {$localCount} patients in local SQLite.");
        } catch (\Throwable $e) {
            Log::error('[FullSyncService] Metadata synchronization failed: ' . $e->getMessage());
        } finally {
            self::$syncInProgress = false;
            try {
                DB::statement('PRAGMA foreign_keys = ON');
            } catch (\Throwable $e) {
                // Ignore
            }
        }
    }

    /**
     * Download a single file's binary from the remote API and cache it locally.
     * Used for on-demand file downloads when the user opens a file offline.
     */
    public function downloadFileBinary(string $fileUuid): bool
    {
        try {
            $file = PatientFile::withoutGlobalScopes()->where('uuid', $fileUuid)->first();
            if (!$file) {
                Log::warning('[FullSyncService] Cannot download binary: file not found: ' . $fileUuid);
                return false;
            }

            // If already cached locally, skip
            if ($file->is_cached_locally && $file->downloaded_at) {
                Log::info('[FullSyncService] File already cached locally: ' . $fileUuid);
                return true;
            }

            // Resolve the remote URL
            $remoteUrl = $file->remote_url;
            if (!$remoteUrl) {
                // Build URL from API base
                $apiUrl = config('app.mobile_api_url', 'https://prof-hosam-fekry.online/api/v1/mobile');
                $remoteUrl = $apiUrl . '/files/' . $fileUuid . '/stream';
            }

            // Get token for authenticated download
            $token = null;
            try {
                $token = app(\App\Services\Mobile\ApiService::class)->getToken();
            } catch (\Throwable $e) {}

            if (!$token) {
                Log::warning('[FullSyncService] Cannot download file binary: no API token');
                return false;
            }

            // Download the file
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

    /**
     * Full synchronization including file binary downloads.
     * Only called explicitly for full offline cache building.
     */
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
            if (! $localPath) {
                Log::warning("[FullSyncService] PatientFile create: no local_path for {$item->record_uuid}");
                return;
            }

            $fullPath = \Illuminate\Support\Facades\Storage::disk('local')->path($localPath);
            if (! file_exists($fullPath)) {
                Log::warning("[FullSyncService] PatientFile create: file not found at {$fullPath}");
                return;
            }

            // Use Http::attach() for multipart upload
            $apiUrl = config('app.mobile_api_url', 'https://prof-hosam-fekry.online/api/v1/mobile');
            $url = $apiUrl . '/patients/' . $patientUuid . '/files';

            // Get token from ApiService singleton (reads from session or DB)
            $token = null;
            try {
                $token = app(\App\Services\Mobile\ApiService::class)->getToken();
            } catch (\Throwable $e) {
                // Last resort: try plaintext session key
                try {
                    $token = session('api_token_raw');
                } catch (\Throwable $se) {}
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

            Log::info("[FullSyncService] Uploaded offline file {$fileName} for patient {$patientUuid}");

            // CRITICAL: The remote API creates a new UUID for the file.
            // We must update our local PatientFile record to use the remote
            // UUID, otherwise the next metadata sync pull will create a
            // DUPLICATE local record (matching on the old local UUID fails).
            $responseData = $response->json();
            $remoteFileUuid = $responseData['data']['uuid'] ?? null;
            if ($remoteFileUuid && $item->record_uuid && $remoteFileUuid !== $item->record_uuid) {
                try {
                    // Direct update to avoid firing any model events
                    PatientFile::where('uuid', $item->record_uuid)
                        ->update(['uuid' => $remoteFileUuid]);
                    Log::info("[FullSyncService] Updated local file UUID from {$item->record_uuid} to {$remoteFileUuid}");
                } catch (\Throwable $e) {
                    Log::warning("[FullSyncService] Failed to update local file UUID: " . $e->getMessage());
                }
            }
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
     * This is critical because the remote API returns the REMOTE patient_id,
     * but local SQLite has different auto-increment IDs. Without this mapping,
     * files appear orphaned.
     */
    private function syncFilesWithLocalPatientId(string $patientUuid, array $files): void
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

        $query = $modelClass::withoutGlobalScopes();

        foreach ($files as $record) {
            if (empty($record['uuid'])) {
                continue;
            }

            // Use unified field mapping helper — resolves patient_id from UUID
            $cleanRecord = self::normalizeFileRecord($record, $patientUuid);

            // Filter to valid columns only
            if (!empty($validColumns)) {
                $cleanRecord = array_intersect_key($cleanRecord, array_flip($validColumns));
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

                Log::warning(
                    "[FullSyncService] Failed to cache file {$record['uuid']}: " . $e->getMessage()
                );
            }
        }
    }

    /**
     * Sync child records (notes, visits) from API, resolving patient_id to local ID.
     */
    private function syncChildRecordsWithLocalPatientId(string $patientUuid, array $records, string $modelClass): void
    {
        // Resolve local patient ID
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

        $query = $modelClass::withoutGlobalScopes();

        foreach ($records as $record) {
            if (empty($record['uuid'])) {
                continue;
            }

            $cleanRecord = [];
            foreach ($record as $key => $value) {
                // Skip non-fillable complex fields
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

            // Use local patient_id
            if ($localPatientId) {
                $cleanRecord['patient_id'] = $localPatientId;
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

                Log::warning(
                    "[FullSyncService] Failed to cache " . class_basename($modelClass) .
                    " {$record['uuid']}: " . $e->getMessage()
                );
            }
        }
    }

    /**
     * Generic local cache sync (kept for backward compatibility).
     */
    private function syncLocalCache(array $records, string $modelClass): void
    {
        if ($modelClass === PatientFile::class || $modelClass === PatientNote::class || $modelClass === PatientVisit::class) {
            // These should use the specific methods with patient_id resolution.
            // The generic method will still work but won't resolve patient_id.
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

                Log::warning(
                    "[FullSyncService] Failed to cache " . class_basename($modelClass) .
                    " {$record['uuid']}: " . $e->getMessage()
                );
            }
        }
    }

    private function syncUsersLocally(array $remoteDoctors): void
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
