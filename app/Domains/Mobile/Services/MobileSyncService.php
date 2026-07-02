<?php

namespace App\Domains\Mobile\Services;

use App\Domains\Patients\Models\Patient;
use App\Domains\Patients\Models\PatientVisit;
use App\Domains\Patients\Models\PatientNote;
use App\Domains\Patients\Models\PatientShare;
use App\Domains\Media\Models\PatientFile;
use App\Domains\Media\Models\FileCategory;
use App\Domains\Users\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class MobileSyncService
{
    protected ProductionApiService $api;
    protected ?string $lastSyncAt = null;

    public function __construct()
    {
        Log::channel('mobile-api')->info('=== MOBILE SYNC SERVICE INITIALIZED ===');
        $this->api = new ProductionApiService;
        $this->lastSyncAt = Cache::get('mobile_last_sync_at');
    }

    public function setToken(string $token): self
    {
        Log::channel('mobile-api')->info('MOBILE SYNC: Setting authentication token');
        $this->api->setToken($token);
        return $this;
    }

    public function getLastSyncAt(): ?string
    {
        return $this->lastSyncAt;
    }

    public function authenticate(string $email, string $password): ?string
    {
        Log::channel('mobile-api')->info('=== MOBILE SYNC: AUTHENTICATION START ===', ['email' => $email]);

        $result = $this->api->login($email, $password, 'nativephp-android');

        if (!$result || !isset($result['token'])) {
            Log::channel('mobile-api')->error('MOBILE SYNC: AUTHENTICATION FAILED');
            return null;
        }

        $token = $result['token'];
        $this->api->setToken($token);

        Cache::put('mobile_auth_user', $result['user'], now()->addDays(30));
        Cache::put('mobile_auth_token', encrypt($token), now()->addDays(30));
        Log::channel('mobile-api')->info('MOBILE SYNC: AUTHENTICATION SUCCESS, TOKEN STORED');

        // After login, get /me and sync immediately
        try {
            $this->api->syncStatus(); // Verify token works
            $this->syncNow();
        } catch (\Exception $e) {
            Log::channel('mobile-api')->error('MOBILE SYNC: POST-LOGIN SYNC FAILED', ['error' => $e->getMessage()]);
        }

        return $token;
    }

    public static function getStoredToken(): ?string
    {
        Log::channel('mobile-api')->info('MOBILE SYNC: Retrieving stored token');
        $encrypted = Cache::get('mobile_auth_token');
        if (!$encrypted) return null;
        try {
            $token = decrypt($encrypted);
            Log::channel('mobile-api')->info('MOBILE SYNC: Token retrieved successfully');
            return $token;
        } catch (\Exception $e) {
            Log::channel('mobile-api')->error('MOBILE SYNC: Token decryption failed', ['error' => $e->getMessage()]);
            Cache::forget('mobile_auth_token');
            return null;
        }
    }

    public static function getStoredUser(): ?array
    {
        Log::channel('mobile-api')->info('MOBILE SYNC: Retrieving stored user');
        return Cache::get('mobile_auth_user');
    }

    public static function clearAuth(): void
    {
        Log::channel('mobile-api')->info('MOBILE SYNC: Clearing authentication data');
        Cache::forget('mobile_auth_token');
        Cache::forget('mobile_auth_user');
        Cache::forget('mobile_last_sync_at');
    }

    public function isOnline(): bool
    {
        return $this->api->isOnline();
    }

    public function syncNow(): array
    {
        Log::channel('mobile-api')->info('=== MOBILE SYNC: SYNC START ===');
        $startTime = microtime(true);
        $results = ['pulled' => 0, 'pushed' => 0, 'errors' => []];

        try {
            if (!$this->api->isOnline()) {
                Log::channel('mobile-api')->error('MOBILE SYNC: No internet connection');
                return ['error' => 'No internet connection'];
            }

            $token = static::getStoredToken();
            if (!$token) {
                Log::channel('mobile-api')->error('MOBILE SYNC: Not authenticated');
                return ['error' => 'Not authenticated'];
            }

            $this->api->setToken($token);

            Log::channel('mobile-api')->info('MOBILE SYNC: Gathering local changes');
            $localChanges = $this->gatherLocalChanges();

            if (!empty($localChanges['patients']) || !empty($localChanges['visits']) ||
                !empty($localChanges['notes']) || !empty($localChanges['shares'])) {
                Log::channel('mobile-api')->info('MOBILE SYNC: Pushing changes to server', ['changes' => array_keys(array_filter($localChanges))]);
                $pushResult = $this->api->push(
                    $localChanges['patients'],
                    $localChanges['visits'],
                    $localChanges['notes'],
                    $localChanges['shares']
                );

                if ($pushResult) {
                    $results['pushed'] = count($localChanges['patients']) + count($localChanges['visits']) +
                                         count($localChanges['notes']) + count($localChanges['shares']);
                    $results['push_results'] = $pushResult['results'] ?? [];
                    Log::channel('mobile-api')->info('MOBILE SYNC: PUSH SUCCESS', ['records_pushed' => $results['pushed']]);
                } else {
                    Log::channel('mobile-api')->error('MOBILE SYNC: PUSH FAILED');
                }
            } else {
                Log::channel('mobile-api')->info('MOBILE SYNC: No local changes to push');
            }

            Log::channel('mobile-api')->info('MOBILE SYNC: Pulling changes from server');
            $pullResult = $this->api->pull($this->lastSyncAt);

            if ($pullResult && isset($pullResult['data'])) {
                $applied = $this->applyRemoteChanges($pullResult['data']);
                $results['pulled'] = $applied;
                Log::channel('mobile-api')->info('MOBILE SYNC: PULL SUCCESS', ['records_pulled' => $applied]);

                if (isset($pullResult['server_time'])) {
                    $this->lastSyncAt = $pullResult['server_time'];
                    Cache::put('mobile_last_sync_at', $this->lastSyncAt, now()->addDays(7));
                    Log::channel('mobile-api')->info('MOBILE SYNC: Last sync time updated');
                }
            } else {
                Log::channel('mobile-api')->error('MOBILE SYNC: PULL FAILED');
            }

            Cache::put('mobile_last_sync_result', $results, now()->addDay());

        } catch (\Exception $e) {
            Log::channel('mobile-api')->error('MOBILE SYNC: SYNC FAILED', ['error' => $e->getMessage(), 'file' => $e->getFile(), 'line' => $e->getLine()]);
            $results['errors'][] = $e->getMessage();
        }

        $endTime = microtime(true);
        Log::channel('mobile-api')->info('=== MOBILE SYNC: SYNC COMPLETE ===', ['execution_time' => round(($endTime - $startTime) * 1000, 2) . 'ms']);
        return $results;
    }

    protected function gatherLocalChanges(): array
    {
        Log::channel('mobile-api')->info('MOBILE SYNC: GATHER LOCAL CHANGES START');
        $changes = ['patients' => [], 'visits' => [], 'notes' => [], 'shares' => []];

        if (!$this->lastSyncAt) {
            Log::channel('mobile-api')->info('MOBILE SYNC: No last sync time, skipping local changes');
            return $changes;
        }

        // Get user from stored auth instead of request()
        $storedUser = self::getStoredUser();
        if (!$storedUser) {
            Log::channel('mobile-api')->info('MOBILE SYNC: No stored user, skipping local changes');
            return $changes;
        }

        // Get local user by UUID or email
        $localUser = User::where('uuid', $storedUser['uuid'] ?? null)
                        ->orWhere('email', $storedUser['email'])
                        ->first();

        if (!$localUser) {
            Log::channel('mobile-api')->info('MOBILE SYNC: No local user found, skipping local changes');
            return $changes;
        }

        $userId = $localUser->id;

        Log::channel('mobile-api')->info('MOBILE SYNC: Gathering changes for user', ['user_id' => $userId]);

        Patient::where('primary_doctor_id', $userId)
            ->withTrashed()
            ->where(function ($q) {
                $q->where('updated_at', '>', $this->lastSyncAt)
                  ->orWhere('client_updated_at', '>', $this->lastSyncAt);
            })
            ->each(function ($patient) use (&$changes) {
                $data = $patient->toArray();
                $data['_deleted'] = $patient->trashed();
                $changes['patients'][] = $data;
            });

        Log::channel('mobile-api')->info('MOBILE SYNC: GATHER LOCAL CHANGES COMPLETE', ['counts' => array_map('count', $changes)]);
        return $changes;
    }

    protected function applyRemoteChanges(array $data): int
    {
        Log::channel('mobile-api')->info('MOBILE SYNC: APPLY REMOTE CHANGES START');
        $count = 0;

        foreach ($data as $entity => $items) {
            if (empty($items)) continue;
            Log::channel('mobile-api')->info('MOBILE SYNC: Processing entity', ['entity' => $entity, 'count' => count($items)]);

            foreach ($items as $item) {
                try {
                    $this->upsertLocal($entity, $item);
                    $count++;
                } catch (\Exception $e) {
                    Log::channel('mobile-api')->warning("MOBILE SYNC: Failed to upsert local {$entity}", ['uuid' => $item['uuid'] ?? 'unknown', 'error' => $e->getMessage()]);
                }
            }
        }

        Log::channel('mobile-api')->info('MOBILE SYNC: APPLY REMOTE CHANGES COMPLETE', ['records_applied' => $count]);
        return $count;
    }

    protected function upsertLocal(string $entity, array $data): void
    {
        match ($entity) {
            'patients' => $this->upsertLocalPatient($data),
            'visits' => $this->upsertLocalVisit($data),
            'notes' => $this->upsertLocalNote($data),
            'shares' => $this->upsertLocalShare($data),
            'categories' => $this->upsertLocalCategory($data),
            'files' => $this->upsertLocalFile($data),
            'doctors' => $this->upsertLocalDoctor($data),
            default => null,
        };
    }

    protected function upsertLocalPatient(array $data): void
    {
        $patient = Patient::withTrashed()->where('uuid', $data['uuid'])->first();

        if ($patient) {
            if (!empty($data['deleted_at'])) {
                if (!$patient->trashed()) $patient->delete();
                return;
            }
            if (empty($data['deleted_at']) && $patient->trashed()) {
                $patient->restore();
            }
            $patient->update(collect($data)->except(['uuid', 'primary_doctor_id', 'id', 'visits', 'notes', 'files', 'shares', 'deleted_at', '_sync_action'])->toArray());
        } else {
            Patient::create(collect($data)->except(['visits', 'notes', 'files', 'shares', 'deleted_at', '_sync_action'])->toArray());
        }
    }

    protected function upsertLocalVisit(array $data): void
    {
        $patient = Patient::where('uuid', $data['patient_uuid'] ?? $data['patient_id'])->first();
        if (!$patient) return;

        $visit = PatientVisit::withTrashed()->where('uuid', $data['uuid'])->first();

        if ($visit) {
            if (!empty($data['deleted_at'])) {
                if (!$visit->trashed()) $visit->delete();
                return;
            }
            if (empty($data['deleted_at']) && $visit->trashed()) {
                $visit->restore();
            }
            $visit->update(collect($data)->except(['uuid', 'patient_id', 'patient_uuid', 'id', 'deleted_at', '_sync_action'])->toArray());
        } else {
            PatientVisit::create(array_merge(
                collect($data)->except(['patient_uuid', 'id', 'deleted_at', '_sync_action'])->toArray(),
                ['patient_id' => $patient->id]
            ));
        }
    }

    protected function upsertLocalNote(array $data): void
    {
        $patient = Patient::where('uuid', $data['patient_uuid'] ?? $data['patient_id'])->first();
        if (!$patient) return;

        $note = PatientNote::withTrashed()->where('uuid', $data['uuid'])->first();

        if ($note) {
            if (!empty($data['deleted_at'])) {
                if (!$note->trashed()) $note->delete();
                return;
            }
            if (empty($data['deleted_at']) && $note->trashed()) {
                $note->restore();
            }
            $note->update(collect($data)->except(['uuid', 'patient_id', 'patient_uuid', 'id', 'deleted_at', '_sync_action'])->toArray());
        } else {
            PatientNote::create(array_merge(
                collect($data)->except(['patient_uuid', 'id', 'deleted_at', '_sync_action'])->toArray(),
                ['patient_id' => $patient->id]
            ));
        }
    }

    protected function upsertLocalShare(array $data): void
    {
        $patient = Patient::where('uuid', $data['patient_uuid'] ?? $data['patient_id'])->first();
        if (!$patient) return;

        $share = PatientShare::withTrashed()->where('uuid', $data['uuid'])->first();

        if ($share) {
            if (!empty($data['deleted_at'])) {
                if (!$share->trashed()) $share->delete();
                return;
            }
            $share->update(collect($data)->except(['uuid', 'patient_id', 'patient_uuid', 'doctor_uuid', 'doctor_id', 'shared_by_uuid', 'id', 'deleted_at', '_sync_action'])->toArray());
        } else {
            $doctorId = null;
            if (!empty($data['doctor_uuid'])) {
                $doctorId = User::where('uuid', $data['doctor_uuid'])->value('id');
            }
            PatientShare::create(array_merge(
                collect($data)->except(['patient_uuid', 'doctor_uuid', 'shared_by_uuid', 'id', 'deleted_at', '_sync_action'])->toArray(),
                ['patient_id' => $patient->id, 'doctor_id' => $doctorId]
            ));
        }
    }

    protected function upsertLocalFile(array $data): void
    {
        $patient = Patient::where('uuid', $data['patient_uuid'] ?? $data['patient_id'])->first();
        if (!$patient) return;

        $file = PatientFile::withTrashed()->where('uuid', $data['uuid'])->first();

        if ($file) {
            if (!empty($data['deleted_at'])) {
                if (!$file->trashed()) $file->delete();
                return;
            }
            if (empty($data['deleted_at']) && $file->trashed()) {
                $file->restore();
            }
            $file->update(collect($data)->except(['uuid', 'patient_id', 'patient_uuid', 'id', 'url', 'thumbnail_url', 'name', 'extension', 'deleted_at', '_sync_action'])->toArray());
        } else {
            PatientFile::create(array_merge(
                collect($data)->except(['patient_uuid', 'id', 'url', 'thumbnail_url', 'name', 'extension', 'deleted_at', '_sync_action'])->toArray(),
                ['patient_id' => $patient->id]
            ));
        }
    }

    protected function upsertLocalCategory(array $data): void
    {
        $category = FileCategory::withTrashed()->where('uuid', $data['uuid'])->first();

        if ($category) {
            if (!empty($data['deleted_at'])) {
                if (!$category->trashed()) $category->delete();
                return;
            }
            if (empty($data['deleted_at']) && $category->trashed()) {
                $category->restore();
            }
            $category->update(collect($data)->except(['uuid', 'id', 'deleted_at', '_sync_action'])->toArray());
        } else {
            FileCategory::create(collect($data)->except(['id', 'deleted_at', '_sync_action'])->toArray());
        }
    }

    protected function upsertLocalDoctor(array $data): void
    {
        $doctor = User::where('uuid', $data['uuid'])->first();

        if ($doctor) {
            $doctor->update(collect($data)->except(['uuid', 'id', 'avatar_url'])->toArray());
        } else {
            User::create(collect($data)->except(['id', 'avatar_url'])->toArray());
        }
    }
}
