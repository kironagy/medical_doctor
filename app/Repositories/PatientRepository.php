<?php

namespace App\Repositories;

use App\Contracts\Repositories\PatientRepositoryInterface;
use App\Repositories\Api\ApiPatientRepository;
use App\Repositories\Eloquent\EloquentPatientRepository;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Log;

class PatientRepository implements PatientRepositoryInterface
{
    public function __construct(
        private ApiPatientRepository $api,
        private EloquentPatientRepository $local,
    ) {}

    public function all(): array
    {
        // NOTE: Sync is handled exclusively by SyncEngineService.
        // We do NOT call syncPendingPatients() here because that would
        // create a race condition with the dedicated sync engine.
        // The dedicated sync engine handles patient-first, files-second
        // ordered synchronization with proper atomicity.

        $data = $this->local->all();
        return array_values(array_filter($data, fn($p) => ($p['sync_status'] ?? 'synced') !== 'pending_delete'));
    }

    public function paginated(int $perPage = 10, int $page = 1, ?string $status = null): array
    {
        // NOTE: Sync is handled exclusively by SyncEngineService.
        // We do NOT call syncPendingPatients() here because that would
        // create a race condition with the dedicated sync engine.
        // The dedicated sync engine handles patient-first, files-second
        // ordered synchronization with proper atomicity.

        try {
            $data = $this->api->paginated($perPage, $page, $status);
            if (isset($data['data'])) {
                $this->syncLocalCache($data['data']);

                // ── Merge local pending patients not yet in API response ─────
                // Patients that are still pending_create/pending_update locally
                // won't be in the API response yet. We merge them so the frontend
                // can still display them even while waiting for sync.
                $localPending = \App\Domains\Patients\Models\Patient::whereIn(
                    'sync_status', ['pending_create', 'pending_update']
                )->get()->toArray();

                if (!empty($localPending)) {
                    $apiUuids = collect($data['data'])->pluck('uuid')->toArray();
                    foreach ($localPending as $pendingPatient) {
                        if (!in_array($pendingPatient['uuid'], $apiUuids)) {
                            $data['data'][] = $pendingPatient;
                        }
                    }
                    // Re-sort by latest to maintain consistent ordering
                    usort($data['data'], fn($a, $b) => strcmp(
                        $b['created_at'] ?? $b['client_updated_at'] ?? '',
                        $a['created_at'] ?? $a['client_updated_at'] ?? ''
                    ));
                    Log::info('[PatientRepo] paginated() - merged ' . count($localPending) . ' local pending patients into response');
                }
            }
            return $data;
        } catch (\Throwable $e) {
            Log::info('[PatientRepo] paginated() - API unavailable, using local: ' . $e->getMessage());
        }

        $localResult = $this->local->paginated($perPage, $page, $status);
        $count = count($localResult['data'] ?? []);
        $firstUuid = $localResult['data'][0]['uuid'] ?? 'none';
        $hasPendingCreate = collect($localResult['data'] ?? [])->contains(fn($p) => ($p['sync_status'] ?? '') === 'pending_create');
        Log::info('[DIAG] PatientRepo::paginated() returning ' . $count . ' patients, first uuid: ' . $firstUuid . ', has_pending_create: ' . ($hasPendingCreate ? 'YES' : 'NO'));
        return $localResult;
    }

    /**
     * Sync all pending local patients to the remote server.
     * Made public so it can be triggered from the frontend via
     * POST /_native/api/sync/patients before fetching the online patient list.
     */
    public function syncPending(): void
    {
        $this->syncPendingPatients();
    }

    /**
     * Create a patient on the remote API only (no local save).
     * Used by SyncEngineService for ordered sync operations.
     */
    public function createOnRemote(array $data): array
    {
        return $this->api->create($data);
    }

    /**
     * Update a patient on the remote API only (no local save).
     * Used by SyncEngineService for ordered sync operations.
     */
    public function updateOnRemote(string $uuid, array $data): array
    {
        return $this->api->update($uuid, $data);
    }

    /**
     * Delete a patient on the remote API only (no local delete).
     * Used by SyncEngineService for ordered sync operations.
     */
    public function deleteOnRemote(string $uuid): void
    {
        $this->api->delete($uuid);
    }

    /**
     * Sync a single remote patient record to the local SQLite database.
     * Made public for use by SyncEngineService.
     *
     * @param  array   $data   Patient data from the remote API
     * @param  bool    $force  If true, overwrites even pending local changes
     */
    public function syncSingleToLocal(array $data, bool $force = false): void
    {
        $this->doSyncSingleToLocal($data, $force);
    }

    private function syncPendingPatients(): void
    {
        $pendingPatients = \App\Domains\Patients\Models\Patient::whereIn('sync_status', ['pending_create', 'pending_update'])->get();

        foreach ($pendingPatients as $patient) {
            try {
                $data = $patient->toArray();
                unset($data['id'], $data['sync_status'], $data['client_updated_at'], $data['deleted_at']);

                if ($patient->sync_status === 'pending_create') {
                    $apiData = $this->api->create($data);
                    $remoteUuid = $apiData['uuid'] ?? 'unknown';
                } else {
                    $apiData = $this->api->update($patient->uuid, $data);
                    $remoteUuid = $apiData['uuid'] ?? $patient->uuid;
                }

                $this->doSyncSingleToLocal($apiData, force: true);

                Log::info('[PatientRepo] Synced pending patient to server', [
                    'local_uuid' => $patient->uuid,
                    'remote_uuid' => $remoteUuid,
                    'sync_status' => $patient->sync_status,
                ]);
            } catch (\Throwable $e) {
                Log::info('[PatientRepo] Failed to sync patient (' . $patient->sync_status . '): ' . $e->getMessage());
                // Continue to next patient — don't break! Remaining patients may still sync.
            }
        }
    }

    public function find(string $uuid): ?array
    {
        $data = $this->local->find($uuid);
        if ($data && ($data['sync_status'] ?? 'synced') === 'pending_delete') {
            return null;
        }
        return $data;
    }

    public function findByUuid(string $uuid): array
    {
        $result = $this->find($uuid);
        if (!$result) throw new \RuntimeException('Patient not found.');
        return $result;
    }

    public function create(array $data): array
    {
        $tf = '/data/local/tmp/np_traces.txt';

        try {
            @file_put_contents($tf, now()->format('H:i:s.v') . ' P6c calling remote API FIRST' . "\n", FILE_APPEND | LOCK_EX);
            $apiData = $this->api->create($data);
            @file_put_contents($tf, now()->format('H:i:s.v') . ' P6d remote SUCCESS uuid=' . ($apiData['uuid'] ?? '?') . "\n", FILE_APPEND | LOCK_EX);
            $this->doSyncSingleToLocal($apiData, force: true);
            return $apiData;
        } catch (\Illuminate\Http\Client\ConnectionException $e) {
            @file_put_contents($tf, now()->format('H:i:s.v') . ' P6e CONNECTION FAILED (offline): ' . $e->getMessage() . "\n", FILE_APPEND | LOCK_EX);
            Log::info('[PatientRepo] create() - offline, saving locally: ' . $e->getMessage());
        } catch (\Throwable $e) {
            @file_put_contents($tf, now()->format('H:i:s.v') . ' P6e API FAILED: ' . get_class($e) . ': ' . $e->getMessage() . "\n", FILE_APPEND | LOCK_EX);
            Log::warning('[PatientRepo] create() - API error, saving locally: ' . $e->getMessage());
        }

        @file_put_contents($tf, now()->format('H:i:s.v') . ' P6f saving locally as pending_create' . "\n", FILE_APPEND | LOCK_EX);
        $data['sync_status'] = 'pending_create';
        $data['client_updated_at'] = now();
        $localData = $this->local->create($data);
        @file_put_contents($tf, now()->format('H:i:s.v') . ' P6g local create uuid=' . ($localData['uuid'] ?? 'NONE') . "\n", FILE_APPEND | LOCK_EX);
        return $localData;
    }

    public function update(string $uuid, array $data): array
    {
        try {
            $apiData = $this->api->update($uuid, $data);
            $this->doSyncSingleToLocal($apiData, force: true);
            return $apiData;
        } catch (\Illuminate\Http\Client\ConnectionException $e) {
            Log::info('[PatientRepo] update() - offline, saving locally: ' . $e->getMessage());
        } catch (\Throwable $e) {
            Log::warning('[PatientRepo] update() - API error, saving locally: ' . $e->getMessage());
        }

        $data['sync_status'] = 'pending_update';
        $data['client_updated_at'] = now();
        return $this->local->update($uuid, $data);
    }

    public function delete(string $uuid): void
    {
        $patient = \App\Domains\Patients\Models\Patient::where('uuid', $uuid)->first();

        if ($patient && $patient->sync_status === 'pending_create') {
            $patient->forceDelete();
            return;
        }

        $this->local->update($uuid, ['sync_status' => 'pending_delete', 'client_updated_at' => now()]);
        $this->local->delete($uuid);

        try {
            $this->api->delete($uuid);
        } catch (\Throwable $e) {
            Log::info('[PatientRepo] delete() - offline, soft-deleted with pending_delete: ' . $e->getMessage());
        }
    }

    public function search(string $term): array
    {
        try {
            $data = $this->api->search($term);
            $this->syncLocalCache($data);
            return $data;
        } catch (\Throwable $e) {
            Log::info('[PatientRepo] search() - API unavailable, using local: ' . $e->getMessage());
        }

        return $this->local->search($term);
    }

    public function shared(int $userId): array
    {
        try {
            return $this->api->shared($userId);
        } catch (\Throwable $e) {
            Log::info('[PatientRepo] shared() - API unavailable, using local: ' . $e->getMessage());
        }

        return $this->local->shared($userId);
    }

    public function stats(): array
    {
        try {
            return $this->api->stats();
        } catch (\Throwable $e) {
            Log::info('[PatientRepo] stats() - API unavailable, using local: ' . $e->getMessage());
        }

        return $this->local->stats();
    }

    public function recent(int $limit): array
    {
        try {
            $data = $this->api->recent($limit);
            $this->syncLocalCache($data);
            return $data;
        } catch (\Throwable $e) {
            Log::info('[PatientRepo] recent() - API unavailable, using local: ' . $e->getMessage());
        }

        return $this->local->recent($limit);
    }

    public function withTrashed(): array
    {
        try {
            $data = $this->api->withTrashed();
            $this->syncLocalCache($data);
            return $data;
        } catch (\Throwable $e) {
            Log::info('[PatientRepo] withTrashed() - API unavailable, using local: ' . $e->getMessage());
        }

        return $this->local->withTrashed();
    }

    public function restore(string $uuid): void
    {
        $this->local->restore($uuid);
        $this->local->update($uuid, ['sync_status' => 'pending_update', 'client_updated_at' => now()]);

        try {
            $this->api->restore($uuid);
        } catch (\Throwable $e) {
            Log::info('[PatientRepo] restore() - offline, restored locally: ' . $e->getMessage());
        }
    }

    public function forceDelete(string $uuid): void
    {
        try {
            $this->api->forceDelete($uuid);
            $this->local->forceDelete($uuid);
            return;
        } catch (\Throwable $e) {
            Log::info('[PatientRepo] forceDelete() - offline, marking as pending_delete: ' . $e->getMessage());
        }

        $this->local->update($uuid, ['sync_status' => 'pending_delete', 'client_updated_at' => now()]);
        $this->local->delete($uuid);
    }

    private function doSyncSingleToLocal(array $data, bool $force = false): void
    {
        // ── 🔥 FIX: Handle API response wrapped in 'data' key ───────────
        // The remote API may return the patient wrapped in a 'data' key
        // (e.g., ['data' => ['uuid' => '...', 'name' => '...']])
        // while other repos return the patient directly.
        if (!isset($data['uuid']) && isset($data['data']['uuid'])) {
            $data = $data['data'];
        }

        if (!isset($data['uuid'])) return;

        if (!$force) {
            $localRecord = \App\Domains\Patients\Models\Patient::where('uuid', $data['uuid'])->first();
            if ($localRecord && $localRecord->sync_status !== 'synced') {
                return;
            }
        }

        $cleanData = \Illuminate\Support\Arr::except($data, [
            'id', 'primary_doctor', 'visits', 'shares', 'files', 'notes'
        ]);
        $cleanData['sync_status'] = 'synced';

        try {
            \App\Domains\Patients\Models\Patient::unguard();
            \App\Domains\Patients\Models\Patient::updateOrCreate(
                ['uuid' => $data['uuid']],
                $cleanData
            );
            \App\Domains\Patients\Models\Patient::reguard();
        } catch (\Exception $e) {
            \App\Domains\Patients\Models\Patient::reguard();
            Log::warning('[PatientRepo] Failed to sync local cache: ' . $e->getMessage());
        }
    }

    private function syncLocalCache(array $data): void
    {
        foreach ($data as $item) {
            if (is_array($item) && isset($item['uuid'])) {
                $this->doSyncSingleToLocal($item);
            }
        }
    }
}
