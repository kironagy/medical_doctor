<?php

namespace App\Services\Sync;

use App\Services\Mobile\RemoteApiService;
use App\Domains\Sync\Models\SyncQueue;
use App\Domains\Sync\Services\SyncQueueService;
use App\Domains\Patients\Models\PatientVisit;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Log;
use Throwable;

class VisitSyncService
{
    public function __construct(
        private readonly RemoteApiService $api,
        private readonly SyncQueueService $queueService
    ) {}

    public function processItem(SyncQueue $item): bool
    {
        $visit = PatientVisit::withoutGlobalScope(\App\Domains\Auth\Scopes\DoctorIsolationScope::class)
            ->where('uuid', $item->entity_uuid)
            ->first();

        if (!$visit && $item->operation !== 'delete') {
            $this->queueService->markFailed($item->id, 'Local visit record not found');
            return false;
        }

        $this->queueService->markProcessing($item->id);

        try {
            switch ($item->operation) {
                case 'create':
                    if (!$visit->patient || $visit->patient->sync_status !== 'synced') {
                        $this->queueService->markFailed($item->id, 'Parent patient not synced yet');
                        return false;
                    }

                    $payload = Arr::except($visit->toArray(), ['id', 'sync_status', 'client_updated_at', 'deleted_at', 'patient']);
                    $response = $this->api->post("/mobile/patients/{$visit->patient->uuid}/visits", $payload);
                    $remoteUuid = $response['uuid'] ?? $response['data']['uuid'] ?? $visit->uuid;

                    $visit->update([
                        'remote_uuid' => $remoteUuid,
                        'sync_status' => 'synced',
                    ]);
                    break;

                case 'update':
                    if (!$visit->patient) {
                        $this->queueService->markFailed($item->id, 'Parent patient not found');
                        return false;
                    }

                    $payload = Arr::except($visit->toArray(), ['id', 'sync_status', 'client_updated_at', 'deleted_at', 'patient']);
                    $remoteId = $visit->remote_uuid ?? $visit->id;
                    $this->api->put("/mobile/patients/{$visit->patient->uuid}/visits/{$remoteId}", $payload);

                    $visit->update(['sync_status' => 'synced']);
                    break;

                case 'delete':
                    if ($visit && $visit->patient) {
                        $remoteId = $visit->remote_uuid ?? $visit->id;
                        try {
                            $this->api->delete("/mobile/patients/{$visit->patient->uuid}/visits/{$remoteId}");
                        } catch (Throwable $e) {
                            if (!str_contains($e->getMessage(), '404')) throw $e;
                        }
                    }
                    if ($visit) {
                        $visit->forceDelete();
                    }
                    break;
            }

            $this->queueService->markSynced($item->id);
            return true;
        } catch (Throwable $e) {
            Log::warning("[VisitSyncService] Item {$item->id} failed: " . $e->getMessage());
            $this->queueService->markFailed($item->id, $e->getMessage());
            return false;
        }
    }
}
