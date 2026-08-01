<?php

namespace App\Services\Sync;

use App\Services\Mobile\RemoteApiService;
use App\Domains\Sync\Models\SyncQueue;
use App\Domains\Sync\Services\SyncQueueService;
use App\Domains\Patients\Models\Patient;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class PatientSyncService
{
    public function __construct(
        private readonly RemoteApiService $api,
        private readonly SyncQueueService $queueService
    ) {}

    public function processItem(SyncQueue $item): bool
    {
        $patient = Patient::where('uuid', $item->entity_uuid)->first();
        if (!$patient && $item->operation !== 'delete') {
            $this->queueService->markFailed($item->id, 'Local patient record not found');
            return false;
        }

        $this->queueService->markProcessing($item->id);

        try {
            switch ($item->operation) {
                case 'create':
                    $payload = $patient->toArray();
                    unset($payload['id'], $payload['sync_status'], $payload['deleted_at']);

                    $response = $this->api->post('/mobile/patients', $payload);
                    $remoteUuid = $response['uuid'] ?? $response['data']['uuid'] ?? $patient->uuid;

                    $patient->update([
                        'uuid'        => $remoteUuid,
                        'sync_status' => 'synced',
                        'updated_at'  => now(),
                    ]);

                    DB::table('offline_files')
                        ->where('patient_uuid', $item->entity_uuid)
                        ->update(['patient_uuid' => $remoteUuid]);
                    break;

                case 'update':
                    $payload = $patient->toArray();
                    unset($payload['id'], $payload['sync_status'], $payload['deleted_at']);

                    $this->api->put("/mobile/patients/{$patient->uuid}", $payload);

                    $patient->update([
                        'sync_status' => 'synced',
                        'updated_at'  => now(),
                    ]);
                    break;

                case 'delete':
                    try {
                        $this->api->delete("/mobile/patients/{$item->entity_uuid}");
                    } catch (Throwable $e) {
                        if (!str_contains($e->getMessage(), '404')) {
                            throw $e;
                        }
                    }

                    if ($patient) {
                        $patient->forceDelete();
                    }
                    break;
            }

            $this->queueService->markSynced($item->id);
            return true;
        } catch (Throwable $e) {
            Log::warning("[PatientSyncService] Item {$item->id} failed: " . $e->getMessage());
            $this->queueService->markFailed($item->id, $e->getMessage());
            return false;
        }
    }
}
