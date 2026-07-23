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
        // Attempt to sync any pending patients (created offline) to the server.
        // This ensures patients with sync_status='pending_create' get uploaded
        // automatically when connectivity returns, even if the user never opens
        // the sidebar paginated list. Called from workspace load (index).
        try {
            $this->syncPendingPatients();
        } catch (\Throwable $e) {
            // Silently ignore — will retry on next call
        }

        $data = $this->local->all();
        return array_values(array_filter($data, fn($p) => ($p['sync_status'] ?? 'synced') !== 'pending_delete'));
    }

    public function paginated(int $perPage = 10, int $page = 1, ?string $status = null): array
    {
        try {
            $this->syncPendingPatients();

            $data = $this->api->paginated($perPage, $page, $status);
            if (isset($data['data'])) {
                $this->syncLocalCache($data['data']);
            }
            return $data;
        } catch (\Throwable $e) {
            Log::info('[PatientRepo] paginated() - API unavailable, using local: ' . $e->getMessage());
        }

        return $this->local->paginated($perPage, $page, $status);
    }

    /**
     * Sync pending patients (sync_status = pending_create) to the remote server.
     * Called automatically by paginated() when the API is reachable.
     * This fixes Bug 5 — the processing icon never disappears.
     */
    private function syncPendingPatients(): void
    {
        $pendingPatients = \App\Domains\Patients\Models\Patient::where('sync_status', 'pending_create')->get();

        foreach ($pendingPatients as $patient) {
            try {
                $data = $patient->toArray();
                unset($data['id'], $data['sync_status'], $data['client_updated_at'], $data['deleted_at']);

                $apiData = $this->api->create($data);
                $this->syncSingleToLocal($apiData, force: true);

                Log::info('[PatientRepo] Synced pending patient to server', [
                    'local_uuid' => $patient->uuid,
                    'remote_uuid' => $apiData['uuid'] ?? 'unknown',
                ]);
            } catch (\Throwable $e) {
                Log::info('[PatientRepo] Failed to sync pending patient (offline): ' . $e->getMessage());
                break;
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
        $apiPayload = $data;
        $data['sync_status'] = 'pending_create';
        $data['client_updated_at'] = now();

        try {
            // Save to local SQLite FIRST. Wrapped inside try/catch to ensure
            // that if the local save fails (FK constraint, missing column, etc.),
            // the error is caught and the user gets a meaningful response.
            $localData = $this->local->create($data);

            $apiPayload['uuid'] = $localData['uuid'];
            $apiData = $this->api->create($apiPayload);
            $this->syncSingleToLocal($apiData, force: true);
            return $apiData;
        } catch (\Throwable $e) {
            Log::info('[PatientRepo] create() - saved locally (offline or error): ' . $e->getMessage());
        }

        return $localData ?? [];
    }

    public function update(string $uuid, array $data): array
    {
        $apiPayload = $data;

        $data['sync_status'] = 'pending_update';
        $data['client_updated_at'] = now();
        $localData = $this->local->update($uuid, $data);

        try {
            $apiData = $this->api->update($uuid, $apiPayload);
            $this->syncSingleToLocal($apiData, force: true);
            return $apiData;
        } catch (\Throwable $e) {
            Log::info('[PatientRepo] update() - offline, saved locally with pending_update: ' . $e->getMessage());
        }

        return $localData;
    }

    public function delete(string $uuid): void
    {
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

    private function syncSingleToLocal(array $data, bool $force = false): void
    {
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
                $this->syncSingleToLocal($item);
            }
        }
    }
}
