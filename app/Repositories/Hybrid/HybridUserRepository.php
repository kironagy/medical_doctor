<?php

namespace App\Repositories\Hybrid;

use App\Contracts\Repositories\UserRepositoryInterface;
use App\Repositories\Api\ApiUserRepository;
use App\Repositories\Eloquent\EloquentUserRepository;
use App\Services\NetworkStatusService;
use App\Services\SyncQueueService;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Log;

class HybridUserRepository implements UserRepositoryInterface
{
    public function __construct(
        private ApiUserRepository $apiRepo,
        private EloquentUserRepository $localRepo,
        private SyncQueueService $syncQueue
    ) {}

    private function syncLocalCache(array $data): void
    {
        if (isset($data['id']) && !is_array($data['id'])) {
            $data = [$data];
        }

        foreach ($data as $item) {
            if (is_array($item) && isset($item['id'])) {
                $cleanData = \Illuminate\Support\Arr::except($item, ['roles', 'permissions']);
                try {
                    \App\Domains\Users\Models\User::updateOrCreate(
                    ['id' => $item['id']],
                    $cleanData
                );
                } catch (\Exception $e) {
                    \Illuminate\Support\Facades\Log::warning("Failed to sync local cache in " . basename("app/Repositories/Hybrid/HybridUserRepository.php") . ": " . $e->getMessage());
                }
            }
        }
    }

    public function find(int $id): ?array
    {
        if (NetworkStatusService::isOnline()) {
            try {
                $data = $this->apiRepo->find($id);
                if ($data) $this->syncLocalCache($data);
                return $data;
            } catch (ConnectionException $e) {
                NetworkStatusService::setOnline(false);
                Log::warning('[HybridUserRepo] find() - API unavailable: ' . $e->getMessage());
            } catch (\Throwable $e) {
                Log::warning('[HybridUserRepo] find() - API error: ' . $e->getMessage());
                NetworkStatusService::handleThrowable($e);
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
            } catch (ConnectionException $e) {
                NetworkStatusService::setOnline(false);
                Log::warning('[HybridUserRepo] update() - API unavailable: ' . $e->getMessage());
            } catch (\Throwable $e) {
                Log::warning('[HybridUserRepo] update() - API error: ' . $e->getMessage());
                NetworkStatusService::handleThrowable($e);
            }
        }

        $this->syncQueue->enqueueOperation('User', 'update', (string) $id, $data);

        return $localData;
    }

    public function updatePassword(int $id, string $password): void
    {
        $this->localRepo->updatePassword($id, $password);

        if (NetworkStatusService::isOnline()) {
            try {
                $this->apiRepo->updatePassword($id, $password);
                return;
            } catch (ConnectionException $e) {
                NetworkStatusService::setOnline(false);
                Log::warning('[HybridUserRepo] updatePassword() - API unavailable: ' . $e->getMessage());
            } catch (\Throwable $e) {
                Log::warning('[HybridUserRepo] updatePassword() - API error: ' . $e->getMessage());
                NetworkStatusService::handleThrowable($e);
            }
        }

        $this->syncQueue->enqueueOperation('User', 'updatePassword', (string) $id, ['password' => $password]);
    }

    public function updatePreferences(int $id, array $preferences): void
    {
        $this->localRepo->updatePreferences($id, $preferences);

        if (NetworkStatusService::isOnline()) {
            try {
                $this->apiRepo->updatePreferences($id, $preferences);
                return;
            } catch (ConnectionException $e) {
                NetworkStatusService::setOnline(false);
                Log::warning('[HybridUserRepo] updatePreferences() - API unavailable: ' . $e->getMessage());
            } catch (\Throwable $e) {
                Log::warning('[HybridUserRepo] updatePreferences() - API error: ' . $e->getMessage());
                NetworkStatusService::handleThrowable($e);
            }
        }

        $this->syncQueue->enqueueOperation('User', 'updatePreferences', (string) $id, $preferences);
    }

    public function doctors(): array
    {
        if (NetworkStatusService::isOnline()) {
            try {
                $data = $this->apiRepo->doctors();
                $this->syncLocalCache($data);
                return $data;
            } catch (ConnectionException $e) {
                NetworkStatusService::setOnline(false);
                Log::warning('[HybridUserRepo] doctors() - API unavailable: ' . $e->getMessage());
            } catch (\Throwable $e) {
                Log::warning('[HybridUserRepo] doctors() - API error: ' . $e->getMessage());
                NetworkStatusService::handleThrowable($e);
            }
        }
        return $this->localRepo->doctors();
    }

    public function searchDoctors(string $term): array
    {
        if (NetworkStatusService::isOnline()) {
            try {
                $data = $this->apiRepo->searchDoctors($term);
                $this->syncLocalCache($data);
                return $data;
            } catch (ConnectionException $e) {
                NetworkStatusService::setOnline(false);
                Log::warning('[HybridUserRepo] searchDoctors() - API unavailable: ' . $e->getMessage());
            } catch (\Throwable $e) {
                Log::warning('[HybridUserRepo] searchDoctors() - API error: ' . $e->getMessage());
                NetworkStatusService::handleThrowable($e);
            }
        }
        return $this->localRepo->searchDoctors($term);
    }
}
