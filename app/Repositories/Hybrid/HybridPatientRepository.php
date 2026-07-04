<?php

namespace App\Repositories\Hybrid;

use App\Contracts\Repositories\PatientRepositoryInterface;
use App\Models\PendingOperation;
use App\Repositories\Api\ApiPatientRepository;
use App\Repositories\Eloquent\EloquentPatientRepository;
use App\Services\NetworkStatusService;
use Illuminate\Http\Client\ConnectionException;
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
                // Conflict resolution: skip if local SQLite record has newer changes
                $localRecord = \App\Domains\Patients\Models\Patient::where('uuid', $item['uuid'])->first();
                if ($localRecord) {
                    $localTime = $localRecord->client_updated_at ?? $localRecord->updated_at;
                    $serverTime = isset($item['updated_at']) ? new \Carbon\Carbon($item['updated_at']) : null;
                    if ($serverTime && $localTime && $localTime->gt($serverTime)) {
                        Log::info("Conflict detected for Patient {$item['uuid']}: device has newer changes. Keeping local.");
                        continue;
                    }
                }

                $cleanData = \Illuminate\Support\Arr::except($item, [
                    'primary_doctor', 'visits', 'shares', 'files', 'notes'
                ]);
                try {
                    \App\Domains\Patients\Models\Patient::unguard();
                    \App\Domains\Patients\Models\Patient::updateOrCreate(
                        ['uuid' => $item['uuid']],
                        $cleanData
                    );
                    \App\Domains\Patients\Models\Patient::reguard();
                } catch (\Exception $e) {
                    \App\Domains\Patients\Models\Patient::reguard();
                    \Illuminate\Support\Facades\Log::warning("Failed to sync local cache in " . basename("app/Repositories/Hybrid/HybridPatientRepository.php") . ": " . $e->getMessage());
                }
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
            } catch (ConnectionException $e) {
                NetworkStatusService::setOnline(false);
                Log::warning('[HybridPatientRepo] all() - API unavailable, falling back to local: ' . $e->getMessage());
            } catch (\Throwable $e) {
                Log::warning('[HybridPatientRepo] all() - API error, falling back to local: ' . $e->getMessage());
                NetworkStatusService::setOnline(false);
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
            } catch (ConnectionException $e) {
                NetworkStatusService::setOnline(false);
                Log::warning('[HybridPatientRepo] find() - API unavailable, falling back to local: ' . $e->getMessage());
            } catch (\Throwable $e) {
                Log::warning('[HybridPatientRepo] find() - API error, falling back to local: ' . $e->getMessage());
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
            } catch (\Illuminate\Validation\ValidationException $e) {
                // If the remote server rejected the data as invalid, let the UI show the error!
                throw $e;
            } catch (\Exception $e) {
                // Any other API error (500, network, etc) means we fallback to offline mode
                NetworkStatusService::setOnline(false);
                Log::warning('Create failed online, queueing offline operation. Error: ' . $e->getMessage());
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
            } catch (ConnectionException $e) {
                NetworkStatusService::setOnline(false);
                Log::warning('[HybridPatientRepo] update() - API unavailable: ' . $e->getMessage());
            } catch (\Throwable $e) {
                Log::warning('[HybridPatientRepo] update() - API error: ' . $e->getMessage());
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
            } catch (ConnectionException $e) {
                NetworkStatusService::setOnline(false);
                Log::warning('[HybridPatientRepo] delete() - API unavailable: ' . $e->getMessage());
            } catch (\Throwable $e) {
                Log::warning('[HybridPatientRepo] delete() - API error: ' . $e->getMessage());
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
            } catch (ConnectionException $e) {
                NetworkStatusService::setOnline(false);
                Log::warning('[HybridPatientRepo] search() - API unavailable: ' . $e->getMessage());
            } catch (\Throwable $e) {
                Log::warning('[HybridPatientRepo] search() - API error: ' . $e->getMessage());
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
            } catch (ConnectionException $e) {
                NetworkStatusService::setOnline(false);
                Log::warning('[HybridPatientRepo] shared() - API unavailable: ' . $e->getMessage());
            } catch (\Throwable $e) {
                Log::warning('[HybridPatientRepo] shared() - API error: ' . $e->getMessage());
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
            } catch (ConnectionException $e) {
                NetworkStatusService::setOnline(false);
                Log::warning('[HybridPatientRepo] stats() - API unavailable: ' . $e->getMessage());
            } catch (\Throwable $e) {
                Log::warning('[HybridPatientRepo] stats() - API error: ' . $e->getMessage());
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
            } catch (ConnectionException $e) {
                NetworkStatusService::setOnline(false);
                Log::warning('[HybridPatientRepo] recent() - API unavailable: ' . $e->getMessage());
            } catch (\Throwable $e) {
                Log::warning('[HybridPatientRepo] recent() - API error: ' . $e->getMessage());
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
            } catch (ConnectionException $e) {
                NetworkStatusService::setOnline(false);
                Log::warning('[HybridPatientRepo] withTrashed() - API unavailable: ' . $e->getMessage());
            } catch (\Throwable $e) {
                Log::warning('[HybridPatientRepo] withTrashed() - API error: ' . $e->getMessage());
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
            } catch (ConnectionException $e) {
                NetworkStatusService::setOnline(false);
                Log::warning('[HybridPatientRepo] restore() - API unavailable: ' . $e->getMessage());
            } catch (\Throwable $e) {
                Log::warning('[HybridPatientRepo] restore() - API error: ' . $e->getMessage());
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
            } catch (ConnectionException $e) {
                NetworkStatusService::setOnline(false);
                Log::warning('[HybridPatientRepo] forceDelete() - API unavailable: ' . $e->getMessage());
            } catch (\Throwable $e) {
                Log::warning('[HybridPatientRepo] forceDelete() - API error: ' . $e->getMessage());
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
