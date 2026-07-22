# Actionable Tasks

## How to Read
- **ID**: Unique task identifier
- **Priority**: Critical → High → Medium → Low
- **Complexity**: S (Small, <2h), M (Medium, 2-8h), L (Large, 1-3d), XL (Extra Large, 3-5d)
- **Depends On**: Tasks that must be completed first
- **Category**: Architecture / Sync / Data / UI / Performance / Operations

---

## CRITICAL PRIORITY

### T001: Fix 10-Patient Pagination Limit
- **Priority**: Critical | **Complexity**: S | **Category**: Data
- **Depends On**: None
- **Description**: Increase per_page from 10 to 100 in WorkspaceController::patientList(). Update frontend to handle larger lists.
- **Files**: `app/Http/Controllers/WorkspaceController.php`, `resources/js/Composables/useWorkspace.js`
- **Acceptance**: Patient list shows all patients without pagination hiding them.

### T002: Remove 50-File Slice — Implement Lazy Loading
- **Priority**: Critical | **Complexity**: M | **Category**: Data
- **Depends On**: None
- **Description**: Remove `array_slice($allFiles, 0, 50)` from patientData(). Return all files. Implement lazy-loading via category endpoint on frontend.
- **Files**: `app/Http/Controllers/WorkspaceController.php`
- **Acceptance**: All files visible. Category-based lazy loading works.

### T003: Fix SyncMiddleware to Save Locally Before Offline Queuing
- **Priority**: Critical | **Complexity**: M | **Category**: Sync
- **Depends On**: None
- **Description**: In SyncMiddleware::handle(), when offline, save the operation to local SQLite via the repository before enqueuing. Return success only after local save succeeds.
- **Files**: `app/Http/Middleware/SyncMiddleware.php`
- **Acceptance**: Offline operations save to local SQLite and appear in UI immediately.

### T004: Fix Offline Note/Visit Fallback in DoctorWorkspace
- **Priority**: Critical | **Complexity**: S | **Category**: Logic
- **Depends On**: None
- **Description**: In DoctorWorkspace.vue, check `navigator.onLine` BEFORE making the API call. If offline, save via a local-only path (or let the existing local middleware handle it).
- **Files**: `resources/js/Pages/DoctorWorkspace.vue`
- **Acceptance**: Notes and visits created offline persist in the UI.

### T005: Eliminate Duplicate Sync Enqueue (Observer + HybridRepo)
- **Priority**: Critical | **Complexity**: M | **Category**: Sync
- **Depends On**: None
- **Description**: Decide on single path for sync enqueue. Option A: Remove sync enqueue from HybridRepo, let Observers handle all sync. Option B: Disable Observers during local fallback, let HybridRepo enqueue.
- **Files**: `app/Repositories/Hybrid/*`, `app/Observers/*`
- **Acceptance**: No duplicate sync queue items for any operation.

---

## HIGH PRIORITY

### T006: Implement Optimistic UI Update with Rollback
- **Priority**: High | **Complexity**: M | **Category**: UI
- **Depends On**: None
- **Description**: Before API calls, save current state. On failure, restore saved state and show error. For updatePatient(), revert local list update on API failure.
- **Files**: `resources/js/Composables/useWorkspace.js`
- **Acceptance**: Edits that fail show original data, not partially-updated data.

### T007: Clean Up Unbounded Tracking Sets
- **Priority**: High | **Complexity**: S | **Category**: Performance
- **Depends On**: None
- **Description**: Add Set size limit (e.g., 100). Add periodic cleanup for UUIDs older than 1 hour. Or move tracking to SQLite.
- **Files**: `resources/js/Composables/useWorkspace.js`
- **Acceptance**: Tracking Sets don't grow unbounded.

### T008: Create PatientObserver
- **Priority**: High | **Complexity**: S | **Category**: Sync
- **Depends On**: None
- **Description**: Create `PatientObserver` with created/updated/deleted handlers that enqueue sync operations, mirroring PatientFileObserver and PatientNoteObserver.
- **Files**: New `app/Observers/PatientObserver.php`, register in `AppServiceProvider` or `EventServiceProvider`
- **Acceptance**: Patient creates, updates, and deletes enqueue sync operations.

### T009: Implement Cascade Delete for Patient in Sync Queue
- **Priority**: High | **Complexity**: M | **Category**: Sync
- **Depends On**: T008
- **Description**: When a patient delete is enqueued, also enqueue deletes for all associated files, notes, and visits.
- **Files**: `app/Observers/PatientObserver.php`, `app/Repositories/Hybrid/HybridPatientRepository.php`
- **Acceptance**: All child records are deleted on the server when a patient is deleted.

### T010: Fix Sidebar PTR Page Reset
- **Priority**: High | **Complexity**: S | **Category**: UI
- **Depends On**: None
- **Description**: Pass `patientsMeta.value?.current_page || 1` to syncAndRefresh in PatientListSidebar PTR.
- **Files**: `resources/js/Components/workspace/PatientListSidebar.vue`
- **Acceptance**: PTR from sidebar preserves current page.

### T011: Consolidate FullSyncService and SyncManager
- **Priority**: High | **Complexity**: L | **Category**: Architecture
- **Depends On**: None
- **Description**: Remove SyncManager. Add pagination support to FullSyncService. Update all callers to use FullSyncService.
- **Files**: `app/Services/Sync/SyncManager.php` (delete), `app/Services/FullSyncService.php` (update), `app/Services/BackgroundSyncService.php` (update)
- **Acceptance**: Single sync engine for all operations.

---

## MEDIUM PRIORITY

### T012: Reduce NetworkStatusService Cache TTL
- **Priority**: Medium | **Complexity**: S | **Category**: Performance
- **Depends On**: None
- **Description**: Change cache TTL from 60 seconds to 5-10 seconds.
- **Files**: `app/Services/NetworkStatusService.php`
- **Acceptance**: Faster reaction to network state changes.

### T013: Reduce Sync Lock TTL and Add Heartbeat
- **Priority**: Medium | **Complexity**: M | **Category**: Sync
- **Depends On**: T011
- **Description**: Reduce LOCK_TTL from 300 to 30 seconds. In sync loop, periodically update lock timestamp so long-running syncs don't get preempted.
- **Files**: `app/Services/SyncQueueService.php`, `app/Services/FullSyncService.php`
- **Acceptance**: Lock releases within 30 seconds of crash. Long syncs maintain lock.

### T014: Fix ShallowRef Reactivity — Use Deep Ref
- **Priority**: Medium | **Complexity**: M | **Category**: UI
- **Depends On**: None
- **Description**: Change `workspaceData` from `shallowRef` to `ref`. Remove manual spread triggers. Audit all mutations for proper reactive handling.
- **Files**: `resources/js/Composables/useWorkspace.js`
- **Acceptance**: UI updates consistently after data changes.

### T015: Soft-Delete Unreturned Records After Pull
- **Priority**: Medium | **Complexity**: M | **Category**: Sync
- **Depends On**: T011
- **Description**: After full metadata pull, collect UUIDs from API response. Soft-delete local records whose UUIDs are not in the response.
- **Files**: `app/Services/FullSyncService.php`
- **Acceptance**: Remotely deleted records are also deleted locally after sync.

### T016: Add Scheduled Cleanup for Sync Queue
- **Priority**: Medium | **Complexity**: S | **Category**: Operations
- **Depends On**: None
- **Description**: Schedule `clearSyncedOperations` and `clearPermanentlyFailed` in Console\Kernel.
- **Files**: `app/Console/Kernel.php`
- **Acceptance**: Old queue items cleaned automatically.

### T017: Reduce Periodic Sync Frequency
- **Priority**: Medium | **Complexity**: S | **Category**: Performance
- **Depends On**: None
- **Description**: Change periodic sync interval from 2 minutes to 5 minutes, or add condition to only sync when changes are pending.
- **Files**: `resources/js/app.js`
- **Acceptance**: Fewer unnecessary sync cycles.

---

## LOW PRIORITY

### T018: Add Queue Priority Escalation
- **Priority**: Low | **Complexity**: S | **Category**: Sync
- **Depends On**: None
- **Description**: Increase priority of queue items that have failed and been retried.
- **Files**: `app/Services/SyncQueueService.php`
- **Acceptance**: Failed items get higher priority on retry.

### T019: Migrate Mobile Controllers to Use Repository Pattern
- **Priority**: Low | **Complexity**: L | **Category**: Architecture
- **Depends On**: None
- **Description**: Update NoteController, FileController, PatientController to use repository interfaces instead of direct model calls.
- **Files**: Mobile controller files
- **Acceptance**: Mobile controllers use repositories consistently.

### T020: Implement Incremental Sync
- **Priority**: Low | **Complexity**: L | **Category**: Sync
- **Depends On**: T011
- **Description**: Complete `IncrementalSyncService` — only fetch records changed since last sync timestamp.
- **Files**: `app/Services/Sync/IncrementalSyncService.php`
- **Acceptance**: Sync only fetches changed records.

### T021: Add Transactional Lock for Patient Code Generation
- **Priority**: Low | **Complexity**: S | **Category**: Data
- **Depends On**: None
- **Description**: Wrap code generation and patient creation in a DB transaction with row-level lock.
- **Files**: `app/Http/Controllers/WorkspaceController.php`
- **Acceptance**: No duplicate patient codes under concurrent load.
