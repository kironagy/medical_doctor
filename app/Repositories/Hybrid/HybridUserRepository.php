<?php

namespace App\Repositories\Hybrid;

use App\Contracts\Repositories\UserRepositoryInterface;
use App\Models\PendingOperation;
use App\Repositories\Api\ApiUserRepository;
use App\Repositories\Eloquent\EloquentUserRepository;
use App\Services\NetworkStatusService;
use Illuminate\Support\Facades\Log;

class HybridUserRepository implements UserRepositoryInterface
{
    public function __construct(
        private ApiUserRepository $apiRepo,
        private EloquentUserRepository $localRepo
    ) {}

    public function all(): array
    {
        if (NetworkStatusService::isOnline()) {
            try {
                return $this->apiRepo->all();
            } catch (\Exception $e) {
                NetworkStatusService::setOnline(false);
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
        if (!$result) throw new \RuntimeException('User not found.');
        return $result;
    }

    public function create(array $data): array
    {
        $localData = $this->localRepo->create($data);

        if (NetworkStatusService::isOnline()) {
            try {
                $data['uuid'] = $localData['uuid'];
                return $this->apiRepo->create($data);
            } catch (\Exception $e) {
                NetworkStatusService::setOnline(false);
            }
        }

        PendingOperation::create([
            'uuid' => $localData['uuid'],
            'entity_type' => 'User',
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
            'entity_type' => 'User',
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
            'entity_type' => 'User',
            'action' => 'delete',
            'payload' => null,
        ]);
    }

    public function doctors(): array
    {
        if (NetworkStatusService::isOnline()) {
            try {
                return $this->apiRepo->doctors();
            } catch (\Exception $e) {
                NetworkStatusService::setOnline(false);
            }
        }
        return $this->localRepo->doctors();
    }
}
