# Master Task List

> Generated from: 00_EXECUTIVE_SUMMARY.md, 01_ARCHITECTURE_REVIEW.md, 02_BUSINESS_LOGIC_ANALYSIS.md,
> 03_PERFORMANCE_ANALYSIS.md, 04_SYNCHRONIZATION_ANALYSIS.md, 05_BUG_CATALOG.md,
> 06_TASKS.md, 07_PRODUCTION_ROADMAP.md

---

## Legend

| Field | Meaning |
|-------|---------|
| **Source** | Analysis files where this issue was identified |
| **RT** | Root cause trace — the underlying architectural decision that creates this bug |
| **Complexity** | S (<2h), M (2-8h), L (1-3d), XL (3-5d) |
| **Depends** | Tasks that must be completed before this one |

---

## Overall Progress

**100% Complete**

**26 / 26 Tasks** ✅

---

## ── PHASE 1: ROOT CAUSE (Architecture) ──

---

### T001: Consolidate Competing Sync Engines ✅

| Field | Value |
|---|---|
| **Source** | 01_ARCHITECTURE_REVIEW.md §2.2 (Sync Layer), 04_SYNCHRONIZATION_ANALYSIS.md §2, 05_BUG_CATALOG.md B014 |
| **RT** | FullSyncService, SyncManager, and BackgroundSyncService are ~80% code duplicates. Different callers use different engines, producing inconsistent behavior. |
| **Problem** | Three sync engines exist. FullSyncService and SyncManager have identical push methods (pushPatientToRemote, pushFileToRemote, pushNoteToRemote, pushVisitToRemote). BackgroundSyncService.run() calls FullSyncService but runFull() calls SyncManager. Lock contention between engines. Bug fixes must be duplicated. |
| **Fix Applied** | Removed SyncManager.php. Ported all unique methods (pushPendingWithDependencyOrder, pullPaginatedPatients, pullPaginatedPatientFiles/Notes/Visits) into FullSyncService. Made isSyncInProgress() self-contained. Updated BackgroundSyncService to remove runFull(). Removed SyncManager singleton registration from RepositoryServiceProvider. Removed unused import from NativeSyncController. |
| **Files Changed** | `app/Services/FullSyncService.php` (+methods), `app/Services/BackgroundSyncService.php` (-runFull), `app/Http/Controllers/NativeSyncController.php` (-import), `app/Providers/RepositoryServiceProvider.php` (-singleton), `app/Services/Sync/SyncManager.php` (DELETED), `tests/Feature/OfflineSyncTest.php` (updated assertion) |
| **Dependencies** | None |
| **Validation** | ✅ 27 Feature tests pass (79 assertions). 7 Unit tests pass (12 assertions). Single engine now for all sync operations. |
| **Complexity** | L |
| **Status** | - [x] |

---

### T002: Eliminate Double Sync Enqueue (Observer + HybridRepo) ✅

| Field | Value |
|---|---|
| **Source** | 01_ARCHITECTURE_REVIEW.md §2.2 (HybridRepository) + §2.2 (Observers), 02_BUSINESS_LOGIC_ANALYSIS.md §1 (P1), 04_SYNCHRONIZATION_ANALYSIS.md §5 (Issue 2), 05_BUG_CATALOG.md B005 |
| **RT** | When HybridRepo falls back to local create, Eloquent model events fire → Observer enqueues sync. HybridRepo ALSO enqueues sync. Dedup in SyncQueueService may not catch it in same transaction. |
| **Problem** | Two code paths enqueue the same sync operation. Result: duplicate queue items, wasted API calls, potential duplicate server records. |
| **Fix Applied** | **Option A: Observers handle all sync enqueuing.** Removed all `$this->syncQueue->enqueueOperation()` calls from HybridPatientFileRepository (upload, delete), HybridPatientNoteRepository (create, update, delete), and HybridPatientRepository (create, update, delete, restore, forceDelete). Removed unused SyncQueueService constructor injection from all three HybridRepos. Each removal has explanatory comments. PatientObserver (T003) now handles Patient sync. |
| **Files Changed** | `app/Repositories/Hybrid/HybridPatientFileRepository.php` (-sync enqueue), `app/Repositories/Hybrid/HybridPatientNoteRepository.php` (-sync enqueue), `app/Repositories/Hybrid/HybridPatientRepository.php` (-sync enqueue) |
| **Dependencies** | T003 |
| **Validation** | ✅ 27 Feature tests pass (79 assertions). Single enqueue path via Observers. |
| **Complexity** | M |
| **Status** | - [x] |

---

### T003: Create PatientObserver ✅

| Field | Value |
|---|---|
| **Source** | 01_ARCHITECTURE_REVIEW.md §2.2 (Missing Observers) + §3 (Flaw 3), 02_BUSINESS_LOGIC_ANALYSIS.md §1 (P1), 04_SYNCHRONIZATION_ANALYSIS.md §7 (Recommendation 3), 05_BUG_CATALOG.md B008 |
| **RT** | PatientFileObserver and PatientNoteObserver exist, but there is NO PatientObserver. Patient creates/updates/deletes via EloquentPatientRepository (without HybridRepo) have no sync. |
| **Problem** | Missing observer for Patient model. Patient operations through some code paths silently fail to sync to the server. |
| **Fix Applied** | Created `app/Observers/PatientObserver.php` with created/updated/deleted/restored handlers. Registered in `AppServiceProvider` via `Patient::observe(PatientObserver::class)`. Uses same dedup pattern as PatientFileObserver. Filtered payload with `Arr::except()` to avoid sending internal fields. Restore uses 'update' operation (same as PatientFileObserver). |
| **Files Changed** | New `app/Observers/PatientObserver.php`, `app/Providers/AppServiceProvider.php` (+Patient model import, +observe registration) |
| **Dependencies** | T002 |
| **Validation** | ✅ 27 Feature tests pass. Patient model now has sync enqueue on all lifecycle events. |
| **Complexity** | S |
| **Status** | - [x] |

---

### T004: Redesign Data Access Layer — Offline-First Architecture ✅

| Field | Value |
|---|---|
| **Source** | 00_EXECUTIVE_SUMMARY.md (Top Problem #1), 01_ARCHITECTURE_REVIEW.md §1 (Source of Truth) + §3 (Flaw 1) |
| **RT** | WorkspaceController read endpoints (index, patientList, patientData) all followed "API-First with Offline Fallback" — when online, data came from the production API. When offline, from SQLite. Two different databases = inconsistent behavior depending on network state. |
| **Problem** | When online, patients loaded from API → persisted to SQLite (two writes). When network flickered, same page could show API data on one visit and SQLite data on the next. Hybrid repos for child resources (files, notes, visits) tried API first on every read. |
| **Fix Applied** | Changed ALL read endpoints in WorkspaceController to read from local database (Eloquent repos) directly — no API involvement:
1. **`index()`**: Skip API-first in NativePHP mode. Read from EloquentPatientRepo::all() (SQLite). In web mode, read from Eloquent (MySQL) — same as before.
2. **`patientList()`**: Always read from EloquentPatientRepo::paginated(). Removed API fallback + auth error handling (errors now surface via writes and sync).
3. **`patientData()`**: Use `app(EloquentPatientFileRepository::class)` etc. directly for ALL child resources — bypasses Hybrid interface binding. Patient lookup also uses Eloquent directly.
4. Removed `syncPatientsLocally()` dead code — background sync handles persistence.
5. Writes (storePatient, updatePatient, deletePatient) still go through Hybrid repos for API sync. |
| **Files Changed** | `app/Http/Controllers/WorkspaceController.php` (3 methods rewritten + 1 deleted) |
| **Dependencies** | T001, T003, T008, T020 (sync must be stable before this is safe) |
| **Validation** | ✅ 27 Feature tests pass (79 assertions). Code review confirmed all reads go through Eloquent repos. Web mode behavior unchanged. |
| **Complexity** | XL |
| **Status** | - [x] |

---

## ── PHASE 2: DATA CONSISTENCY ──

---

### T005: Fix 10-Patient Pagination Limit ✅

| Field | Value |
|---|---|
| **Source** | 01_ARCHITECTURE_REVIEW.md §3 (Flaw 6), 02_BUSINESS_LOGIC_ANALYSIS.md §7 (P2), 03_PERFORMANCE_ANALYSIS.md §1.3, 05_BUG_CATALOG.md B001 |
| **RT** | `WorkspaceController::patientList()` hardcodes `per_page=10`. Frontend `refreshPatientList()` doesn't specify per_page. Patients beyond page 10 are invisible. New patients created shift older patients to page 2+. On next API refresh (returns page 1), those patients "disappear". |
| **Problem** | Doctor with 15 patients sees 10. Patients 11-15 on page 2. RefreshPatientList always fetches page 1. New patient goes to top of page 1, pushing patient 10 to page 2 — it disappears from view. |
| **Fix Applied** | Changed `paginated(10, ...)` to `paginated(100, ...)` in both API and Eloquent fallback paths in `WorkspaceController::patientList()`. Updated auth error `per_page` meta to 100. |
| **Files Changed** | `app/Http/Controllers/WorkspaceController.php` (3 lines) |
| **Dependencies** | None |
| **Validation** | Create 25+ patients. Verify all appear in sidebar list without pagination hiding them. |
| **Complexity** | S |
| **Status** | - [x] |

---

### T006: Remove 50-File Hard Limit ✅

| Field | Value |
|---|---|
| **Source** | 01_ARCHITECTURE_REVIEW.md §3 (Flaw 5), 02_BUSINESS_LOGIC_ANALYSIS.md §6 (P1), 03_PERFORMANCE_ANALYSIS.md §1.1, 05_BUG_CATALOG.md B002 |
| **RT** | `WorkspaceController::patientData()` does `$files = array_slice($allFiles, 0, 50)`. Files 51+ are silently invisible with no pagination UI. |
| **Problem** | Patient with 100 files only sees 50. No "load more" button. Files 51-100 are completely inaccessible through the UI. |
| **Fix Applied** | Changed `$files = array_slice($allFiles, 0, 50)` to `$files = $allFiles` in `patientData()`. Kept `array_slice($allFiles, 0, 5)` for `recent_uploads` in stats widget. Cleaned up redundant profiling log field. CategoryBlock.vue already has server-side paginated lazy loading via category endpoint. |
| **Files Changed** | `app/Http/Controllers/WorkspaceController.php` (2 lines) |
| **Dependencies** | None |
| **Validation** | ✅ Tests pass (27 Feature tests, 79 assertions). Code review confirmed clean. CategoryBlock lazy loading verified. |
| **Complexity** | M |
| **Status** | - [x] |

---

### T007: Add Cascade Sync for Patient Deletion + Soft-Delete Awareness in Pull ✅

| Field | Value |
|---|---|
| **Source** | 01_ARCHITECTURE_REVIEW.md §3 (Flaw 8), 02_BUSINESS_LOGIC_ANALYSIS.md §3 (P1), 04_SYNCHRONIZATION_ANALYSIS.md §5 (Issue 4), 05_BUG_CATALOG.md B009, B017 |
| **RT** | (1) No cascade on patient delete — child records orphaned. (2) No soft-delete propagation — remotely deleted records persist locally. |
| **Fix Applied** | (1) PatientObserver::deleted() now cascade soft-deletes files, notes, visits — their observers enqueue sync. (2) FullSyncService::syncMetadataOnly() now soft-deletes local patients/files/notes/visits not in API response after each sync. SAFETY: Orphan detection scoped to patients with successful API fetches. Excludes records with pending/failed create syncs to prevent deleting locally-created records. fetchChildResourcesBatched always sets array keys on success (even empty), omits on failure. |
| **Files Changed** | `app/Observers/PatientObserver.php` (+cascade delete), `app/Services/FullSyncService.php` (+soft-delete awareness, +safety tracking) |
| **Dependencies** | T001, T003 |
| **Validation** | ✅ 27 Feature tests pass (79 assertions). Code review confirmed cascade + soft-delete with safety guards. |
| **Complexity** | L |
| **Status** | - [x] |

---

### T008: Fix SyncMiddleware to Save Locally Before Offline Queuing ✅

| Field | Value |
|---|---|
| **Source** | 01_ARCHITECTURE_REVIEW.md §2.4 (SyncMiddleware) + §3 (Flaw 4), 02_BUSINESS_LOGIC_ANALYSIS.md §4 (P1), 04_SYNCHRONIZATION_ANALYSIS.md §5 (Issue 1), 05_BUG_CATALOG.md B003 |
| **RT** | SyncMiddleware intercepts offline write requests, enqueues them to sync_queue, and returns `success: true` — but the controller NEVER runs. The data is queued but NOT saved to local SQLite. |
| **Problem** | The most critical offline bug. Every offline create/update/delete via SyncMiddleware appears to succeed but actually saves nothing to local SQLite. |
| **Fix Applied** | Replaced `$this->syncQueue->enqueueOperation()` with `$this->saveLocally()` in the offline path. Added entity-specific save methods (savePatientLocally, saveNoteLocally, saveVisitLocally) that save to local SQLite via Eloquent models — triggering observers to enqueue sync. Added `resolveParentPatientUuid()` to inject `patient_uuid` into payload for child resource creates. |
| **Files Changed** | `app/Http/Middleware/SyncMiddleware.php` (+saveLocally, +resolveParentPatientUuid, +entity save methods) |
| **Dependencies** | T001, T003 |
| **Validation** | ✅ 27 Feature tests pass (79 assertions). Code review confirmed. |
| **Complexity** | M |
| **Status** | - [x] |

---

## ── PHASE 3: SYNCHRONIZATION ROBUSTNESS ──

---

### T009: Fix Offline Note/Visit Fallback (Dead Code in DoctorWorkspace) ✅

| Field | Value |
|---|---|
| **Source** | 02_BUSINESS_LOGIC_ANALYSIS.md §5 (P2), 05_BUG_CATALOG.md B004 |
| **RT** | `submitNoteForm()` and `submitVisitForm()` have offline fallback code that retries the EXACT SAME axios.post() call that just failed. |
| **Problem** | Notes and visits created while offline are silently lost. The fallback code is dead. |
| **Fix Applied** | Added `navigator.onLine` check BEFORE both API calls. If offline: submitNoteForm() uses `addNoteLocally()` for instant UI; submitVisitForm() adds visit directly to workspaceData.visits. Removed dead-code retry logic in catch blocks. Fixed toast translation key issue. |
| **Files Changed** | `resources/js/Pages/DoctorWorkspace.vue` (submitNoteForm, submitVisitForm) |
| **Dependencies** | T008 |
| **Validation** | ✅ 27 Feature tests pass (79 assertions). Code review confirmed. |
| **Complexity** | S |
| **Status** | - [x] |

---

### T010: Fix Patient Update Race and Add Optimistic UI Rollback ✅

| Field | Value |
|---|---|
| **Source** | 01_ARCHITECTURE_REVIEW.md §3 (Flaw 9), 02_BUSINESS_LOGIC_ANALYSIS.md §2 (P1, P2), 05_BUG_CATALOG.md B006 |
| **RT** | (1) `updatePatient()` updates local list immediately before API confirmation, with no rollback on failure. (2) `updatePatient()` calls `refreshWorkspaceData()` after update. If another refresh is in progress (sync-completed, PTR), the dedup guard returns the in-progress promise with STALE data that overwrites the just-applied edit. |
| **Fix Applied** | Added pre-update snapshot capture. Applied optimistic update to patients.value AND workspaceData.value.patient. On API failure: restored both to pre-update state. Removed refreshWorkspaceData() call entirely — patient edits don't change files/notes/visits, and the dedup guard race is eliminated. |
| **Files Changed** | `resources/js/Composables/useWorkspace.js` |
| **Dependencies** | None |
| **Validation** | ✅ 27 Feature tests pass. Code review confirmed. |
| **Complexity** | M |
| **Status** | - [x] |

---

### T011: Reduce Sync Lock TTL and Add Heartbeat ✅

| Field | Value |
|---|---|
| **Source** | 04_SYNCHRONIZATION_ANALYSIS.md §4 (P4), 05_BUG_CATALOG.md B012, B020 |
| **RT** | `SyncQueueService::acquireLock()` sets lock with 300-second TTL. If sync crashes (PHP fatal error, OOM), the lock stays for 5 minutes. During this time: no sync runs, queue items accumulate, user sees "sync in progress". Additionally, lock is in `finally` block — fatal errors skip `finally`. |
| **Fix Applied** | Reduced LOCK_TTL from 300s to 30s (public const). Added `touchLock()` method to extend lock during long operations. Added heartbeat calls in syncMetadataOnly() after each major phase (push, users sync, child fetch, every 10 patients). Added heartbeat inside pullPaginatedPatients() after each page and inside fetchChildResourcesBatched() after each batch. Updated isSyncInProgress() to reference the constant. |
| **Files Changed** | `app/Services/SyncQueueService.php`, `app/Services/FullSyncService.php` |
| **Dependencies** | T001 |
| **Validation** | ✅ 27 Feature tests pass. 7 Unit tests pass. Code review confirmed. |
| **Complexity** | M |
| **Status** | - [x] |

---

### T012: Fix Inconsistent Queue Status Reset ✅

| Field | Value |
|---|---|
| **Source** | 04_SYNCHRONIZATION_ANALYSIS.md §4 (P3) |
| **RT** | `processPendingOperations()` resets failed items to 'pending' BEFORE processing them. If the process crashes after this reset but before processing, items lose their `retry_count` history — they're reset to `retry_count=0` and retried as if never attempted. |
| **Fix Applied** | Removed batch status reset from `processPendingOperations()`. Moved per-item status reset into the caller (`FullSyncService::syncPendingOperations()`). Each failed item is reset to 'pending' individually right before processing. If crash happens mid-batch, only the current item loses its 'failed' marker — the rest remain 'failed' with retry_count preserved. |
| **Files Changed** | `app/Services/SyncQueueService.php`, `app/Services/FullSyncService.php` |
| **Dependencies** | T001 |
| **Validation** | ✅ 27 Feature tests pass. Code review confirmed. |
| **Complexity** | S |
| **Status** | - [x] |

---

### T013: Fix Conflict Resolver — Check Recently-Synced Records ✅

| Field | Value |
|---|---|
| **Source** | 04_SYNCHRONIZATION_ANALYSIS.md §3 (P2) |
| **RT** | `ConflictResolver::hasPendingChanges()` only checks sync_queue for 'pending' or 'failed' items. If a sync item was already processed (status='synced'), it returns false. A record synced 5 minutes ago then changed locally is NOT protected during next pull sync — remote version overwrites local changes. |
| **Fix Applied** | Added optional `$localUpdatedAt` parameter to `hasPendingChanges()`. Now checks TWO conditions: (1) existing queue item check, (2) NEW: if `client_updated_at > last_sync_at` from sync_states. Cached last_sync_at to avoid repeated DB queries. Updated both call sites (syncFilesWithLocalPatientId and syncChildRecordsWithLocalPatientId) to pass `client_updated_at`. |
| **Files Changed** | `app/Services/Sync/ConflictResolver.php`, `app/Services/FullSyncService.php` |
| **Dependencies** | T001 |
| **Validation** | ✅ 27 Feature tests pass. Code review confirmed. |
| **Complexity** | M |
| **Status** | - [x] |

---

### T014: Add Scheduled Cleanup for Sync Queue ✅

| Field | Value |
|---|---|
| **Source** | 04_SYNCHRONIZATION_ANALYSIS.md §4 (P1), 05_BUG_CATALOG.md B024 |
| **RT** | Sync queue table has no scheduled cleanup. `permanently_failed` and old `synced` items accumulate forever. `clearSyncedOperations()` and `clearPermanentlyFailed()` methods exist but are never called. |
| **Fix Applied** | Added two scheduled tasks in Console Kernel: daily at 2AM (clearSyncedOperations, 7 days), weekly on Monday at 3AM (clearPermanentlyFailed, 30 days). Both wrapped in try/catch + logging. |
| **Files Changed** | `app/Console/Kernel.php` |
| **Dependencies** | None |
| **Validation** | ✅ 27 Feature tests pass. Code review confirmed. |
| **Complexity** | S |
| **Status** | - [x] |

---

### T015: Reduce NetworkStatusService Cache TTL ✅

| Field | Value |
|---|---|
| **Source** | 03_PERFORMANCE_ANALYSIS.md §3.3, 05_BUG_CATALOG.md B011 |
| **RT** | `NetworkStatusService::isOnline()` caches online status for 60 seconds. When connectivity drops, 60 seconds of failing API calls before detection. Each failed call triggers error handling, retries, and token refresh attempts. |
| **Fix Applied** | Changed both online and offline cache TTL to 5 seconds (was 60s/15s). Both values now consistent at 5s. |
| **Files Changed** | `app/Services/NetworkStatusService.php` |
| **Dependencies** | None |
| **Validation** | ✅ 27 Feature tests pass. Code review confirmed. |
| **Complexity** | S |
| **Status** | - [x] |

---

### T016: Reduce Periodic Sync Frequency / Make Conditional ✅

| Field | Value |
|---|---|
| **Source** | 03_PERFORMANCE_ANALYSIS.md §3.1, 05_BUG_CATALOG.md B016 |
| **RT** | `app.js` starts periodic sync every 2 minutes: `setInterval(..., 120000)`. Even with no changes, 30 sync cycles per hour. Each sync triggers 3+ API calls (sync, patient list, patient data). On mobile, this drains battery and consumes data. |
| **Fix Applied** | Changed periodic sync interval from 120000ms (2 min) to 300000ms (5 min). Reduces sync cycles from 30/hour to 12/hour. |
| **Files Changed** | `resources/js/app.js` |
| **Dependencies** | None |
| **Validation** | ✅ 27 Feature tests pass. Code review confirmed. |
| **Complexity** | S |
| **Status** | - [x] |

---

## ── PHASE 4: BUSINESS LOGIC & UI ──

---

### T017: Fix ShallowRef Reactivity — Use Deep Ref ✅

| Field | Value |
|---|---|
| **Source** | 01_ARCHITECTURE_REVIEW.md §2.1 (Composables) + §3 (Flaw 10), 05_BUG_CATALOG.md B013 |
| **RT** | `workspaceData` is `shallowRef`. Code mutates nested arrays which does NOT trigger shallowRef reactivity. |
| **Fix Applied** | Changed `shallowRef` to `ref`. Removed `shallowRef` from import. Removed all `workspaceData.value = { ...workspaceData.value }` spread triggers from syncWorkspaceStats(), addFileLocally(), updateFileLocally(), addNoteLocally(), removeFileLocally(). Deep ref now detects nested mutations automatically. |
| **Files Changed** | `resources/js/Composables/useWorkspace.js` |
| **Dependencies** | None |
| **Validation** | ✅ 27 Feature tests pass. Code review confirmed. |
| **Complexity** | M |
| **Status** | - [x] |

---

### T018: Clean Up Unbounded Tracking Sets ✅

| Field | Value |
|---|---|
| **Source** | 01_ARCHITECTURE_REVIEW.md §2.1 (Composables) + §3 (Flaw 7), 02_BUSINESS_LOGIC_ANALYSIS.md §1 (P3), 03_PERFORMANCE_ANALYSIS.md §2.2, 05_BUG_CATALOG.md B007 |
| **RT** | `locallyCreatedPatients`, `locallyAddedFileUuids`, `locallyAddedNoteUuids` Sets in useWorkspace grow without bound. |
| **Fix Applied** | Added `capTrackingSet()` helper that removes oldest entries when a Set exceeds 100 items. Called before every `set.add()` for all three tracking Sets (locallyCreatedPatients, locallyAddedFileUuids, locallyAddedNoteUuids). |
| **Files Changed** | `resources/js/Composables/useWorkspace.js` |
| **Dependencies** | None |
| **Validation** | ✅ 27 Feature tests pass. Code review confirmed. |
| **Complexity** | S |
| **Status** | - [x] |

---

### T019: Fix Sidebar PTR Page Reset ✅

| Field | Value |
|---|---|
| **Source** | 02_BUSINESS_LOGIC_ANALYSIS.md §9 (P1), 05_BUG_CATALOG.md B010 |
| **RT** | Sidebar PTR calls `syncAndRefresh()` without page parameter — always resets to page 1. |
| **Fix Applied** | Changed `syncAndRefresh()` to `syncAndRefresh(patientsMeta.value?.current_page || 1)` in PatientListSidebar.vue. |
| **Files Changed** | `resources/js/Components/workspace/PatientListSidebar.vue` |
| **Dependencies** | None |
| **Validation** | ✅ 27 Feature tests pass. Code review confirmed. |
| **Complexity** | S |
| **Status** | - [x] |

---

### T020: Implement Incremental Sync (Complete IncrementalSyncService Stub) ✅

| Field | Value |
|---|---|
| **Source** | 03_PERFORMANCE_ANALYSIS.md §3.2, 04_SYNCHRONIZATION_ANALYSIS.md §6 |
| **RT** | `IncrementalSyncService` exists as a stub with `incrementalPull()` method. It calculates `$lastSyncAt` timestamp but the actual implementation is missing. Every sync cycle does a FULL fetch of ALL patients, files, notes, visits — even for records that haven't changed. For 500 patients with 10 files each = 1,500 API calls per cycle. |
| **Problem** | Every sync cycle fetches ALL data. FullSyncService fetches 1000 patients, files for each, notes for each, visits for each. No delta/timestamp filtering. Wastes bandwidth, time, and API resources. |
| **Fix Applied** | Added `?string $updatedSince = null` to `ApiPatientRepository::paginated()` and `ApiPatientFileRepository::forPatient()`, `ApiPatientNoteRepository::forPatient()`, `ApiPatientVisitRepository::forPatient()`. Rewrote `IncrementalSyncService::pullIncrementalPatients()` to use `paginated()` with `updated_since` param in pagination loop. `pullIncrementalChildResources()` passes `updatedSinceStr` to child resource API calls directly. Added `seedTimestamp()` to set last sync time without duplicate sync. `FullSyncService::syncAll()` checks `last_incremental_sync_at` — uses incremental if < 24h, full sync otherwise. After full sync, seeds timestamp instead of duplicate incremental. |
| **Files Changed** | `app/Services/Sync/IncrementalSyncService.php`, `app/Services/FullSyncService.php`, `app/Repositories/Api/ApiPatientRepository.php`, `app/Repositories/Api/ApiPatientFileRepository.php`, `app/Repositories/Api/ApiPatientNoteRepository.php`, `app/Repositories/Api/ApiPatientVisitRepository.php` |
| **Dependencies** | T001 (consolidated sync engine) |
| **Validation** | ✅ 27 Feature tests pass. Code review confirmed. Incremental sync selects < 24h or full sync; timestamp seeded without duplicate. |
| **Complexity** | L |
| **Status** | - [x] |

---

## ── PHASE 5: SECONDARY FIXES ──

---

### T021: Add Queue Priority Escalation ✅

| Field | Value |
|---|---|
| **Source** | 04_SYNCHRONIZATION_ANALYSIS.md §4 (P5), 05_BUG_CATALOG.md B019 |
| **RT** | All queue items start with priority 5. Failed items keep priority 5. Critical operations don't get higher priority after retry. |
| **Fix Applied** | Added `$item->priority = max(1, 5 - $item->retry_count)` in markItemResult() failure path. Failed items get increasingly higher priority (lower number) on each retry. |
| **Files Changed** | `app/Services/SyncQueueService.php` |
| **Dependencies** | T012 |
| **Validation** | ✅ 27 Feature tests pass. Code review confirmed. |
| **Complexity** | S |
| **Status** | - [x] |

---

### T022: Migrate Mobile Controllers to Repository Pattern ✅

| Field | Value |
|---|---|
| **Source** | 02_BUSINESS_LOGIC_ANALYSIS.md §4 (P1) + §5 (P1), 05_BUG_CATALOG.md B021 |
| **RT** | Mobile controllers bypass the repository layer, making direct Eloquent calls. |
| **Problem** | Repository pattern is inconsistently applied. Mobile controllers use `Patient::create()`, `PatientNote::where()`, `PatientVisit::update()`, etc. directly instead of repository interfaces. |
| **Fix Applied** | **PatientController**: create→`$this->patientRepo->create()`, update→`$this->patientRepo->update()`, delete→`$this->patientRepo->delete()`. **NoteController**: store→`$this->noteRepo->create()`, update→`$this->noteRepo->update()`, destroy→`$this->noteRepo->delete()`. **VisitController**: store→`$this->visitRepo->create()`, update→`$this->visitRepo->update()`, destroy→`$this->visitRepo->delete()`. **FileController**: store→`$this->fileRepo->upload()`, update→`$this->fileRepo->update()`, destroy→`$this->fileRepo->delete()`. **Infrastructure**: Added `update(string $uuid, array $data): array` to `PatientFileRepositoryInterface`. Changed `PatientVisitRepositoryInterface` signatures from `int $visitId` to `string $visitUuid` (all 3 implementations + FullSyncService updated). |
| **Files Changed** | `app/Contracts/Repositories/PatientFileRepositoryInterface.php`, `app/Contracts/Repositories/PatientVisitRepositoryInterface.php`, `app/Repositories/Eloquent/EloquentPatientFileRepository.php`, `app/Repositories/Eloquent/EloquentPatientVisitRepository.php`, `app/Repositories/Api/ApiPatientFileRepository.php`, `app/Repositories/Api/ApiPatientVisitRepository.php`, `app/Repositories/Hybrid/HybridPatientFileRepository.php`, `app/Repositories/Hybrid/HybridPatientVisitRepository.php`, `app/Services/FullSyncService.php`, `app/Http/Controllers/Api/Mobile/PatientController.php`, `app/Http/Controllers/Api/Mobile/NoteController.php`, `app/Http/Controllers/Api/Mobile/VisitController.php`, `app/Http/Controllers/Api/Mobile/FileController.php` |
| **Dependencies** | None |
| **Validation** | ✅ 27 Feature tests pass (79 assertions). Code review confirmed no `Model::create()`, `Model::update()`, `Model::delete()`, `save()`, or `forceDelete()` exists in any mobile controller. |
| **Complexity** | L |
| **Status** | - [x] |

---

### T023: Add Transactional Patient Code Generation ✅

| Field | Value |
|---|---|
| **Source** | 02_BUSINESS_LOGIC_ANALYSIS.md §1 (P2), 05_BUG_CATALOG.md B022 |
| **RT** | Patient code generation uses `do { random_int(100000, 999999); } while (exists)`. No DB transaction or lock. |
| **Fix Applied** | Wrapped code generation do-while loop in `DB::transaction()`. Provides isolation against concurrent code generation. Unique database constraint on `code` column provides safety net for edge cases. |
| **Files Changed** | `app/Http/Controllers/WorkspaceController.php` |
| **Dependencies** | None |
| **Validation** | ✅ 27 Feature tests pass. Code review confirmed. |
| **Complexity** | S |
| **Status** | - [x] |

---

### T024: Fix Frontend Search to Query API Not Just Loaded Patients ✅

| Field | Value |
|---|---|
| **Source** | 02_BUSINESS_LOGIC_ANALYSIS.md §7 (P2) |
| **RT** | `filteredPatients` computed property only filters `patients.value` — the currently loaded patients. If a matching patient is on another page, it won't appear in search results. |
| **Fix Applied** | Added `search` parameter to paginated() in both EloquentPatientRepository and ApiPatientRepository. WorkspaceController::patientList() reads and forwards `search` parameter. Frontend: debounced watcher on searchQuery (400ms) triggers refreshPatientList(1) with search param. API searches across entire dataset. |
| **Files Changed** | `app/Repositories/Eloquent/EloquentPatientRepository.php`, `app/Repositories/Api/ApiPatientRepository.php`, `app/Http/Controllers/WorkspaceController.php`, `resources/js/Composables/useWorkspace.js` |
| **Dependencies** | T005 |
| **Validation** | ✅ 27 Feature tests pass. Code review confirmed. |
| **Complexity** | M |
| **Status** | - [x] |

---

### T025: Fix Print/Export Route Auth (Use Token Not Session) ✅

| Field | Value |
|---|---|
| **Source** | 05_BUG_CATALOG.md B025 |
| **RT** | Print and export endpoints use web session authentication. New tab may not carry session (NativePHP WebView). |
| **Fix Applied** | Added `authenticateViaTokenIfNeeded()` to verify `?token=` query parameter. Moved export route to correct `/api/v1/` prefix. Added `<meta name="api-token">` in app.blade.php for authenticated users. Frontend: handlePrint() and handleExport() now read token from meta tag and pass it as query parameter. |
| **Files Changed** | `app/Http/Controllers/WorkspaceController.php`, `resources/views/app.blade.php`, `resources/js/Pages/DoctorWorkspace.vue` |
| **Dependencies** | None |
| **Validation** | ✅ 27 Feature tests pass. Code review confirmed. |
| **Complexity** | S |
| **Status** | - [x] |

---

### T026: Fix Token Session/DB Desync ✅

| Field | Value |
|---|---|
| **Source** | 02_BUSINESS_LOGIC_ANALYSIS.md §8 (P1), 05_BUG_CATALOG.md B023 |
| **RT** | API token is stored in both session AND sync_states DB table. If exception occurs during one update but not the other, the two sources desync. |
| **Fix Applied** | Changed constructor to always load from DB (removed session-first behavior). Changed setToken() to write to DB FIRST, then session. DB is now the single source of truth; session is a read-through cache. |
| **Files Changed** | `app/Services/Mobile/ApiService.php` |
| **Dependencies** | None |
| **Validation** | ✅ 27 Feature tests pass. Code review confirmed. |
| **Complexity** | S |
| **Status** | - [x] |

---

## ── EXECUTION PHASES ──

### Phase 1: Stop the Bleeding (Week 1)

| Order | Task | Effort | Why Here |
|-------|------|--------|----------|
| 1 | **T005** — Fix 10-patient pagination | S | Direct cause of "patients disappear" |
| 2 | **T006** — Remove 50-file limit | M | Direct cause of "files invisible" |
| 3 | **T001** — Consolidate sync engines | L | Root cause of sync inconsistency |
| 4 | **T002** — Eliminate double enqueue | M | Root cause of duplicate items |
| 5 | **T003** — Create PatientObserver | S | Missing sync path |

### Phase 2: Data Integrity (Week 2)

| Order | Task | Effort | Why Here |
|-------|------|--------|----------|
| 6 | **T008** — SyncMiddleware local save | M | Critical offline data loss |
| 7 | **T009** — Fix offline fallback | S | Dead code, offline ops fail |
| 8 | **T007** — Cascade + soft-delete sync | L | Orphaned records |
| 9 | **T010** — Optimistic UI + rollback | M | Stale data after edits |

### Phase 3: Sync Robustness (Week 3)

| Order | Task | Effort | Why Here |
|-------|------|--------|----------|
| 10 | **T011** — Lock TTL + heartbeat | M | 5-min sync freeze |
| 11 | **T012** — Queue status reset | S | Lost retry history |
| 12 | **T013** — Conflict resolver fix | M | Silent data overwrite |
| 13 | **T014** — Queue cleanup schedule | S | Unbounded queue growth |
| 14 | **T015** — Network cache TTL | S | Slow connectivity reaction |
| 15 | **T016** — Reduce sync frequency | S | Battery/data waste |

### Phase 4: UI & State Fixes (Week 3-4)

| Order | Task | Effort | Why Here |
|-------|------|--------|----------|
| 16 | **T017** — ShallowRef → deep ref | M | Intermittent UI freeze |
| 17 | **T018** — Clean tracking Sets | S | Memory leak |
| 18 | **T019** — Sidebar PTR page reset | S | UX regression |

### Phase 5: Performance & Secondary (Week 4-5)

| Order | Task | Effort | Why Here |
|-------|------|--------|----------|
| 19 | **T020** — Incremental sync | L | 1500 API calls per cycle |
| 20 | **T021** — Queue priority escalation | S | Critical ops stuck behind non-critical |
| 21 | **T022** — Mobile controllers to repos | L | Inconsistent data paths |
| 22 | **T023** — Transactional code gen | S | Rare duplicate codes |
| 23 | **T024** — Frontend API search | M | Search only loaded patients |
| 24 | **T025** — Print/export token auth | S | Session-only routes fail |
| 26 | **T026** — Token desync fix | S | Auth errors |

### Phase 6: Architecture Transformation (Week 6+)

| Order | Task | Effort | Why Here |
|-------|------|--------|----------|
| 25 | **T004** — Redesign data access layer | XL | True offline-first. Requires stable sync first. |

---

## SUMMARY

| Metric | Value |
|--------|-------|
| **Total Tasks** | 26 |
| **Critical** | 8 (T001, T002, T003, T005, T006, T007, T008, T009) |
| **High** | 6 (T010, T011, T012, T013, T017, T020) |
| **Medium** | 7 (T014, T015, T016, T018, T019, T022, T024) |
| **Low** | 5 (T021, T023, T025, T026, T004) |
| **Estimated Total Effort** | ~4-6 weeks full-time |
| **Estimated Person-Days** | 20-30 person-days |
