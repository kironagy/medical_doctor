<?php

namespace App\Repositories\Hybrid;

use App\Contracts\Repositories\PatientVisitRepositoryInterface;
use App\Models\PendingOperation;
use App\Repositories\Api\ApiPatientVisitRepository;
use App\Repositories\Eloquent\EloquentPatientVisitRepository;
use App\Services\NetworkStatusService;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Log;

class HybridPatientVisitRepository implements PatientVisitRepositoryInterface
{
    public function __construct(
        private ApiPatientVisitRepository $apiRepo,
        private EloquentPatientVisitRepository $localRepo
    ) {}

    private function syncLocalCache(array $data): void
    {
        if (isset($data['uuid']) && !is_array($data['uuid'])) {
            $data = [$data];
        }

        foreach ($data as $item) {
            if (is_array($item) && isset($item['uuid'])) {
                // Conflict resolution: skip if local SQLite record has newer changes
                $localRecord = \App\Domains\Patients\Models\PatientVisit::where('uuid', $item['uuid'])->first();
                if ($localRecord) {
                    $localTime = $localRecord->client_updated_at ?? $localRecord->updated_at;
                    $serverTime = isset($item['updated_at']) ? new \Carbon\Carbon($item['updated_at']) : null;
                    if ($serverTime && $localTime && $localTime->gt($serverTime)) {
                        Log::info("Conflict detected for Visit {$item['uuid']}: device has newer changes. Keeping local.");
                        continue;
                    }
                }

        $cleanData = \Illuminate\Support\Arr::except($item, ['id', 'doctor', 'patient']);
        try {
            \App\Domains\Patients\Models\PatientVisit::withoutGlobalScopes()->updateOrCreate(
                ['uuid' => $item['uuid']],
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
            } catch (ConnectionException $e) {
                NetworkStatusService::setOnline(false);
                Log::warning('[HybridPatientVisitRepo] forPatient() - API unavailable: ' . $e->getMessage());
            } catch (\Throwable $e) {
                Log::warning('[HybridPatientVisitRepo] forPatient() - API error: ' . $e->getMessage());
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
            } catch (ConnectionException $e) {
                NetworkStatusService::setOnline(false);
                Log::warning('[HybridPatientVisitRepo] create() - API unavailable: ' . $e->getMessage());
            } catch (\Throwable $e) {
                Log::warning('[HybridPatientVisitRepo] create() - API error: ' . $e->getMessage());
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
            } catch (ConnectionException $e) {
                NetworkStatusService::setOnline(false);
                Log::warning('[HybridPatientVisitRepo] update() - API unavailable: ' . $e->getMessage());
            } catch (\Throwable $e) {
                Log::warning('[HybridPatientVisitRepo] update() - API error: ' . $e->getMessage());
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
            } catch (ConnectionException $e) {
                NetworkStatusService::setOnline(false);
                Log::warning('[HybridPatientVisitRepo] delete() - API unavailable: ' . $e->getMessage());
            } catch (\Throwable $e) {
                Log::warning('[HybridPatientVisitRepo] delete() - API error: ' . $e->getMessage());
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
