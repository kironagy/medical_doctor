<?php

namespace App\Repositories\Hybrid;

use App\Contracts\Repositories\PatientFileRepositoryInterface;
use App\Models\PendingOperation;
use App\Repositories\Api\ApiPatientFileRepository;
use App\Repositories\Eloquent\EloquentPatientFileRepository;
use App\Services\NetworkStatusService;

class HybridPatientFileRepository implements PatientFileRepositoryInterface
{
    public function __construct(
        private ApiPatientFileRepository $apiRepo,
        private EloquentPatientFileRepository $localRepo
    ) {}

    public function forPatient(string $patientUuid): array
    {
        if (NetworkStatusService::isOnline()) {
            try {
                return $this->apiRepo->forPatient($patientUuid);
            } catch (\Exception $e) {
                NetworkStatusService::setOnline(false);
            }
        }
        return $this->localRepo->forPatient($patientUuid);
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

    public function upload(string $patientUuid, array $file, array $data = []): array
    {
        $localData = $this->localRepo->upload($patientUuid, $file, $data);

        if (NetworkStatusService::isOnline()) {
            try {
                return $this->apiRepo->upload($patientUuid, $file, $data);
            } catch (\Exception $e) {
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
            } catch (\Exception $e) {
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
                return $this->apiRepo->byCategory($patientUuid, $categorySlug);
            } catch (\Exception $e) {
                NetworkStatusService::setOnline(false);
            }
        }
        return $this->localRepo->byCategory($patientUuid, $categorySlug);
    }
}
