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

    public function syncAll(): void
    {
        $this->syncPendingOperations();

        Log::info('[FullSyncService] Starting full database synchronization...');

        try {
            DB::statement('PRAGMA foreign_keys = OFF');

            // Step A: Sync doctors FIRST so FK references from patients -> users work.
            // Must use ApiUserRepository to fetch remote data.
            Log::info('[FullSyncService] Syncing users...');
            $remoteDoctors = $this->apiUserRepo->doctors();
            $this->syncUsersLocally($remoteDoctors);
            Log::info('[FullSyncService] Users sync completed. ' . count($remoteDoctors) . ' doctors.');

        // Step B: Pull all patients from remote API and cache them locally
        Log::info('[FullSyncService] Syncing patients...');
        $patients = $this->apiPatientRepo->all();
        $uuids_before = collect($patients)->map(fn($p) => ($p['uuid'] ?? '?') . ':' . ($p['name'] ?? '?') . ':' . ($p['code'] ?? '?'))->toArray();
        Log::channel('single')->info('[PATIENT_DEBUG] FullSyncService::syncAll() - patients from remote', [
            'remote_count' => count($patients),
            'uuids' => $uuids_before,
        ]);
        $this->syncLocalCache($patients, Patient::class);
        Log::info('[FullSyncService] Synchronized ' . count($patients) . ' patients.');

            // Step C: Sync child resources for each patient
            foreach ($patients as $p) {
                if (empty($p['uuid'])) {
                    continue;
                }

                try {
                    $files = $this->apiFileRepo->forPatient($p['uuid']);
                    $notes = $this->apiNoteRepo->forPatient($p['uuid']);
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

        // Post-sync verification: count patients in local SQLite
        $localCount = Patient::count();
        $localAll = Patient::latest()->get();
        $localUuids = $localAll->map(fn($p) => $p->uuid . ':' . $p->name . ':' . $p->code)->toArray();
        Log::channel('single')->info('[PATIENT_DEBUG] FullSyncService::syncAll() - LOCAL SQLite after sync', [
            'local_count' => $localCount,
            'local_uuids' => $localUuids,
        ]);
        Log::info('[FullSyncService] Full database synchronization completed successfully.');
    } catch (\Throwable $e) {
            Log::error('[FullSyncService] Full database synchronization failed: ' . $e->getMessage());
        } finally {
            try {
                DB::statement('PRAGMA foreign_keys = ON');
            } catch (\Throwable $e) {
                // Ignore
            }
        }
    }

    private function pushQueueItem(SyncQueueItem $item): void
    {
        $payload = $item->payload ?? [];

        match ($item->entity) {
            'Patient' => $this->pushPatientToRemote($item, $payload),
            'PatientVisit' => $this->pushVisitToRemote($item, $payload),
            'PatientNote' => $this->pushNoteToRemote($item, $payload),
            default => Log::warning("[FullSyncService] Unsupported queue entity: {$item->entity}"),
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

    private function syncLocalCache(array $records, string $modelClass): void
    {
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
                $query->updateOrCreate(
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
