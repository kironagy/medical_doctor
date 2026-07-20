<?php

namespace App\Repositories\Hybrid;

use App\Contracts\Repositories\PatientRepositoryInterface;
use App\Domains\Patients\Models\Patient;
use App\Models\PendingOperation;
use App\Repositories\Api\ApiPatientRepository;
use App\Repositories\Eloquent\EloquentPatientRepository;
use App\Services\NetworkStatusService;
use App\Services\SyncQueueService;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Log;

class HybridPatientRepository implements PatientRepositoryInterface
{
    public function __construct(
        private ApiPatientRepository $apiRepo,
        private EloquentPatientRepository $localRepo,
        private SyncQueueService $syncQueue,
    ) {}

    /**
     * Sync remote API patient data into the local SQLite cache.
     * Uses last-write-wins with device priority (local wins on tie).
     */
    private function syncLocalCache(array $data): void
    {
        if (isset($data['uuid']) && !is_array($data['uuid'])) {
            $data = [$data];
        }

        foreach ($data as $item) {
            if (!is_array($item) || !isset($item['uuid'])) continue;

            $cleanData = \Illuminate\Support\Arr::except($item, [
                'id', 'primary_doctor', 'visits', 'shares', 'files', 'notes'
            ]);

            try {
                Patient::unguard();
                // Simple updateOrCreate — no conflict resolution, server is source of truth
                // for bulk sync operations. Local-only pending changes are tracked in sync_queue.
                Patient::withoutGlobalScopes()->updateOrCreate(
                    ['uuid' => $item['uuid']],
                    $cleanData
                );
                Patient::reguard();
            } catch (\Exception $e) {
                Patient::reguard();
                Log::warning('[HybridPatientRepo] syncLocalCache failed for ' . ($item['uuid'] ?? '?' ) . ': ' . $e->getMessage());
            }
        }
    }

    public function all(): array
    {
        $start = microtime(true);
        $data = null;
        $source = 'local';

        if (NetworkStatusService::isOnline()) {
            try {
                $data = $this->apiRepo->all();
                $source = 'api';
                if (is_array($data) && count($data) > 0) {
                    $this->syncLocalCache($data);
                }
            } catch (\Throwable $e) {
                NetworkStatusService::setOnline(false);
                Log::warning('[HybridPatientRepo] all() - API error, falling back to local: ' . $e->getMessage());
                $source = 'local_fallback';
                $data = null;
            }
        }

        if ($data === null) {
            $data = $this->localRepo->all();
        }

        return $data ?: [];
    }

    public function find(string $uuid): ?array
    {
        if (NetworkStatusService::isOnline()) {
            try {
                $data = $this->apiRepo->find($uuid);
                if ($data) {
                    $this->syncLocalCache($data);
                    return $data;
                }
            } catch (\Throwable $e) {
                NetworkStatusService::setOnline(false);
                Log::warning('[HybridPatientRepo] find() - API error: ' . $e->getMessage());
            }
        }
        return $this->localRepo->find($uuid);
    }

    public function findByUuid(string $uuid): array
    {
        $result = $this->find($uuid);
        if (!$result) throw new \RuntimeException('Patient not found.');
        return $result;
    }

    public function create(array $data): array
    {
        // 1. Save to local SQLite immediately (offline-first)
        $localData = $this->localRepo->create($data);
        $localUuid = $localData['uuid'] ?? null;

        if (NetworkStatusService::isOnline() && $localUuid) {
            try {
                // 2. Send to API with same UUID to avoid duplication
                $data['uuid'] = $localUuid;
                $apiData = $this->apiRepo->create($data);

                // 3. Merge API response (server may enrich with additional fields)
                if (is_array($apiData) && isset($apiData['uuid'])) {
                    $this->syncLocalCache($apiData);
                    return $apiData;
                }
            } catch (\Illuminate\Validation\ValidationException $e) {
                // Validation error: remove local record and rethrow
                if ($localUuid) {
                    Patient::where('uuid', $localUuid)->forceDelete();
                }
                throw $e;
            } catch (\Throwable $e) {
                NetworkStatusService::setOnline(false);
                Log::warning('[HybridPatientRepo] create() - API failed, queuing offline: ' . $e->getMessage());
            }
        }

        // Offline: queue for next sync
        if ($localUuid) {
            $this->syncQueue->enqueueOperation('Patient', 'create', $localUuid, $localData);
        }

        return $localData;
    }

    public function update(string $uuid, array $data): array
    {
        $localData = $this->localRepo->update($uuid, $data);

        if (NetworkStatusService::isOnline()) {
            try {
                $apiData = $this->apiRepo->update($uuid, $data);
                if (is_array($apiData) && isset($apiData['uuid'])) {
                    $this->syncLocalCache($apiData);
                    return $apiData;
                }
            } catch (\Throwable $e) {
                NetworkStatusService::setOnline(false);
                Log::warning('[HybridPatientRepo] update() - API error: ' . $e->getMessage());
            }
        }

        $this->syncQueue->enqueueOperation('Patient', 'update', $uuid, $data);
        return $localData;
    }

    public function delete(string $uuid): void
    {
        $this->localRepo->delete($uuid);

        if (NetworkStatusService::isOnline()) {
            try {
                $this->apiRepo->delete($uuid);
                return;
            } catch (\Throwable $e) {
                NetworkStatusService::setOnline(false);
                Log::warning('[HybridPatientRepo] delete() - API error: ' . $e->getMessage());
            }
        }

        $this->syncQueue->enqueueOperation('Patient', 'delete', $uuid, null);
    }

    public function search(string $term): array
    {
        if (NetworkStatusService::isOnline()) {
            try {
                $data = $this->apiRepo->search($term);
                $this->syncLocalCache($data);
                return $data;
            } catch (\Throwable $e) {
                NetworkStatusService::setOnline(false);
                Log::warning('[HybridPatientRepo] search() - API error: ' . $e->getMessage());
            }
        }
        return $this->localRepo->search($term);
    }

    public function shared(int $userId): array
    {
        if (NetworkStatusService::isOnline()) {
            try {
                $data = $this->apiRepo->shared($userId);
                $this->syncLocalCache($data);
                return $data;
            } catch (\Throwable $e) {
                NetworkStatusService::setOnline(false);
                Log::warning('[HybridPatientRepo] shared() - API error: ' . $e->getMessage());
            }
        }
        return $this->localRepo->shared($userId);
    }

    public function stats(): array
    {
        if (NetworkStatusService::isOnline()) {
            try {
                return $this->apiRepo->stats();
            } catch (\Throwable $e) {
                NetworkStatusService::setOnline(false);
            }
        }
        return $this->localRepo->stats();
    }

    public function recent(int $limit): array
    {
        if (NetworkStatusService::isOnline()) {
            try {
                $data = $this->apiRepo->recent($limit);
                $this->syncLocalCache($data);
                return $data;
            } catch (\Throwable $e) {
                NetworkStatusService::setOnline(false);
            }
        }
        return $this->localRepo->recent($limit);
    }

    public function withTrashed(): array
    {
        if (NetworkStatusService::isOnline()) {
            try {
                $data = $this->apiRepo->withTrashed();
                $this->syncLocalCache($data);
                return $data;
            } catch (\Throwable $e) {
                NetworkStatusService::setOnline(false);
            }
        }
        return $this->localRepo->withTrashed();
    }

    public function paginated(int $perPage = 10, int $page = 1, ?string $status = null): array
    {
        $data = null;

        if (NetworkStatusService::isOnline()) {
            try {
                $data = $this->apiRepo->paginated($perPage, $page, $status);
                if (isset($data['data']) && count($data['data']) > 0) {
                    $this->syncLocalCache($data['data']);
                }
            } catch (\Throwable $e) {
                NetworkStatusService::setOnline(false);
                Log::warning('[HybridPatientRepo] paginated() - API error: ' . $e->getMessage());
                $data = null;
            }
        }

        if ($data === null) {
            $data = $this->localRepo->paginated($perPage, $page, $status);
        }

        return $data;
    }

    public function restore(string $uuid): void
    {
        $this->localRepo->restore($uuid);

        if (NetworkStatusService::isOnline()) {
            try {
                $this->apiRepo->restore($uuid);
                return;
            } catch (\Throwable $e) {
                NetworkStatusService::setOnline(false);
                Log::warning('[HybridPatientRepo] restore() - API error: ' . $e->getMessage());
            }
        }

        $this->syncQueue->enqueueOperation('Patient', 'restore', $uuid, null);
    }

    public function forceDelete(string $uuid): void
    {
        $this->localRepo->forceDelete($uuid);

        if (NetworkStatusService::isOnline()) {
            try {
                $this->apiRepo->forceDelete($uuid);
                return;
            } catch (\Throwable $e) {
                NetworkStatusService::setOnline(false);
                Log::warning('[HybridPatientRepo] forceDelete() - API error: ' . $e->getMessage());
            }
        }

        $this->syncQueue->enqueueOperation('Patient', 'forceDelete', $uuid, null);
    }
}
