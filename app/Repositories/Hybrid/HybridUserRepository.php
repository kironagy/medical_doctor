<?php

namespace App\Repositories\Hybrid;

use App\Contracts\Repositories\UserRepositoryInterface;
use App\Models\PendingOperation;
use App\Repositories\Api\ApiUserRepository;
use App\Repositories\Eloquent\EloquentUserRepository;
use App\Services\NetworkStatusService;

class HybridUserRepository implements UserRepositoryInterface
{
    public function __construct(
        private ApiUserRepository $apiRepo,
        private EloquentUserRepository $localRepo
    ) {}

    public function find(int $id): ?array
    {
        if (NetworkStatusService::isOnline()) {
            try {
                return $this->apiRepo->find($id);
            } catch (\Exception $e) {
                NetworkStatusService::setOnline(false);
            }
        }
        return $this->localRepo->find($id);
    }

    public function update(int $id, array $data): array
    {
        $localData = $this->localRepo->update($id, $data);

        if (NetworkStatusService::isOnline()) {
            try {
                return $this->apiRepo->update($id, $data);
            } catch (\Exception $e) {
                NetworkStatusService::setOnline(false);
            }
        }

        PendingOperation::create([
            'uuid' => (string) $id,
            'entity_type' => 'User',
            'action' => 'update',
            'payload' => $data,
        ]);

        return $localData;
    }

    public function updatePassword(int $id, string $password): void
    {
        $this->localRepo->updatePassword($id, $password);

        if (NetworkStatusService::isOnline()) {
            try {
                $this->apiRepo->updatePassword($id, $password);
                return;
            } catch (\Exception $e) {
                NetworkStatusService::setOnline(false);
            }
        }

        PendingOperation::create([
            'uuid' => (string) $id,
            'entity_type' => 'User',
            'action' => 'updatePassword',
            'payload' => ['password' => $password],
        ]);
    }

    public function updatePreferences(int $id, array $preferences): void
    {
        $this->localRepo->updatePreferences($id, $preferences);

        if (NetworkStatusService::isOnline()) {
            try {
                $this->apiRepo->updatePreferences($id, $preferences);
                return;
            } catch (\Exception $e) {
                NetworkStatusService::setOnline(false);
            }
        }

        PendingOperation::create([
            'uuid' => (string) $id,
            'entity_type' => 'User',
            'action' => 'updatePreferences',
            'payload' => $preferences,
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

    public function searchDoctors(string $term): array
    {
        if (NetworkStatusService::isOnline()) {
            try {
                return $this->apiRepo->searchDoctors($term);
            } catch (\Exception $e) {
                NetworkStatusService::setOnline(false);
            }
        }
        return $this->localRepo->searchDoctors($term);
    }
}
