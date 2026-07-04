<?php

namespace App\Repositories\Hybrid;

use App\Contracts\Repositories\PatientFileRepositoryInterface;
use App\Models\PendingOperation;
use App\Repositories\Api\ApiPatientFileRepository;
use App\Repositories\Eloquent\EloquentPatientFileRepository;
use App\Services\NetworkStatusService;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Log;

class HybridPatientFileRepository implements PatientFileRepositoryInterface
{
    public function __construct(
        private ApiPatientFileRepository $apiRepo,
        private EloquentPatientFileRepository $localRepo
    ) {}

    private function syncLocalCache(array $data): void
    {
        if (isset($data['uuid']) && !is_array($data['uuid'])) {
            $data = [$data];
        }

        foreach ($data as $item) {
            if (is_array($item) && isset($item['uuid'])) {
                $cleanData = \Illuminate\Support\Arr::except($item, ['id', 'patient', 'creator']);
                try {
                    \App\Domains\Media\Models\PatientFile::updateOrCreate(
                    ['uuid' => $item['uuid']],
                    $cleanData
                );
                } catch (\Exception $e) {
                    \Illuminate\Support\Facades\Log::warning("Failed to sync local cache in " . basename("app/Repositories/Hybrid/HybridPatientFileRepository.php") . ": " . $e->getMessage());
                }
            }
        }
    }

    public function forPatient(string $patientUuid): array
    {
        if (NetworkStatusService::isOnline()) {
            try {
                $data = $this->apiRepo->forPatient($patientUuid);
                $this->syncLocalCache($data);
                return $data;
            } catch (ConnectionException $e) {
                NetworkStatusService::setOnline(false);
                Log::warning('[HybridPatientFileRepo] forPatient() - API unavailable: ' . $e->getMessage());
            } catch (\Throwable $e) {
                Log::warning('[HybridPatientFileRepo] forPatient() - API error: ' . $e->getMessage());
                NetworkStatusService::setOnline(false);
            }
        }
        return $this->localRepo->forPatient($patientUuid);
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
                Log::warning('[HybridPatientFileRepo] find() - API unavailable: ' . $e->getMessage());
            } catch (\Throwable $e) {
                Log::warning('[HybridPatientFileRepo] find() - API error: ' . $e->getMessage());
                NetworkStatusService::setOnline(false);
            }
        }
        return $this->localRepo->find($uuid);
    }

    public function upload(string $patientUuid, array $file, array $data = []): array
    {
        $localData = $this->localRepo->upload($patientUuid, $file, $data);

        if (NetworkStatusService::isOnline()) {
            try {
                return $this->apiRepo->upload($patientUuid, $file, $data);
            } catch (ConnectionException $e) {
                NetworkStatusService::setOnline(false);
                Log::warning('[HybridPatientFileRepo] upload() - API unavailable: ' . $e->getMessage());
            } catch (\Throwable $e) {
                Log::warning('[HybridPatientFileRepo] upload() - API error: ' . $e->getMessage());
                NetworkStatusService::setOnline(false);
            }
        }

        PendingOperation::create([
            'uuid' => $localData['uuid'] ?? \Illuminate\Support\Str::uuid()->toString(),
            'entity_type' => 'PatientFile',
            'action' => 'create',
            'payload' => array_merge($data, ['patient_uuid' => $patientUuid, 'file' => $file]),
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
                Log::warning('[HybridPatientFileRepo] delete() - API unavailable: ' . $e->getMessage());
            } catch (\Throwable $e) {
                Log::warning('[HybridPatientFileRepo] delete() - API error: ' . $e->getMessage());
                NetworkStatusService::setOnline(false);
            }
        }

        PendingOperation::create([
            'uuid' => $uuid,
            'entity_type' => 'PatientFile',
            'action' => 'delete',
            'payload' => null,
        ]);
    }

    public function byCategory(string $patientUuid, string $categorySlug): array
    {
        if (NetworkStatusService::isOnline()) {
            try {
                $data = $this->apiRepo->byCategory($patientUuid, $categorySlug);
                $this->syncLocalCache($data);
                return $data;
            } catch (ConnectionException $e) {
                NetworkStatusService::setOnline(false);
                Log::warning('[HybridPatientFileRepo] byCategory() - API unavailable: ' . $e->getMessage());
            } catch (\Throwable $e) {
                Log::warning('[HybridPatientFileRepo] byCategory() - API error: ' . $e->getMessage());
                NetworkStatusService::setOnline(false);
            }
        }
        return $this->localRepo->byCategory($patientUuid, $categorySlug);
    }
}
