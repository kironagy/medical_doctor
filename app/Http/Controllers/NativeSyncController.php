<?php

namespace App\Http\Controllers;

use App\Models\PendingOperation;
use App\Models\SyncQueueItem;
use App\Repositories\Api\ApiPatientRepository;
use App\Repositories\Api\ApiPatientVisitRepository;
use App\Repositories\Api\ApiPatientNoteRepository;
use App\Repositories\Api\ApiPatientFileRepository;
use App\Services\FullSyncService;
use App\Services\SyncQueueService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class NativeSyncController extends Controller
{
    public function __construct(
        private FullSyncService $fullSync,
        private SyncQueueService $syncQueue,
        private ApiPatientRepository $apiPatient,
        private ApiPatientVisitRepository $apiVisit,
        private ApiPatientNoteRepository $apiNote,
        private ApiPatientFileRepository $apiFile
    ) {}

/**
 * Sync all pending operations with the remote, then pull fresh remote data
 * into local SQLite.
 *
 * IMPORTANT: This endpoint is called from the frontend (syncAndRefresh) AFTER
 * the UI has already rendered. It runs in the background and should NEVER
 * block the response — the frontend handles failures gracefully.
 */
public function sync(Request $request)
{
    Log::info('NativeSyncController: Starting Sync');

    // Verify API token is available for sync operations
    $token = $this->getToken();
    if (!$token) {
        Log::warning('[NativeSyncController] Sync attempted without API token');
        return response()->json([
            'error' => 'No API token available. Please login again.',
            'auth_error' => true,
        ], 401);
    }

    Log::info('[NativeSyncController] Token available: YES (len=' . strlen($token) . ')');

    // Log local patient count before sync
    try {
        $beforeCount = \App\Domains\Patients\Models\Patient::count();
        Log::info('[NativeSyncController] Local patients BEFORE sync: ' . $beforeCount);
    } catch (\Throwable $e) {
        Log::warning('[NativeSyncController] Count before sync failed: ' . $e->getMessage());
    }

    try {
        // 1. Push any legacy PendingOperation records (pre-SyncQueue entries).
        $pending = PendingOperation::oldest()->get();
        foreach ($pending as $op) {
            try {
                $this->pushLegacyOperation($op);
                $op->delete();
            } catch (\Exception $e) {
                Log::error(
                    "Failed to push legacy operation {$op->id} ({$op->entity_type} {$op->action}): " . $e->getMessage()
                );
            }
        }

        // 2. Pull remote data + push pending queue items (syncMetadataOnly handles both).
        $this->fullSync->syncMetadataOnly();

        // Log local patient count after sync
        try {
            $afterCount = \App\Domains\Patients\Models\Patient::count();
            Log::info('[NativeSyncController] Local patients AFTER sync: ' . $afterCount);
        } catch (\Throwable $e) {
            Log::warning('[NativeSyncController] Count after sync failed: ' . $e->getMessage());
        }

        return response()->json(['success' => true]);
    } catch (\Exception $e) {
        Log::error('NativeSyncController error: ' . $e->getMessage());
        return response()->json(['error' => $e->getMessage()], 500);
    }
}

/**
 * Push a single SyncQueueItem to the remote API.
 */
private function pushItemToRemote(SyncQueueItem $item): void
{
    match ($item->entity) {
        'Patient'      => $this->pushPatientItem($item),
        'PatientVisit' => $this->pushVisitItem($item),
        'PatientNote'  => $this->pushNoteItem($item),
        'PatientFile'  => $this->pushFileItem($item),
        default => Log::warning("[NativeSyncController] Unsupported entity: {$item->entity}"),
    };
}

/**
 * Push a legacy PendingOperation to the remote API.
 */
private function pushLegacyOperation(PendingOperation $op): void
{
if ($op->entity_type === 'Patient') {
if ($op->action === 'create') {
$this->apiPatient->create($op->payload);
} elseif ($op->action === 'update') {
$this->apiPatient->update($op->uuid, $op->payload);
} elseif ($op->action === 'delete') {
$this->apiPatient->delete($op->uuid);
}
} elseif ($op->entity_type === 'PatientVisit') {
$patientUuid = $op->payload['patient_uuid'] ?? null;
if (! $patientUuid) {
return;
}
if ($op->action === 'create') {
$this->apiVisit->create($patientUuid, $op->payload);
} elseif ($op->action === 'update') {
$this->apiVisit->update((int) $op->uuid, $op->payload);
} elseif ($op->action === 'delete') {
$this->apiVisit->delete((int) $op->uuid);
}
} elseif ($op->entity_type === 'PatientNote') {
$patientUuid = $op->payload['patient_uuid'] ?? null;
if (! $patientUuid) {
return;
}
if ($op->action === 'create') {
$this->apiNote->create($patientUuid, $op->payload);
} elseif ($op->action === 'update') {
$this->apiNote->update($patientUuid, $op->uuid, $op->payload);
} elseif ($op->action === 'delete') {
$this->apiNote->delete($patientUuid, $op->uuid);
}
}
}

private function pushPatientItem(SyncQueueItem $item): void
{
$payload = $item->payload ?? [];
if ($item->operation === 'create') {
$this->apiPatient->create($payload);
} elseif ($item->operation === 'update') {
$this->apiPatient->update($item->record_uuid, $payload);
} elseif ($item->operation === 'delete' && $item->record_uuid) {
$this->apiPatient->delete($item->record_uuid);
}
}

private function pushVisitItem(SyncQueueItem $item): void
{
$patientUuid = $item->payload['patient_uuid'] ?? null;
if (! $patientUuid) {
return;
}
if ($item->operation === 'create') {
$this->apiVisit->create($patientUuid, $item->payload);
} elseif ($item->operation === 'update' && $item->record_uuid) {
$this->apiVisit->update((int) $item->record_uuid, $item->payload);
} elseif ($item->operation === 'delete' && $item->record_uuid) {
$this->apiVisit->delete((int) $item->record_uuid);
}
}

    private function pushNoteItem(SyncQueueItem $item): void
    {
        $patientUuid = $item->payload['patient_uuid'] ?? null;
        if (! $patientUuid) {
            return;
        }
        if ($item->operation === 'create') {
            $this->apiNote->create($patientUuid, $item->payload);
        } elseif ($item->operation === 'update' && $item->record_uuid) {
            $this->apiNote->update($patientUuid, $item->record_uuid, $item->payload);
        } elseif ($item->operation === 'delete' && $item->record_uuid) {
            $this->apiNote->delete($patientUuid, $item->record_uuid);
        }
    }

    /**
     * Push a PatientFile sync item to the remote API.
     * For offline-created files the binary is read from local storage.
     */
    private function pushFileItem(SyncQueueItem $item): void
    {
        $patientUuid = $item->payload['patient_uuid'] ?? null;

        if ($item->operation === 'delete' && $item->record_uuid) {
            $this->apiFile->delete($item->record_uuid);
            return;
        }

        if ($item->operation === 'create' && $patientUuid) {
            $localPath = $item->payload['local_path'] ?? null;
            if (! $localPath) {
                Log::warning("[NativeSyncController] PatientFile create: no local_path for {$item->record_uuid}");
                return;
            }

            $fullPath = \Illuminate\Support\Facades\Storage::disk('local')->path($localPath);
            if (! file_exists($fullPath)) {
                Log::warning("[NativeSyncController] PatientFile create: file not found at {$fullPath}");
                return;
            }

            // Use Http::attach() for multipart upload
            $apiUrl = config('app.mobile_api_url', 'https://prof-hosam-fekry.online/api/v1/mobile');
            $url = $apiUrl . '/patients/' . $patientUuid . '/files';

            $token = null;
            try {
                $token = app(\App\Services\Mobile\ApiService::class)->getToken();
            } catch (\Throwable $e) {
                try {
                    $token = session('api_token_raw');
                } catch (\Throwable $se) {}
            }

            $mimeType = $item->payload['mime_type'] ?? (mime_content_type($fullPath) ?: 'application/octet-stream');
            $fileName = $item->payload['file_name'] ?? basename($fullPath);

            $http = \Illuminate\Support\Facades\Http::timeout(120)
                ->when($token, fn($c) => $c->withToken($token))
                ->attach('file', file_get_contents($fullPath), $fileName, ['Content-Type' => $mimeType]);

            $data = \Illuminate\Support\Arr::except($item->payload, ['patient_uuid', 'local_path', 'file', 'file_path', 'file_name', 'mime_type', 'size', 'upload_status']);
            foreach ($data as $k => $v) {
                if ($v !== null) $http = $http->attach($k, (string) $v);
            }

            $response = $http->post($url);
            if ($response->failed()) {
                throw new \RuntimeException("File upload failed: " . ($response->json('message') ?? $response->body()));
            }

            Log::info("[NativeSyncController] Uploaded offline file {$fileName} for patient {$patientUuid}");
        }
    }


/**
 * GET /api/native/sync/status
 *
 * Returns the current sync queue state. Intended to be called from
 * routes/api.php (or web.php) as a standalone endpoint.
 */    /**
     * Get the current API token from the active session or local DB.
     */
    private function getToken(): ?string
    {
        try {
            return app(\App\Services\Mobile\ApiService::class)->getToken();
        } catch (\Throwable $e) {
            try {
                return session('api_token_raw');
            } catch (\Throwable $se) {
                return null;
            }
        }
    }

    public function getStatus()
    {
try {
$pendingCount = $this->syncQueue->getPendingCount();

$lastSyncRow = DB::table('sync_states')->where('key', 'last_sync_at')->first();
$inProgressRow = DB::table('sync_states')->where('key', 'sync_in_progress')->first();

$lastSyncAt = $lastSyncRow ? json_decode($lastSyncRow->value, true) : null;
$syncInProgress = $inProgressRow ? (bool) json_decode($inProgressRow->value, true) : false;

return response()->json([
'success'          => true,
'pending_count'    => $pendingCount,
'last_sync_at'     => $lastSyncAt,
'sync_in_progress' => $syncInProgress,
]);
} catch (\Exception $e) {
Log::error('NativeSyncController::getStatus error: ' . $e->getMessage());
return response()->json(['error' => $e->getMessage()], 500);
}
}

/**
 * POST /api/native/sync/force
 *
 * Forces a full sync regardless of connectivity checks.
 */
public function forceSync(Request $request)
{
Log::info('NativeSyncController: Force sync requested.');

try {
return $this->sync($request);
} catch (\Exception $e) {
Log::error('NativeSyncController::forceSync error: ' . $e->getMessage());
return response()->json(['error' => $e->getMessage()], 500);
}
}
}
