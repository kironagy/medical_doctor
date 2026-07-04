<?php

namespace App\Repositories\Hybrid;

use App\Contracts\Repositories\PatientVisitRepositoryInterface;
use App\Models\PendingOperation;
use App\Repositories\Api\ApiPatientVisitRepository;
use App\Repositories\Eloquent\EloquentPatientVisitRepository;
use App\Services\NetworkStatusService;

class HybridPatientVisitRepository implements PatientVisitRepositoryInterface
{
    public function __construct(
        private ApiPatientVisitRepository $apiRepo,
        private EloquentPatientVisitRepository $localRepo
    ) {}

    private function syncLocalCache(array $data): void
    {
        if (isset($data['id']) && !is_array($data['id'])) {
            $data = [$data];
        }

        foreach ($data as $item) {
            if (is_array($item) && isset($item['id'])) {
                $cleanData = \Illuminate\Support\Arr::except($item, ['doctor', 'patient']);
                try {
                    \App\Domains\Patients\Models\PatientVisit::updateOrCreate(
                    ['id' => $item['id']],
                    $cleanData
                );
                } catch (\Exception $e) {
                    \Illuminate\Support\Facades\Log::warning("Failed to sync local cache in " . basename("app/Repositories/Hybrid/HybridPatientVisitRepository.php") . ": " . $e->getMessage());
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
            } catch (\Illuminate\Http\Client\ConnectionException $e) {
                NetworkStatusService::setOnline(false);
            }
        }
        return $this->localRepo->forPatient($patientUuid);
    }

    public function create(string $patientUuid, array $data): array
    {
        $localData = $this->localRepo->create($patientUuid, $data);

        if (NetworkStatusService::isOnline()) {
            try {
                return $this->apiRepo->create($patientUuid, $data);
            } catch (\Illuminate\Http\Client\ConnectionException $e) {
                NetworkStatusService::setOnline(false);
            }
        }

        PendingOperation::create([
            'uuid' => $localData['uuid'] ?? \Illuminate\Support\Str::uuid()->toString(),
            'entity_type' => 'PatientVisit',
            'action' => 'create',
            'payload' => array_merge($data, ['patient_uuid' => $patientUuid]),
        ]);

        return $localData;
    }

    public function update(int $visitId, array $data): array
    {
        $localData = $this->localRepo->update($visitId, $data);

        if (NetworkStatusService::isOnline()) {
            try {
                return $this->apiRepo->update($visitId, $data);
            } catch (\Illuminate\Http\Client\ConnectionException $e) {
                NetworkStatusService::setOnline(false);
            }
        }

        PendingOperation::create([
            'uuid' => (string) $visitId, // Using ID as UUID for queueing
            'entity_type' => 'PatientVisit',
            'action' => 'update',
            'payload' => $data,
        ]);

        return $localData;
    }

    public function delete(int $visitId): void
    {
        $this->localRepo->delete($visitId);

        if (NetworkStatusService::isOnline()) {
            try {
                $this->apiRepo->delete($visitId);
                return;
            } catch (\Illuminate\Http\Client\ConnectionException $e) {
                NetworkStatusService::setOnline(false);
            }
        }

        PendingOperation::create([
            'uuid' => (string) $visitId,
            'entity_type' => 'PatientVisit',
            'action' => 'delete',
            'payload' => null,
        ]);
    }
}
