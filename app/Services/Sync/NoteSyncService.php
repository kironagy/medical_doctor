<?php

namespace App\Services\Sync;

use App\Services\Mobile\RemoteApiService;
use App\Domains\Sync\Models\SyncQueue;
use App\Domains\Sync\Services\SyncQueueService;
use App\Domains\Patients\Models\PatientNote;
use Illuminate\Support\Facades\Log;
use Throwable;

class NoteSyncService
{
    public function __construct(
        private readonly RemoteApiService $api,
        private readonly SyncQueueService $queueService
    ) {}

    public function processItem(SyncQueue $item): bool
    {
        $note = PatientNote::withoutGlobalScope(\App\Domains\Auth\Scopes\DoctorIsolationScope::class)
            ->where('uuid', $item->entity_uuid)
            ->first();

        if (!$note && $item->operation !== 'delete') {
            $this->queueService->markFailed($item->id, 'Local note record not found');
            return false;
        }

        $this->queueService->markProcessing($item->id);

        try {
            switch ($item->operation) {
                case 'create':
                    if (!$note->patient || $note->patient->sync_status !== 'synced') {
                        $this->queueService->markFailed($item->id, 'Parent patient not synced yet');
                        return false;
                    }

                    $response = $this->api->post("/mobile/patients/{$note->patient->uuid}/notes", [
                        'content'  => $note->content,
                        'category' => $note->category ?? 'notes',
                    ]);
                    $remoteUuid = $response['uuid'] ?? $response['data']['uuid'] ?? null;

                    $note->update([
                        'remote_uuid' => $remoteUuid,
                        'sync_status' => 'synced',
                    ]);
                    break;

                case 'update':
                    if (!$note->patient || !$note->remote_uuid) {
                        $this->queueService->markFailed($item->id, 'Note missing remote UUID for update');
                        return false;
                    }

                    $this->api->put("/mobile/patients/{$note->patient->uuid}/notes/{$note->remote_uuid}", [
                        'content' => $note->content,
                    ]);
                    $note->update(['sync_status' => 'synced']);
                    break;

                case 'delete':
                    $remoteUuid = $note?->remote_uuid ?? $item->entity_uuid;
                    if ($note && $note->patient) {
                        try {
                            $this->api->delete("/mobile/patients/{$note->patient->uuid}/notes/{$remoteUuid}");
                        } catch (Throwable $e) {
                            if (!str_contains($e->getMessage(), '404')) throw $e;
                        }
                    }
                    if ($note) {
                        $note->forceDelete();
                    }
                    break;
            }

            $this->queueService->markSynced($item->id);
            return true;
        } catch (Throwable $e) {
            Log::warning("[NoteSyncService] Item {$item->id} failed: " . $e->getMessage());
            $this->queueService->markFailed($item->id, $e->getMessage());
            return false;
        }
    }
}
