<?php

namespace App\Repositories;

use App\Contracts\Repositories\PatientRepositoryInterface;
use App\Repositories\Api\ApiPatientRepository;
use App\Repositories\Eloquent\EloquentPatientRepository;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Log;

class PatientRepository implements PatientRepositoryInterface
{
    public function __construct(
        private ApiPatientRepository $api,
        private EloquentPatientRepository $local,
    ) {}

    public function all(): array
    {
        try {
            $this->syncPendingPatients();
        } catch (\Throwable $e) {
            // Silently ignore
        }

        $data = $this->local->all();
        return array_values(array_filter($data, fn($p) => ($p['sync_status'] ?? 'synced') !== 'pending_delete'));
    }

    public function paginated(int $perPage = 10, int $page = 1, ?string $status = null): array
    {
        try {
            $this->syncPendingPatients();

            $data = $this->api->paginated($perPage, $page, $status);
            if (isset($data['data'])) {
                $this->syncLocalCache($data['data']);

                // ── Merge local pending patients not yet in API response ─────
                // After syncing pending patients to the API, some may have failed
                // (e.g., network hiccup, server error). They remain in SQLite with
                // sync_status='pending_create' or 'pending_update'. The API paginated
                // response won't include them. We must merge them back into the result
                // so the frontend doesn't lose them.
                $localPending = \App\Domains\Patients\Models\Patient::whereIn(
                    'sync_status', ['pending_create', 'pending_update']
                )->get()->toArray();

                if (!empty($localPending)) {
                    $apiUuids = collect($data['data'])->pluck('uuid')->toArray();
                    foreach ($localPending as $pendingPatient) {
                        if (!in_array($pendingPatient['uuid'], $apiUuids)) {
                            $data['data'][] = $pendingPatient;
                        }
                    }
                    // Re-sort by latest to maintain consistent ordering
                    usort($data['data'], fn($a, $b) => strcmp(
                        $b['created_at'] ?? $b['client_updated_at'] ?? '',
                        $a['created_at'] ?? $a['client_updated_at'] ?? ''
                    ));
                    Log::info('[PatientRepo] paginated() - merged ' . count($localPending) . ' local pending patients into response');
                }
            }
            return $data;
        } catch (\Throwable $e) {
            Log::info('[PatientRepo] paginated() - API unavailable, using local: ' . $e->getMessage());
        }

        $localResult = $this->local->paginated($perPage, $page, $status);
        $count = count($localResult['data'] ?? []);
        $firstUuid = $localResult['data'][0]['uuid'] ?? 'none';
        $hasPendingCreate = collect($localResult['data'] ?? [])->contains(fn($p) => ($p['sync_status'] ?? '') === 'pending_create');
        Log::info('[DIAG] PatientRepo::paginated() returning ' . $count . ' patients, first uuid: ' . $firstUuid . ', has_pending_create: ' . ($hasPendingCreate ? 'YES' : 'NO'));
        return $localResult;
    }

    private function syncPendingPatients(): void
    {
        $pendingPatients = \App\Domains\Patients\Models\Patient::whereIn('sync_status', ['pending_create', 'pending_update'])->get();

        foreach ($pendingPatients as $patient) {
            try {
                $data = $patient->toArray();
                unset($data['id'], $data['sync_status'], $data['client_updated_at'], $data['deleted_at']);

                if ($patient->sync_status === 'pending_create') {
                    $apiData = $this->api->create($data);
                    $remoteUuid = $apiData['uuid'] ?? 'unknown';
                } else {
                    $apiData = $this->api->update($patient->uuid, $data);
                    $remoteUuid = $apiData['uuid'] ?? $patient->uuid;
                }

                $this->syncSingleToLocal($apiData, force: true);

                Log::info('[PatientRepo] Synced pending patient to server', [
                    'local_uuid' => $patient->uuid,
                    'remote_uuid' => $remoteUuid,
                    'sync_status' => $patient->sync_status,
                ]);
            } catch (\Throwable $e) {
                Log::info('[PatientRepo] Failed to sync patient (' . $patient->sync_status . '): ' . $e->getMessage());
                // Continue to next patient — don't break! Remaining patients may still sync.
            }
        }
    }

    public function find(string $uuid): ?array
    {
        $data = $this->local->find($uuid);
        if ($data && ($data['sync_status'] ?? 'synced') === 'pending_delete') {
            return null;
        }
        return $data;
    }

    public function findByUuid(string $uuid): array
    {
        $result = $this->find($uuid);
        if (!$result) throw new \RuntimeException('Patient not found.');
        return $result;
    }

    public function create(array $data): array
    {
        $tf = '/data/local/tmp/np_traces.txt';
        @file_put_contents($tf, now()->format('H:i:s.v') . ' P6 ENTERED name=' . ($data['name'] ?? 'none') . "\n", FILE_APPEND | LOCK_EX);
        try {
            $apiPayload = $data;
            $data['sync_status'] = 'pending_create';
            $data['client_updated_at'] = now();

            @file_put_contents($tf, now()->format('H:i:s.v') . ' P6a calling Eloquent::create()' . "\n", FILE_APPEND | LOCK_EX);
            $localData = $this->local->create($data);
            @file_put_contents($tf, now()->format('H:i:s.v') . ' P6b local create uuid=' . (isset($localData['uuid']) ? $localData['uuid'] : 'NONE') . "\n", FILE_APPEND | LOCK_EX);

            if (isset($localData['uuid'])) {
                $apiPayload['uuid'] = $localData['uuid'];
                try {
                    @file_put_contents($tf, now()->format('H:i:s.v') . ' P6c calling remote API' . "\n", FILE_APPEND | LOCK_EX);
                    $apiData = $this->api->create($apiPayload);
                    $this->syncSingleToLocal($apiData, force: true);
                    @file_put_contents($tf, now()->format('H:i:s.v') . ' P6d remote SUCCESS'. "\n", FILE_APPEND | LOCK_EX);
                    return $apiData;
                } catch (\Throwable $e) {
                    @file_put_contents($tf, now()->format('H:i:s.v') . ' P6e remote FAILED: ' . $e->getMessage() . "\n", FILE_APPEND | LOCK_EX);
                    Log::info('[PatientRepo] create() - remote API unavailable: ' . $e->getMessage());
                }
            }

            @file_put_contents($tf, now()->format('H:i:s.v') . ' P6f returning localData'. "\n", FILE_APPEND | LOCK_EX);
            Log::info('[DIAG] PatientRepo::create() returning uuid=' . ($localData['uuid'] ?? 'NO UUID IN localData') . ' name=' . ($localData['name'] ?? 'none') . ' sync_status=' . ($localData['sync_status'] ?? 'none'));
            return $localData;
        } catch (\Throwable $e) {
            @file_put_contents($tf, now()->format('H:i:s.v') . ' P6g OUTER CATCH: ' . $e->getMessage() . ' at ' . $e->getFile() . ':' . $e->getLine() . "\n", FILE_APPEND | LOCK_EX);
            Log::error('[PatientRepo] create() - failed to save locally: ' . $e->getMessage(), [
                'trace' => substr($e->getTraceAsString(), 0, 500),
            ]);
            return [];
        }
    }

    public function update(string $uuid, array $data): array
    {
        $apiPayload = $data;

        $data['sync_status'] = 'pending_update';
        $data['client_updated_at'] = now();
        $localData = $this->local->update($uuid, $data);

        try {
            $apiData = $this->api->update($uuid, $apiPayload);
            $this->syncSingleToLocal($apiData, force: true);
            return $apiData;
        } catch (\Throwable $e) {
            Log::info('[PatientRepo] update() - offline, saved locally with pending_update: ' . $e->getMessage());
        }

        return $localData;
    }

    public function delete(string $uuid): void
    {
        $this->local->update($uuid, ['sync_status' => 'pending_delete', 'client_updated_at' => now()]);
        $this->local->delete($uuid);

        try {
            $this->api->delete($uuid);
        } catch (\Throwable $e) {
            Log::info('[PatientRepo] delete() - offline, soft-deleted with pending_delete: ' . $e->getMessage());
        }
    }

    public function search(string $term): array
    {
        try {
            $data = $this->api->search($term);
            $this->syncLocalCache($data);
            return $data;
        } catch (\Throwable $e) {
            Log::info('[PatientRepo] search() - API unavailable, using local: ' . $e->getMessage());
        }

        return $this->local->search($term);
    }

    public function shared(int $userId): array
    {
        try {
            return $this->api->shared($userId);
        } catch (\Throwable $e) {
            Log::info('[PatientRepo] shared() - API unavailable, using local: ' . $e->getMessage());
        }

        return $this->local->shared($userId);
    }

    public function stats(): array
    {
        try {
            return $this->api->stats();
        } catch (\Throwable $e) {
            Log::info('[PatientRepo] stats() - API unavailable, using local: ' . $e->getMessage());
        }

        return $this->local->stats();
    }

    public function recent(int $limit): array
    {
        try {
            $data = $this->api->recent($limit);
            $this->syncLocalCache($data);
            return $data;
        } catch (\Throwable $e) {
            Log::info('[PatientRepo] recent() - API unavailable, using local: ' . $e->getMessage());
        }

        return $this->local->recent($limit);
    }

    public function withTrashed(): array
    {
        try {
            $data = $this->api->withTrashed();
            $this->syncLocalCache($data);
            return $data;
        } catch (\Throwable $e) {
            Log::info('[PatientRepo] withTrashed() - API unavailable, using local: ' . $e->getMessage());
        }

        return $this->local->withTrashed();
    }

    public function restore(string $uuid): void
    {
        $this->local->restore($uuid);
        $this->local->update($uuid, ['sync_status' => 'pending_update', 'client_updated_at' => now()]);

        try {
            $this->api->restore($uuid);
        } catch (\Throwable $e) {
            Log::info('[PatientRepo] restore() - offline, restored locally: ' . $e->getMessage());
        }
    }

    public function forceDelete(string $uuid): void
    {
        try {
            $this->api->forceDelete($uuid);
            $this->local->forceDelete($uuid);
            return;
        } catch (\Throwable $e) {
            Log::info('[PatientRepo] forceDelete() - offline, marking as pending_delete: ' . $e->getMessage());
        }

        $this->local->update($uuid, ['sync_status' => 'pending_delete', 'client_updated_at' => now()]);
        $this->local->delete($uuid);
    }

    private function syncSingleToLocal(array $data, bool $force = false): void
    {
        if (!isset($data['uuid'])) return;

        if (!$force) {
            $localRecord = \App\Domains\Patients\Models\Patient::where('uuid', $data['uuid'])->first();
            if ($localRecord && $localRecord->sync_status !== 'synced') {
                return;
            }
        }

        $cleanData = \Illuminate\Support\Arr::except($data, [
            'id', 'primary_doctor', 'visits', 'shares', 'files', 'notes'
        ]);
        $cleanData['sync_status'] = 'synced';

        try {
            \App\Domains\Patients\Models\Patient::unguard();
            \App\Domains\Patients\Models\Patient::updateOrCreate(
                ['uuid' => $data['uuid']],
                $cleanData
            );
            \App\Domains\Patients\Models\Patient::reguard();
        } catch (\Exception $e) {
            \App\Domains\Patients\Models\Patient::reguard();
            Log::warning('[PatientRepo] Failed to sync local cache: ' . $e->getMessage());
        }
    }

    private function syncLocalCache(array $data): void
    {
        foreach ($data as $item) {
            if (is_array($item) && isset($item['uuid'])) {
                $this->syncSingleToLocal($item);
            }
        }
    }
}
