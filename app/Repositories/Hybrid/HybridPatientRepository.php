<?php

namespace App\Repositories\Hybrid;

use App\Contracts\Repositories\PatientRepositoryInterface;
use App\Models\PendingOperation;
use App\Repositories\Api\ApiPatientRepository;
use App\Repositories\Eloquent\EloquentPatientRepository;
use App\Services\NetworkStatusService;
use Illuminate\Support\Facades\Log;

class HybridPatientRepository implements PatientRepositoryInterface
{
    public function __construct(
        private ApiPatientRepository $apiRepo,
        private EloquentPatientRepository $localRepo
    ) {}

    public function all(): array
    {
        if (NetworkStatusService::isOnline()) {
            try {
                $data = $this->apiRepo->all();
                // Sync to local cache in background (or fire & forget)
                // For simplicity, we can just update local SQLite if we had an upsert.
                // It's safer to rely on pull-to-refresh to fetch new items.
                return $data;
            } catch (\Exception $e) {
                NetworkStatusService::setOnline(false);
                Log::warning('Fallback to offline mode: ' . $e->getMessage());
            }
        }
        return $this->localRepo->all();
    }

    public function find(string $uuid): ?array
    {
        if (NetworkStatusService::isOnline()) {
            try {
                return $this->apiRepo->find($uuid);
            } catch (\Exception $e) {
                NetworkStatusService::setOnline(false);
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
        $localData = $this->localRepo->create($data); // Save to local SQLite cache

        if (NetworkStatusService::isOnline()) {
            try {
                // Ensure UUID is sent to API to avoid duplication
                $data['uuid'] = $localData['uuid'];
                return $this->apiRepo->create($data);
            } catch (\Exception $e) {
                NetworkStatusService::setOnline(false);
                Log::warning('Create failed online, queueing offline operation.');
            }
        }

        // Queue for sync
        PendingOperation::create([
            'uuid' => $localData['uuid'],
            'entity_type' => 'Patient',
            'action' => 'create',
            'payload' => $localData,
        ]);

        return $localData;
    }

    public function update(string $uuid, array $data): array
    {
        $localData = $this->localRepo->update($uuid, $data);

        if (NetworkStatusService::isOnline()) {
            try {
                return $this->apiRepo->update($uuid, $data);
            } catch (\Exception $e) {
                NetworkStatusService::setOnline(false);
            }
        }

        PendingOperation::create([
            'uuid' => $uuid,
            'entity_type' => 'Patient',
            'action' => 'update',
            'payload' => $data,
        ]);

        return $localData;
    }

    public function delete(string $uuid): void
    {
        $this->localRepo->delete($uuid);

        if (NetworkStatusService::isOnline()) {
            try {
                $this->apiRepo->delete($uuid);
                return;
            } catch (\Exception $e) {
                NetworkStatusService::setOnline(false);
            }
        }

        PendingOperation::create([
            'uuid' => $uuid,
            'entity_type' => 'Patient',
            'action' => 'delete',
            'payload' => null,
        ]);
    }

    public function search(string $term): array
    {
        if (NetworkStatusService::isOnline()) {
            try {
                return $this->apiRepo->search($term);
            } catch (\Exception $e) {
                NetworkStatusService::setOnline(false);
            }
        }
        return $this->localRepo->search($term);
    }

    public function shared(int $userId): array
    {
        if (NetworkStatusService::isOnline()) {
            try {
                return $this->apiRepo->shared($userId);
            } catch (\Exception $e) {
                NetworkStatusService::setOnline(false);
            }
        }
        return $this->localRepo->shared($userId);
    }

    public function stats(): array
    {
        if (NetworkStatusService::isOnline()) {
            try {
                return $this->apiRepo->stats();
            } catch (\Exception $e) {
                NetworkStatusService::setOnline(false);
            }
        }
        return $this->localRepo->stats();
    }

    public function recent(int $limit): array
    {
        if (NetworkStatusService::isOnline()) {
            try {
                return $this->apiRepo->recent($limit);
            } catch (\Exception $e) {
                NetworkStatusService::setOnline(false);
            }
        }
        return $this->localRepo->recent($limit);
    }

    public function withTrashed(): array
    {
        if (NetworkStatusService::isOnline()) {
            try {
                return $this->apiRepo->withTrashed();
            } catch (\Exception $e) {
                NetworkStatusService::setOnline(false);
            }
        }
        return $this->localRepo->withTrashed();
    }

    public function restore(string $uuid): void
    {
        $this->localRepo->restore($uuid);

        if (NetworkStatusService::isOnline()) {
            try {
                $this->apiRepo->restore($uuid);
                return;
            } catch (\Exception $e) {
                NetworkStatusService::setOnline(false);
            }
        }

        PendingOperation::create([
            'uuid' => $uuid,
            'entity_type' => 'Patient',
            'action' => 'restore',
            'payload' => null,
        ]);
    }

    public function forceDelete(string $uuid): void
    {
        $this->localRepo->forceDelete($uuid);

        if (NetworkStatusService::isOnline()) {
            try {
                $this->apiRepo->forceDelete($uuid);
                return;
            } catch (\Exception $e) {
                NetworkStatusService::setOnline(false);
            }
        }

        PendingOperation::create([
            'uuid' => $uuid,
            'entity_type' => 'Patient',
            'action' => 'forceDelete',
            'payload' => null,
        ]);
    }
}
