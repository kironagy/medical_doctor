# Bug Catalog

## Bug Categories Key
- **S**: Severity (Critical/High/Medium/Low)
- **C**: Category (Data/Sync/UI/Performance/Logic/Architecture)
- **P**: Priority (1=Immediate, 2=This Week, 3=This Sprint, 4=Backlog)

---

## CRITICAL BUGS

### B001: Patient Silently Disappears from List on Page 2+
- **S**: Critical | **C**: Data | **P**: 1
- **Root Cause**: `WorkspaceController::patientList()` calls `ApiPatientRepository::paginated(10, page, status)` with per_page=10. Frontend `refreshPatientList()` doesn't specify per_page. Default is 10 patients per page. When a new patient is created, it's prepended to the list in memory via `upsertPatient()`, but the next API refresh loads page 1 (10 latest patients). New patient is on page 1, but older patients shift to page 2+ and disappear.
- **Evidence**: `WorkspaceController.php` line: `$apiResult = $this->getApiPatientRepo()->paginated(10, $page, $status);`
- **Affected Files**: `app/Http/Controllers/WorkspaceController.php`, `resources/js/Composables/useWorkspace.js`
- **Impact**: Patients "disappear" from the sidebar list. User must manually navigate to page 2+ or restart the app.
- **Fix**: Increase per_page to 100 or implement proper infinite scroll.

### B002: Files Beyond 50 Are Invisible
- **S**: Critical | **C**: Data | **P**: 1
- **Root Cause**: `WorkspaceController::patientData()` loads all files but returns only 50 via `array_slice($allFiles, 0, 50)`.
- **Evidence**: `WorkspaceController.php`: `$files = array_slice($allFiles, 0, 50);`
- **Affected Files**: `app/Http/Controllers/WorkspaceController.php`
- **Impact**: If a patient has 100 files, only 50 are displayed. No "load more" button. Files 51-100 are completely inaccessible.
- **Fix**: Remove the slice and implement lazy-loading via the category endpoint.

### B003: Offline SyncMiddleware Returns Success Without Saving Locally
- **S**: Critical | **C**: Sync | **P**: 1
- **Root Cause**: `SyncMiddleware::handle()` when offline enqueues the operation but does NOT save to local SQLite. The controller never runs.
- **Evidence**: `app/Http/Middleware/SyncMiddleware.php`: Returns `$offlineResponse` with `success: true` without calling controller.
- **Affected Files**: `app/Http/Middleware/SyncMiddleware.php`
- **Impact**: User performs an operation (creates patient, adds note) while offline. UI shows success. Data is queued but not in SQLite. If user refreshes or navigates away, data is gone. Only appears after sync completes.
- **Fix**: Save to local SQLite via Eloquent model before returning offline response.

### B004: Note/Vista Offline Fallback in UI Is Dead Code
- **S**: Critical | **C**: Logic | **P**: 1
- **Root Cause**: In `DoctorWorkspace.vue`, `submitNoteForm()` has an offline fallback that retries the SAME axios call that just failed with a network error.
- **Evidence**: `DoctorWorkspace.vue`:
  ```javascript
  if (!navigator.onLine || e?.code === 'ERR_NETWORK') {
      await axios.post('/api/v1/patients/' + ...)  // SAME CALL
  }
  ```
- **Affected Files**: `resources/js/Pages/DoctorWorkspace.vue`
- **Impact**: Offline note/visit creation NEVER works. The fallback retries the same failing call instead of saving locally.
- **Fix**: Check offline status BEFORE making the API call. Use a local-saving path when offline.

### B005: Double Sync Enqueue from HybridRepo + Observer
- **S**: Critical | **C**: Sync | **P**: 1
- **Root Cause**: When `HybridPatientFileRepository` or `HybridPatientNoteRepository` falls back to local create (API failure), the Eloquent model's `created` event fires the Observer (`PatientFileObserver`). Both the HybridRepo and Observer call `SyncQueueService::enqueueOperation()`. The dedup check may not catch it due to timing.
- **Evidence**: 
  - `app/Repositories/Hybrid/HybridPatientFileRepository.php` (if exists)
  - `app/Observers/PatientFileObserver.php::created()`
- **Affected Files**: All Hybrid repositories, All Observers
- **Impact**: Duplicate sync queue items. Server receives two create/update/delete requests for the same resource. May cause duplicate records or 404 on second request.
- **Fix**: Either Observers handle sync exclusively (remove from HybridRepo) or HybridRepo fires sync exclusively (disable observers during local fallback).

---

## HIGH BUGS

### B006: Patient Update Race — Local Edit Overwritten by Stale Refresh
- **S**: High | **C**: Data | **P**: 2
- **Root Cause**: `updatePatient()` in useWorkspace calls `refreshWorkspaceData()` after the update. If another refresh is in progress (from sync-completed event, PTR, etc.), the dedup guard returns the in-progress promise. That in-progress refresh may have stale data that overwrites the just-updated data.
- **Evidence**: `useWorkspace.js` `updatePatient()` and `refreshWorkspaceData()` dedup logic.
- **Affected Files**: `resources/js/Composables/useWorkspace.js`
- **Impact**: User edits a patient. The edit saves but the UI reverts to old data. User thinks edit failed and repeats it.
- **Fix**: Use request-specific dedup (track by patient UUID), not global flag.

### B007: Unbounded Memory Growth from Tracking Sets
- **S**: High | **C**: Performance | **P**: 2
- **Root Cause**: `locallyCreatedPatients`, `locallyAddedFileUuids`, `locallyAddedNoteUuids` Sets in useWorkspace grow without bound. Failed/abandoned operations are never cleaned.
- **Evidence**: `useWorkspace.js` module-level Sets with no cleanup.
- **Affected Files**: `resources/js/Composables/useWorkspace.js`
- **Impact**: Memory leak over long sessions. With heavy offline usage, Sets can grow to thousands of entries.
- **Fix**: Add periodic cleanup, limit Set size, or move tracking to SQLite.

### B008: Missing PatientObserver — No Sync for Local-Only Patient Operations
- **S**: High | **C**: Sync | **P**: 2
- **Root Cause**: There is no `PatientObserver`. When a patient is created/updated/deleted via `EloquentPatientRepository` directly (not through HybridRepo), no sync is enqueued.
- **Evidence**: Only `PatientFileObserver` and `PatientNoteObserver` exist. No `PatientObserver`.
- **Affected Files**: Missing file
- **Impact**: Patients created through certain code paths never sync to the server. Inconsistent data between mobile and website.
- **Fix**: Create `PatientObserver` with create/update/delete handlers that enqueue sync operations.

### B009: Cascade Sync Missing for Patient Deletion
- **S**: High | **C**: Sync | **P**: 2
- **Root Cause**: When a patient is deleted, only the patient delete is enqueued. No enqueue for associated files, notes, or visits.
- **Evidence**: `HybridPatientRepository::delete()` just calls `$this->localRepo->delete($uuid)` and enqueues 'Patient' delete.
- **Affected Files**: `app/Repositories/Hybrid/HybridPatientRepository.php`
- **Impact**: After patient deletion syncs, child records remain orphaned on the server. Next metadata sync re-fetches them, causing database inconsistency.
- **Fix**: Enqueue cascade deletes for all child records when a patient is deleted.

### B010: `syncAndRefresh` Skips Page Parameter from Sidebar PTR
- **S**: High | **C**: UI | **P**: 2
- **Root Cause**: Sidebar PTR calls `syncAndRefresh()` without page parameter (defaults to 1). Main workspace PTR passes `patientsMeta.value?.current_page || 1`.
- **Evidence**: `PatientListSidebar.vue`: `await syncAndRefresh()`
- **Affected Files**: `resources/js/Components/workspace/PatientListSidebar.vue`
- **Impact**: Pulling to refresh from sidebar always resets to page 1, even if viewing page 3.
- **Fix**: Pass current page to sidebar's syncAndRefresh call.

---

## MEDIUM BUGS

### B011: NetworkStatusService Cache Causes 60-Second Stale State
- **S**: Medium | **C**: Performance | **P**: 3
- **Root Cause**: `NetworkStatusService::isOnline()` caches result for 60 seconds. After connectivity loss, the app still thinks it's online and makes failing API calls.
- **Evidence**: `app/Services/NetworkStatusService.php`: `Cache::put($cacheKey, $online, $online ? 60 : 15);`
- **Affected Files**: `app/Services/NetworkStatusService.php`
- **Impact**: 60 seconds of wasted API calls after network loss. Each failed call triggers error handling overhead.
- **Fix**: Reduce cache TTL to 5-10 seconds.

### B012: Lock Stuck for 5 Minutes After Sync Crash
- **S**: Medium | **C**: Sync | **P**: 3
- **Root Cause**: `SyncQueueService::acquireLock()` has a 300-second TTL before stale locks are released.
- **Evidence**: `app/Services/SyncQueueService.php`: `private const LOCK_TTL = 300;`
- **Affected Files**: `app/Services/SyncQueueService.php`
- **Impact**: If sync crashes, no sync runs for 5 minutes. Accumulating queue items, stale data.
- **Fix**: Reduce TTL to 30 seconds and implement heartbeat.

### B013: ShallowRef + Deep Mutation Breaks Reactivity
- **S**: Medium | **C**: UI | **P**: 3
- **Root Cause**: `workspaceData` is `shallowRef`. Code mutates nested arrays without triggering reactivity. The workaround `workspaceData.value = { ...workspaceData.value }` is used inconsistently.
- **Evidence**: `useWorkspace.js`: `const workspaceData = shallowRef(null);` then `workspaceData.value.files = [...]` and later `workspaceData.value = { ...workspaceData.value }`
- **Affected Files**: `resources/js/Composables/useWorkspace.js`
- **Impact**: Intermittent UI failures — files or notes appear missing because the reactive system didn't detect the change.
- **Fix**: Use `ref` instead of `shallowRef`, or ensure ALL mutations use the spread restore pattern.

### B014: Duplicate Code in FullSyncService and SyncManager
- **S**: Medium | **C**: Architecture | **P**: 3
- **Root Cause**: Two sync engines with ~80% code duplication. Maintenance nightmare.
- **Evidence**: Compare `app/Services/FullSyncService.php` and `app/Services/Sync/SyncManager.php` — identical push methods.
- **Affected Files**: `app/Services/FullSyncService.php`, `app/Services/Sync/SyncManager.php`
- **Impact**: Bug fix in one engine is missed in the other. Different behavior for the same operation.
- **Fix**: Deprecate SyncManager, consolidate into FullSyncService.

### B015: Fetch with per_page=1000 for All Patients
- **S**: Medium | **C**: Performance | **P**: 3
- **Root Cause**: `ApiPatientRepository::all()` requests 1000 patients per page. For a practice with thousands of patients, this loads everything into memory.
- **Evidence**: `app/Repositories/Api/ApiPatientRepository.php`: `$body = $this->apiCall('GET', '/patients', ['per_page' => 1000])->json() ?? [];`
- **Affected Files**: `app/Repositories/Api/ApiPatientRepository.php`
- **Impact**: High memory usage, slow sync for large practices.
- **Fix**: Use paginated fetching instead of all().

### B016: Periodic Sync Fires Every 2 Minutes Regardless of User Activity
- **S**: Medium | **C**: Performance | **P**: 3
- **Root Cause**: `app.js` starts periodic sync every 2 minutes: `setInterval(..., 120000)`
- **Evidence**: `resources/js/app.js`
- **Impact**: Unnecessary network calls, battery drain on mobile, server load.
- **Fix**: Only sync when user is active or use visibility-based triggering.

### B017: No Soft-Delete Awareness in Metadata Pull
- **S**: Medium | **C**: Sync | **P**: 3
- **Root Cause**: When pulling metadata, sync creates/updates records but never soft-deletes records that were removed remotely.
- **Evidence**: `FullSyncService::syncLocalCache()` uses `updateOrCreate` — never `delete`.
- **Affected Files**: `app/Services/FullSyncService.php`
- **Impact**: Records that are deleted on the server persist indefinitely in local SQLite.
- **Fix**: After full sync, soft-delete local records not present in API response.

### B018: DoctorIsolationScope Subquery Performance on SQLite
- **S**: Medium | **C**: Performance | **P**: 3
- **Root Cause**: The scope uses nested subqueries (`WHERE id IN (SELECT patient_id FROM patient_shares WHERE ...)`) which are slow on SQLite with large datasets.
- **Evidence**: `app/Domains/Auth/Scopes/DoctorIsolationScope.php`
- **Affected Files**: `app/Domains/Auth/Scopes/DoctorIsolationScope.php`
- **Impact**: Slow patient list loading on devices with thousands of shared patients.
- **Fix**: Use JOIN instead of subquery, or cache share IDs.

---

## LOW BUGS

### B019: No Queue Priority Escalation
- **S**: Low | **C**: Sync | **P**: 4
- **Root Cause**: Failed queue items keep the same priority. Critical operations don't get retried faster.
- **Evidence**: `SyncQueueService::enqueueOperation()` — priority is fixed.
- **Affected Files**: `app/Services/SyncQueueService.php`
- **Fix**: Increase priority on retry.

### B020: Cache Lock Not Released on PHP Fatal Error
- **S**: Low | **C**: Sync | **P**: 4
- **Root Cause**: The lock is released in `finally` block of `syncMetadataOnly()`. But a PHP fatal error (out of memory, max execution time) prevents finally from running.
- **Evidence**: `app/Services/FullSyncService.php`
- **Affected Files**: `app/Services/FullSyncService.php`
- **Fix**: Use shutdown function or process-based lock.

### B021: Direct Model Usage in Controllers (Bypasses Repository)
- **S**: Low | **C**: Architecture | **P**: 4
- **Root Cause**: Mobile controllers (NoteController, FileController, PatientController) use `Patient::create()`, `PatientNote::create()`, etc. directly.
- **Evidence**: `app/Http/Controllers/Api/Mobile/NoteController.php`, `FileController.php`, `PatientController.php`
- **Affected Files**: Mobile controller files
- **Impact**: Repository abstraction is violated. Some operations go through Hybrid logic, some bypass it.
- **Fix**: Use repository interfaces in mobile controllers.

### B022: Patient Code Generated Without Transaction
- **S**: Low | **C**: Data | **P**: 4
- **Root Cause**: Code generation loop checks existence then inserts. Two concurrent requests can get the same code.
- **Evidence**: `WorkspaceController::storePatient()`: `do { $validated['code'] = random_int(...); } while (exists);`
- **Affected Files**: `app/Http/Controllers/WorkspaceController.php`
- **Impact**: Rare duplicate code error.
- **Fix**: Wrap in DB transaction with lock.

### B023: Token in Session and DB Can Desync
- **S**: Low | **C**: Auth | **P**: 4
- **Root Cause**: Token stored in session AND sync_states table. If one is updated without the other, they desync.
- **Evidence**: `ApiService::setToken()` updates both session and DB, but exception handling in one path may skip the other.
- **Affected Files**: `app/Services/Mobile/ApiService.php`
- **Fix**: Single source of truth for token (DB), use session as cache only.

### B024: Missing Migration for Queue Item Cleanup Schedule
- **S**: Low | **C**: Operations | **P**: 4
- **Root Cause**: No scheduled job to clean up old sync_queue items.
- **Evidence**: No command registered in `Kernel.php` for `clearSyncedOperations`.
- **Affected Files**: `app/Console/Kernel.php`
- **Impact**: sync_queue table grows without bound.
- **Fix**: Schedule `clearSyncedOperations` and `clearPermanentlyFailed` in Kernel.

### B025: Print/Export Routes Use Web API Session, Not Token Auth
- **S**: Low | **C**: Auth | **P**: 4
- **Root Cause**: `handlePrint()` opens `window.open('/api/v1/workspace/{uuid}/print')`. This uses web session auth, not the API token. If the session expires but token is valid, print fails.
- **Evidence**: `DoctorWorkspace.vue`: `window.open('/api/v1/workspace/...print', '_blank')`
- **Affected Files**: `resources/js/Pages/DoctorWorkspace.vue`
- **Impact**: Print/export fails silently when session expires.
- **Fix**: Use token-based auth for print routes.
