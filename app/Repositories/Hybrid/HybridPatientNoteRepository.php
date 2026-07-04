<?php

namespace App\Repositories\Hybrid;

use App\Contracts\Repositories\PatientNoteRepositoryInterface;
use App\Models\PendingOperation;
use App\Repositories\Api\ApiPatientNoteRepository;
use App\Repositories\Eloquent\EloquentPatientNoteRepository;
use App\Services\NetworkStatusService;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Log;

class HybridPatientNoteRepository implements PatientNoteRepositoryInterface
{
    public function __construct(
        private ApiPatientNoteRepository $apiRepo,
        private EloquentPatientNoteRepository $localRepo
    ) {}

    private function syncLocalCache(array $data): void
    {
        if (isset($data['uuid']) && !is_array($data['uuid'])) {
            $data = [$data];
        }

        foreach ($data as $item) {
            if (is_array($item) && isset($item['uuid'])) {
                $cleanData = \Illuminate\Support\Arr::except($item, ['id', 'patient', 'author']);
                try {
                    \App\Domains\Patients\Models\PatientNote::updateOrCreate(
                    ['uuid' => $item['uuid']],
                    $cleanData
                );
                } catch (\Exception $e) {
                    \Illuminate\Support\Facades\Log::warning("Failed to sync local cache in " . basename("app/Repositories/Hybrid/HybridPatientNoteRepository.php") . ": " . $e->getMessage());
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
                Log::warning('[HybridPatientNoteRepo] forPatient() - API unavailable: ' . $e->getMessage());
            } catch (\Throwable $e) {
                Log::warning('[HybridPatientNoteRepo] forPatient() - API error: ' . $e->getMessage());
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
                Log::warning('[HybridPatientNoteRepo] create() - API unavailable: ' . $e->getMessage());
            } catch (\Throwable $e) {
                Log::warning('[HybridPatientNoteRepo] create() - API error: ' . $e->getMessage());
                NetworkStatusService::setOnline(false);
            }
        }

        PendingOperation::create([
            'uuid' => $localData['uuid'] ?? \Illuminate\Support\Str::uuid()->toString(),
            'entity_type' => 'PatientNote',
            'action' => 'create',
            'payload' => array_merge($data, ['patient_uuid' => $patientUuid]),
        ]);

        return $localData;
    }

    public function update(string $patientUuid, string $noteUuid, array $data): array
    {
        $localData = $this->localRepo->update($patientUuid, $noteUuid, $data);

        if (NetworkStatusService::isOnline()) {
            try {
                return $this->apiRepo->update($patientUuid, $noteUuid, $data);
            } catch (ConnectionException $e) {
                NetworkStatusService::setOnline(false);
                Log::warning('[HybridPatientNoteRepo] update() - API unavailable: ' . $e->getMessage());
            } catch (\Throwable $e) {
                Log::warning('[HybridPatientNoteRepo] update() - API error: ' . $e->getMessage());
                NetworkStatusService::setOnline(false);
            }
        }

        PendingOperation::create([
            'uuid' => $noteUuid,
            'entity_type' => 'PatientNote',
            'action' => 'update',
            'payload' => array_merge($data, ['patient_uuid' => $patientUuid]),
        ]);

        return $localData;
    }

    public function delete(string $patientUuid, string $noteUuid): void
    {
        $this->localRepo->delete($patientUuid, $noteUuid);

        if (NetworkStatusService::isOnline()) {
            try {
                $this->apiRepo->delete($patientUuid, $noteUuid);
                return;
            } catch (ConnectionException $e) {
                NetworkStatusService::setOnline(false);
                Log::warning('[HybridPatientNoteRepo] delete() - API unavailable: ' . $e->getMessage());
            } catch (\Throwable $e) {
                Log::warning('[HybridPatientNoteRepo] delete() - API error: ' . $e->getMessage());
                NetworkStatusService::setOnline(false);
            }
        }

        PendingOperation::create([
            'uuid' => $noteUuid,
            'entity_type' => 'PatientNote',
            'action' => 'delete',
            'payload' => ['patient_uuid' => $patientUuid],
        ]);
    }
}
