<?php

namespace App\Repositories\Hybrid;

use App\Contracts\Repositories\PatientFileRepositoryInterface;
use App\Repositories\Api\ApiPatientFileRepository;
use App\Repositories\Eloquent\EloquentPatientFileRepository;
use App\Services\NetworkStatusService;
use App\Services\SyncQueueService;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

class HybridPatientFileRepository implements PatientFileRepositoryInterface
{
    public function __construct(
        private ApiPatientFileRepository $apiRepo,
        private EloquentPatientFileRepository $localRepo,
        private SyncQueueService $syncQueue,
    ) {}

    private function syncLocalCache(array $data, ?string $patientUuid = null): void
    {
        if (isset($data['uuid']) && !is_array($data['uuid'])) {
            $data = [$data];
        }

        foreach ($data as $item) {
            if (is_array($item) && isset($item['uuid'])) {
                // Conflict resolution: skip if local SQLite record has newer changes
                $localRecord = \App\Domains\Media\Models\PatientFile::where('uuid', $item['uuid'])->first();
                if ($localRecord) {
                    $localTime = $localRecord->client_updated_at ?? $localRecord->updated_at;
                    $serverTime = isset($item['updated_at']) ? new \Carbon\Carbon($item['updated_at']) : null;
                    if ($serverTime && $localTime && $localTime->gt($serverTime)) {
                        Log::info("Conflict detected for File {$item['uuid']}: device has newer changes. Keeping local.");
                        continue;
                    }
                }

                $cleanData = \Illuminate\Support\Arr::except($item, ['id', 'patient', 'creator', 'uploader']);
                // Resolve local patient_id via UUID to prevent foreign key or patient mismatches
                $pUuid = $patientUuid ?? $item['patient_uuid'] ?? ($item['patient']['uuid'] ?? null);
                if ($pUuid) {
                    $localPatient = \App\Domains\Patients\Models\Patient::where('uuid', $pUuid)->first();
                    if ($localPatient) {
                        $cleanData['patient_id'] = $localPatient->id;
                    }
                }
                
                // Generate a local file path if missing from remote API metadata response
                if (empty($cleanData['file_path'])) {
                    $resolvedUuid = $pUuid ?? 'unknown';
                    $fileName = $item['file_name'] ?? ($item['title'] ?? 'file');
                    $cleanData['file_path'] = "patients/{$resolvedUuid}/{$fileName}";
                }

                // Map API response field names to model field names
                if (isset($cleanData['description']) && !isset($cleanData['desc'])) {
                    $cleanData['desc'] = $cleanData['description'];
                }
                unset($cleanData['description'], $cleanData['url'], $cleanData['thumbnail_url']);
                // uploaded_by_id is NOT NULL in the DB, but the API only sends nested uploader
                if (empty($cleanData['uploaded_by_id'])) {
                    if (isset($item['uploader']['id'])) {
                        $cleanData['uploaded_by_id'] = $item['uploader']['id'];
                    } else {
                        $cleanData['uploaded_by_id'] = Auth::id();
                    }
                }
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

    private function rewriteUrls(array $item): array
    {
        if (isset($item['uuid'])) {
            $baseUrl = rtrim(config('app.url'), '/');
            $item['url'] = $baseUrl . '/api/v1/files/' . $item['uuid'];
            
            if (!empty($item['thumbnail_path']) || !empty($item['thumbnail_url'])) {
                // We MUST proxy the thumbnail through the local API to inject the Bearer token
                $item['thumbnail_url'] = $baseUrl . '/api/v1/files/' . $item['uuid'] . '/thumbnail';
            } elseif (isset($item['mime_type']) && str_starts_with($item['mime_type'], 'image/')) {
                $item['thumbnail_url'] = $item['url'];
            }
        }
        return $item;
    }

    public function forPatient(string $patientUuid): array
    {
        if (NetworkStatusService::isOnline()) {
            try {
                $data = $this->apiRepo->forPatient($patientUuid);
                $this->syncLocalCache($data, $patientUuid);
                return array_map([$this, 'rewriteUrls'], $data);
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
                if ($data) {
                    $this->syncLocalCache([$data]);
                    return $this->rewriteUrls($data);
                }
                return null;
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
                $apiData = $this->apiRepo->upload($patientUuid, $file, $data);
                // Sync API response back into local SQLite
                $this->syncLocalCache([$apiData], $patientUuid);
                return $this->rewriteUrls($apiData);
            } catch (ConnectionException $e) {
                NetworkStatusService::setOnline(false);
                Log::warning('[HybridPatientFileRepo] upload() - API unavailable: ' . $e->getMessage());
            } catch (\Throwable $e) {
                Log::warning('[HybridPatientFileRepo] upload() - API error: ' . $e->getMessage());
                NetworkStatusService::setOnline(false);
            }
        }

        // Queue for offline sync — note: binary file cannot be re-uploaded from queue easily;
        // the file is already stored locally, so we mark it for upload when online
        $this->syncQueue->enqueueOperation(
            'PatientFile', 'create',
            $localData['uuid'] ?? \Illuminate\Support\Str::uuid()->toString(),
            array_merge($data, ['patient_uuid' => $patientUuid, 'local_path' => $localData['file_path'] ?? null]),
            3 // higher priority so files upload before other operations
        );

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

        $this->syncQueue->enqueueOperation('PatientFile', 'delete', $uuid, null);
    }

    public function byCategory(string $patientUuid, string $categorySlug): array
    {
        if (NetworkStatusService::isOnline()) {
            try {
                $data = $this->apiRepo->byCategory($patientUuid, $categorySlug);
                $this->syncLocalCache($data);
                return array_map([$this, 'rewriteUrls'], $data);
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
