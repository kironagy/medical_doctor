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

    private function syncLocalCache(array $data): void
    {
        if (isset($data['uuid']) && !is_array($data['uuid'])) {
            $data = [$data];
        }

        foreach ($data as $item) {
            if (is_array($item) && isset($item['uuid'])) {
                $cleanData = \Illuminate\Support\Arr::except($item, [
                    'id', 'primary_doctor', 'visits', 'shares', 'files', 'notes'
                ]);
                \App\Domains\Patients\Models\Patient::updateOrCreate(
                    ['uuid' => $item['uuid']],
                    $cleanData
                );
            }
        }
    }

    public function all(): array
    {
        if (NetworkStatusService::isOnline()) {
            try {
                $data = $this->apiRepo->all();
                $this->syncLocalCache($data);
                return $data;
            } catch (\Illuminate\Http\Client\ConnectionException $e) {
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
                $data = $this->apiRepo->find($uuid);
                if ($data) $this->syncLocalCache($data);
                return $data;
            } catch (\Illuminate\Http\Client\ConnectionException $e) {
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
            } catch (\Illuminate\Http\Client\ConnectionException $e) {
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
            } catch (\Illuminate\Http\Client\ConnectionException $e) {
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
            } catch (\Illuminate\Http\Client\ConnectionException $e) {
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
                $data = $this->apiRepo->search($term);
                $this->syncLocalCache($data);
                return $data;
            } catch (\Illuminate\Http\Client\ConnectionException $e) {
                NetworkStatusService::setOnline(false);
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
            } catch (\Illuminate\Http\Client\ConnectionException $e) {
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
            } catch (\Illuminate\Http\Client\ConnectionException $e) {
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
            } catch (\Illuminate\Http\Client\ConnectionException $e) {
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
            } catch (\Illuminate\Http\Client\ConnectionException $e) {
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
            } catch (\Illuminate\Http\Client\ConnectionException $e) {
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
            } catch (\Illuminate\Http\Client\ConnectionException $e) {
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
