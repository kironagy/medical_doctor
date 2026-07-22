<?php

namespace App\Repositories\Hybrid;

use App\Contracts\Repositories\PatientNoteRepositoryInterface;
use App\Repositories\Api\ApiPatientNoteRepository;
use App\Repositories\Eloquent\EloquentPatientNoteRepository;
use App\Services\NetworkStatusService;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Log;

class HybridPatientNoteRepository implements PatientNoteRepositoryInterface
{
    public function __construct(
        private ApiPatientNoteRepository $apiRepo,
        private EloquentPatientNoteRepository $localRepo,
    ) {}

    private function syncLocalCache(array $data): void
    {
        if (isset($data['uuid']) && !is_array($data['uuid'])) {
            $data = [$data];
        }

        foreach ($data as $item) {
            if (is_array($item) && isset($item['uuid'])) {
                // Conflict resolution: skip if local SQLite record has newer changes
                $localRecord = \App\Domains\Patients\Models\PatientNote::where('uuid', $item['uuid'])->first();
                if ($localRecord) {
                    $localTime = $localRecord->client_updated_at ?? $localRecord->updated_at;
                    $serverTime = isset($item['updated_at']) ? new \Carbon\Carbon($item['updated_at']) : null;
                    if ($serverTime && $localTime && $localTime->gt($serverTime)) {
                        Log::info("Conflict detected for Note {$item['uuid']}: device has newer changes. Keeping local.");
                        continue;
                    }
                }

        $cleanData = \Illuminate\Support\Arr::except($item, ['id', 'patient', 'author']);
        try {
            \App\Domains\Patients\Models\PatientNote::withoutGlobalScopes()->updateOrCreate(
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
                NetworkStatusService::handleThrowable($e);
            }
        }
        return $this->localRepo->forPatient($patientUuid);
    }

    public function create(string $patientUuid, array $data): array
    {
        // API-FIRST: When online, create via the production API synchronously.
        // The API response is authoritative. Local SQLite is updated in background.
        if (NetworkStatusService::isOnline()) {
            try {
                if (!isset($data['uuid'])) {
                    $data['uuid'] = (string) \Illuminate\Support\Str::uuid();
                }
                $apiData = $this->apiRepo->create($patientUuid, $data);
                if (is_array($apiData) && isset($apiData['uuid'])) {
                    $this->syncLocalCache($apiData);
                    return $apiData;
                }
            } catch (\Throwable $e) {
                Log::warning('[HybridPatientNoteRepo] create() - API error, falling back to local: ' . $e->getMessage());
                NetworkStatusService::handleThrowable($e);
            }
        }

        // OFFLINE FALLBACK: Save to local SQLite.
        // SYNC NOTE: PatientNoteObserver::created() handles enqueuing the sync operation.
        // We DO NOT enqueue here to avoid duplicate sync queue items.
        $localData = $this->localRepo->create($patientUuid, $data);

        return $localData;
    }

    public function update(string $patientUuid, string $noteUuid, array $data): array
    {
        $localData = $this->localRepo->update($patientUuid, $noteUuid, $data);

        if (NetworkStatusService::isOnline()) {
            try {
                $apiData = $this->apiRepo->update($patientUuid, $noteUuid, $data);
                $this->syncLocalCache($apiData);
                return $apiData;
            } catch (ConnectionException $e) {
                NetworkStatusService::setOnline(false);
                Log::warning('[HybridPatientNoteRepo] update() - API unavailable: ' . $e->getMessage());
            } catch (\Throwable $e) {
                Log::warning('[HybridPatientNoteRepo] update() - API error: ' . $e->getMessage());
                NetworkStatusService::handleThrowable($e);
            }
        }

        // SYNC NOTE: PatientNoteObserver::updated() handles enqueuing the sync operation.
        // No need to enqueue here.

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
                NetworkStatusService::handleThrowable($e);
            }
        }

        // SYNC NOTE: PatientNoteObserver::deleted() handles enqueuing the sync operation.
        // No need to enqueue here.
    }
}
