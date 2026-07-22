# Synchronization Analysis

## 1. SYNC ARCHITECTURE OVERVIEW

### Current Sync Topology

```
                    ┌─────────────────────────────────────┐
                    │         THREE SYNC ENGINES           │
                    │                                      │
                    │  FullSyncService   SyncManager        │
                    │  BackgroundSyncService               │
                    │                                      │
                    │  THREE ENQUEUE SOURCES               │
                    │                                      │
                    │  HybridRepos     Observers           │
                    │  SyncMiddleware                       │
                    └─────────────────────────────────────┘
                              │
                              ▼
                    ┌─────────────────────┐
                    │    SyncQueueService  │
                    │  (sync_queue table)  │
                    │  (sync_states table) │
                    └─────────────────────┘
                              │
                              ▼
                    ┌─────────────────────┐
                    │   Remote API         │
                    │   (MySQL)            │
                    └─────────────────────┘
```

### Sync Flow Direction

```
PUSH (local → remote):
  Observer fires → SyncQueueService.enqueueOperation() → FullSyncService.syncPendingOperations()
  HybridRepo.onAPIFailure() → SyncQueueService.enqueueOperation()
  SyncMiddleware.onOffline() → SyncQueueService.enqueueOperation() [NO LOCAL SAVE]

PULL (remote → local):
  FullSyncService.syncMetadataOnly() → API → updateOrCreate SQLite
  SyncManager.pullMetadata() → API → updateOrCreate SQLite
  BackgroundSyncService.run() → FullSyncService.syncMetadataOnly()
  app.js periodic sync → /api/native/sync/background → FullSyncService.syncMetadataOnly()
```

---

## 2. SYNC ENGINE DUPLICATION

### FullSyncService vs SyncManager

Both classes have NEAR-IDENTICAL code:

| Feature | FullSyncService | SyncManager |
|---------|----------------|-------------|
| syncPendingOperations() | ✅ | ❌ (has pushPending()) |
| pushQueueItem() | ✅ | ✅ (pushItem() - identical) |
| pushPatientToRemote() | ✅ | ✅ (identical) |
| pushFileToRemote() | ✅ | ✅ (identical) |
| pushNoteToRemote() | ✅ | ✅ (identical) |
| pushVisitToRemote() | ✅ | ✅ (identical) |
| fetchChildResourcesBatched() | ✅ | ✅ (identical) |
| syncMetadataOnly() | ✅ | ❌ (has pullMetadata()) |
| pullPaginatedPatients() | ❌ | ✅ |
| pullPaginatedPatientFiles() | ❌ | ✅ |

**Root Cause**: Two developers each created a sync engine without eliminating the other. FullSyncService was the original. SyncManager was added for "better pagination support" but duplicated 90% of the logic.

**Impact**: 
- Maintenance burden (bug fix must be applied in two places)
- Different behavior (FullSyncService uses `all()` with per_page=1000, SyncManager uses paginated)
- Lock contention (both acquire/release `sync_in_progress` lock independently)

### BackgroundSyncService Wrapper

```php
class BackgroundSyncService
{
    public function run(): void
    {
        // Calls FullSyncService::syncMetadataOnly()
        $fullSync = app(FullSyncService::class);
        $fullSync->syncMetadataOnly();
    }
    
    public function runFull(): void
    {
        // Calls SyncManager::pullMetadata()
        $syncManager = app(SyncManager::class);
        $syncManager->pullMetadata();
    }
}
```
Two different methods calling two different sync engines. No consistency guarantee.

---

## 3. CONFLICT RESOLUTION ANALYSIS

### Current Strategy: Last-Write-Wins (LWW)

```php
class ConflictResolver
{
    public function resolve(
        ?string $localUpdatedAt,
        ?string $remoteUpdatedAt,
        bool $hasLocalPendingChanges = false
    ): string {
        if ($hasLocalPendingChanges) return 'local';
        if (!$localUpdatedAt && !$remoteUpdatedAt) return 'remote';
        if (!$localUpdatedAt) return 'remote';
        if (!$remoteUpdatedAt) return 'local';
        
        $localTime = new Carbon($localUpdatedAt);
        $remoteTime = new Carbon($remoteUpdatedAt);
        
        if ($remoteTime->gt($localTime)) return 'remote';
        return 'local';  // Local wins on tie
    }
}
```

### Problems

**P1. Timestamp Granularity**
Both client_updated_at and updated_at are used for comparison:
```php
$record['client_updated_at'] ?? $record['updated_at'] ?? null
```
`updated_at` has second granularity. If two operations happen in the same second (possible with concurrent saves), the resolver returns `local` (local wins on tie) — but this is arbitrary, not deterministic.

**P2. hasPendingChanges Checks sync_queue**
```php
public function hasPendingChanges(string $recordUuid): bool
{
    return SyncQueueItem::where('record_uuid', $recordUuid)
        ->whereIn('status', ['pending', 'failed'])
        ->exists();
}
```
This only checks pending or failed items. If a sync item was already processed (status='synced'), it returns false. So a record that was synced 5 minutes ago and then changed locally will NOT be protected during the next pull sync. The remote version overwrites the local changes.

**P3. Conflict Resolution on Every Record**
The resolver is called for EVERY record during sync, even records that haven't changed. For 1000 records, 1000 DB queries.

**P4. No 3-Way Merge**
LWW means data is always lost in conflicts. If Doctor A changes a patient's phone and Doctor B changes the diagnosis, LWW means one doctor's changes are completely lost. A 3-way merge would preserve both changes.

---

## 4. SYNC QUEUE ANALYSIS

### Queue Item Lifecycle

```
enqueue → status='pending' → process → success → status='synced' → cleanup after 7 days
                                 → failure → status='failed' → retry (up to 5) → status='permanently_failed'
```

### Problems

**P1. No Queue Item Expiry**
`permanently_failed` items stay until manually cleaned by `clearPermanentlyFailed()`. No automatic cleanup schedule.

**P2. All Items Processed Together**
`processPendingOperations()` pulls ALL pending items (sorted, up to 50):
```php
$items = SyncQueueItem::whereIn('status', ['pending', 'failed'])
    ->where('retry_count', '<', self::MAX_RETRIES)
    ->get()
    ->sortBy([...])
    ->take($batchSize);
```
For 3000 pending items, this loads 3000 records into memory even though only 50 are processed. The in-memory sort happens on all 3000.

**P3. Inconsistent Status Reset**
When processing, failed items are reset to pending:
```php
$failedIds = $items->where('status', 'failed')->pluck('id');
SyncQueueItem::whereIn('id', $failedIds)->update(['status' => 'pending']);
```
But this happens BEFORE the items are processed. If the process crashes after this update but before processing, the items are reset to pending without retry_count increment. They'll be retried on next run, but with no record of the previous failure.

**P4. Missing Heartbeat for Lock**
The lock mechanism (`acquireLock()`) sets `sync_in_progress = true` with a timestamp. If the sync process crashes (PHP fatal error, OOM kill), the lock stays for 300 seconds. During this time:
1. No other sync can start
2. New queue items accumulate
3. User sees "sync in progress" for 5 minutes

**P5. No Queue Priority Escalation**
Items start with priority 5. Failed items stay at priority 5. There's no escalation — critical operations (patient creates) don't get higher priority after retry failure.

---

## 5. RECURRING SYNC ISSUES

### Issue 1: SyncMiddleware Blocks Controller Execution

When offline:
```php
$this->syncQueue->enqueueOperation($entity, $operation, $recordUuid, $payload);
$offlineResponse = response()->json([
    'success' => true,
    'queued_offline' => true,
]);
return $offlineResponse;
```
**The controller NEVER runs.** The data is queued but NOT saved to local SQLite. When the user navigates away and comes back, the data is gone. It only appears after the sync completes.

### Issue 2: Observer + HybridRepo Double Enqueue

Path: HybridRepository::create() when API fails:
1. EloquentPatientRepository::create() executes → SQLite INSERT
2. Eloquent model `created` event fires → PatientObserver::created() would fire (NONE EXISTS for Patient!)
3. For File/Note: the Observer enqueues sync
4. Then HybridRepo also enqueues sync → call to SyncQueueService::enqueueOperation()
5. SyncQueueService::hasPending() checks for existing pending → IF Observer already enqueued, return existing
6. BUT: Observer's enqueue is async — the `hasPending()` check may not find the Observer's item yet (same transaction)

### Issue 3: Multiple Simultaneous Pulls

Scenario:
1. User opens app → DoctorWorkspace onMounted → syncAndRefresh() → POST /api/native/sync → FullSyncService.syncMetadataOnly()
2. After sync, sync-completed event fires → useWorkspace listener → refreshPatientList()
3. Meanwhile, 2-minute periodic sync triggers → POST /api/native/sync/background → FullSyncService.syncMetadataOnly()
4. Both acquireLock() → first one gets lock, second skips (correct)
5. BUT: Both already called syncPendingOperations() before lock check — first processes items, second finds nothing to process
6. Result: No double-sync, but wasted API calls for duplicate processing

### Issue 4: Missing Soft Delete Awareness in Pull

When pushing a delete:
```php
$this->localRepo->delete($uuid);  // Soft delete
$this->syncQueue->enqueueOperation('Patient', 'delete', $uuid);
```
When pulling next time:
```php
$patients = $this->apiPatientRepo->all();  // API doesn't return soft-deleted patients
$this->syncLocalCache($patients);  // updateOrCreate — doesn't soft-delete locally!
```
The local soft-deleted patient remains in SQLite (not updated by API response because API doesn't return it). But the frontend calls `Patient::latest()` which respects the scope — so deleted patients are hidden. But they're still in the database, consuming space.

---

## 6. INCREMENTAL SYNC ANALYSIS

The `IncrementalSyncService` exists but is incomplete:
```php
class IncrementalSyncService
{
    public function incrementalPull(): void
    {
        $lastSyncAt = $this->getLastSyncTimestamp();
        // ... fetch records updated since $lastSyncAt
    }
}
```
This class is a stub. It has the concept but no real implementation. The actual sync always does full pulls.

---

## 7. RECOMMENDATIONS

### Critical Fixes
1. **Consolidate sync engines**: Remove SyncManager, keep only FullSyncService
2. **Fix SyncMiddleware**: Save to local SQLite before returning offline success
3. **Add PatientObserver**: Create create/update/delete observer for Patient model
4. **Fix Observer + HybridRepo double-enqueue**: Only one path should enqueue
5. **Add lock heartbeat**: Periodically update lock timestamp to prevent 5-minute freeze

### Short-term Improvements
6. **Batch conflict resolution**: Only check pending changes for records that exist locally
7. **Queue priority escalation**: Failed items get higher priority after retry
8. **Implement incremental sync**: Only fetch changed records since last sync
9. **Soft-delete awareness in pull**: When pulling new data, soft-delete locally if remote doesn't return record
10. **Scheduled cleanup**: Automatic cleanup of old synced and permanently_failed items

### Architecture Changes
11. **Move to push-based sync**: Changes should notify immediately rather than polling
12. **Add sync progress reporting**: Return progress from background sync for UI indicators
13. **Implement proper conflict UI**: Let user choose which version to keep on conflict
