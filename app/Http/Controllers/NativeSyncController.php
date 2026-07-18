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
 */
public function sync(Request $request)
{
Log::info('NativeSyncController: Starting Sync');

try {
// 1. Push operations from sync_queue (queued by SyncMiddleware in online mode).
$queueItems = $this->syncQueue->processPendingOperations();

foreach ($queueItems as $item) {
try {
$this->pushItemToRemote($item);

// Mark as synced only on success so a partial failure doesn't hide errors.
$this->syncQueue->markItemResult($item, true);
} catch (\Exception $e) {
$this->syncQueue->markItemResult($item, false, $e->getMessage());
// Continue with the next item rather than aborting the whole sync.
}
}

// 2. Push any legacy PendingOperation records (pre-SyncQueue entries).
$pending = PendingOperation::oldest()->get();
foreach ($pending as $op) {
try {
$this->pushLegacyOperation($op);

$op->delete();
} catch (\Exception $e) {
Log::error(
"Failed to push legacy operation {$op->id} ({$op->entity_type} {$op->action}): " . $e->getMessage()
);
// Keep the PendingOperation so it can be retried next sync attempt.
}
}

// 3. Pull all remote data into the local SQLite database.
$this->fullSync->syncAll();

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
'Patient'     => $this->pushPatientItem($item),
'PatientVisit'=> $this->pushVisitItem($item),
'PatientNote' => $this->pushNoteItem($item),
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
 * GET /api/native/sync/status
 *
 * Returns the current sync queue state. Intended to be called from
 * routes/api.php (or web.php) as a standalone endpoint.
 */
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
}
