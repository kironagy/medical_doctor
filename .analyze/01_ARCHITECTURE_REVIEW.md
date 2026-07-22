# Architecture Review

## 1. HIGH-LEVEL ARCHITECTURE

### Current Architecture: "HTTP-Proxy Offline-First" (Broken)

```
Browser (Vue) → HTTP API (Laravel) → HybridRepository → { API (MySQL) | Eloquent (SQLite) }
                                        ↕
                                  SyncQueue → BackgroundSync → Remote API
                                        ↕
                                  Observers (auto-enqueue)
```

The application attempts an Offline-First architecture but actually implements an **API-First with Offline Fallback** pattern. The critical flaw: **the Vue frontend never reads from SQLite directly**. It always calls HTTP API endpoints, which internally decide whether to hit MySQL or SQLite based on network status.

### What the Architecture Should Be

```
Browser (Vue) → SQLite (Local DB) ← Background Sync → Remote API (MySQL)
                     ↕
            Service Workers / Cache API (PWA)
```

### Source of Truth Analysis

| Data | Current Source of Truth | Should Be |
|------|------------------------|-----------|
| Patient List | REST API response (HTTP) | SQLite (local DB) |
| Files/Notes | REST API response (HTTP) | SQLite (local DB) |
| Sync Queue | SQLite sync_queue table | SQLite sync_queue table (OK) |
| Active Workspace | Memory (workspaceData shallowRef) | Memory (OK - ephemeral) |
| Pending Local Creates | Tracking Sets (memory) | SQLite "local-only" flag |

**The fundamental problem**: Both the frontend AND backend have "smart" routing logic that decides where to read/write based on network status. This creates 4 possible states:
- Online: HTTP → HybridRepo → API → MySQL (writes) + syncLocalCache → SQLite
- Online (API fails): HTTP → HybridRepo → Eloquent → SQLite + enqueue sync
- Offline: HTTP → Eloquent → SQLite
- Offline (window.online=true but no actual connectivity): Mixed behavior

---

## 2. LAYER-BY-LAYER ANALYSIS

### 2.1 Vue Frontend (resources/js/)

#### Composables
- **useWorkspace.js**: Central state manager. Contains ALL workspace logic (600+ lines). Violates Single Responsibility.
- **useSyncState.js**: Sync status polling. Module-level refs shared across instances. Good pattern.
- **usePullToRefresh.js**: Reusable PTR. Good implementation.

**Problems:**
- `workspaceData` is `shallowRef` but mutated deeply throughout
- `patients.value` overwritten by both Inertia props AND API responses (race)
- Tracking Sets (`locallyCreatedPatients`, etc.) grow unbounded
- No debouncing on API calls from rapid user actions
- `syncAndRefresh()` calls `/api/native/sync` and then `refreshPatientList()` via API — this is 2 API calls when 1 should suffice

#### Pages
- **DoctorWorkspace.vue**: 700+ lines. Too much logic in page component.
  - Contains note CRUD, visit CRUD, archive/restore/delete, print/export actions
  - Duplicates logic already in useWorkspace (archive/restore/delete)
  - Connectivity watcher triggers sync+refresh on every transition
  
- **AppLayout.vue**: 350+ lines. Contains sidebar, mobile nav, sync indicators.
  - `triggerSync()` in AppLayout calls `useWorkspace().syncAndRefresh()` — competes with DoctorWorkspace's sync trigger

**Lifecycle Race:**
1. DoctorWorkspace mounts → sets patients from Inertia props
2. DoctorWorkspace onMounted calls syncAndRefresh() after 100ms
3. Meanwhile, AppLayout also may trigger sync on mount
4. Both hit useWorkspace's dedup guard, but the dedup is per-function, not per-session

### 2.2 Laravel Backend

#### Repository Layer — Three Implementations:

**a) EloquentRepositories** — Read/write local SQLite
- Simple, direct, reliable
- Used as fallback when offline

**b) ApiRepositories** — Call remote API
- Use `MakesApiRequests` trait for auth + retry
- Good: Token refresh on 401
- Bad: Direct HTTP calls from mobile to remote API — no proxy layer

**c) HybridRepositories** — "Smart" router
- Decision logic: `if (NetworkStatusService::isOnline()) { try API } else { local }`
- **Critical Problem**: `create()` calls API first, then saves locally. If API succeeds, great. If API fails:
  1. Falls back to local create
  2. Local create fires Eloquent event → Observer fires → enqueues sync
  3. HybridRepo also enqueues sync
  4. **Result: Double sync queue items** for the same operation

#### Controller Layer

**WorkspaceController:**
- `patientData()` loads files with `array_slice($allFiles, 0, 50)` — **SILENTLY DROPS FILES**
- `patientList()` hardcodes `per_page=10` — **Patients on page 2+ are invisible**
- Patient data loading makes 4 sequential DB queries (patient → files → notes → visits)

**Mobile Controllers (PatientController, NoteController, FileController):**
- Check `NativePhp::isRunning() && NetworkStatusService::isOnline()` before every action
- When both true: proxy to remote API
- When NativePHP but offline: hit local SQLite
- **Problem**: Non-NativePHP requests always hit local SQLite (DoctorIsolationScope filters)
- **Problem**: The NativePHP check duplicates the HybridRepo logic

#### Sync Layer — THREE Parallel Sync Engines:

**a) FullSyncService:**
- `syncMetadataOnly()`: Fetch all patients, files, notes, visits from remote API
- `syncAll()`: Full metadata sync
- `syncPendingOperations()`: Push local queue items to remote
- `downloadFileBinary()`: Download file from remote

**b) SyncManager:**
- `pullMetadata()`: Similar to FullSyncService but with pagination
- `pushPending()`: Same as pushQueueItem in FullSyncService
- **99% code duplication with FullSyncService**

**c) BackgroundSyncService:**
- Wraps FullSyncService with debounce and online check
- `run()`: Sync metadata only
- `runFull()`: Use SyncManager.pullMetadata()

**d) SyncQueueService:**
- Manages the sync_queue table
- Dedup by record_uuid + operation (partial — only checks pending)
- Dependency ordering (patients before child records)
- Lock mechanism with 300-second TTL

#### Observers

**PatientFileObserver:**
- `created()`: Enqueues create sync + dispatches video optimization
- `deleted()`: Enqueues delete sync
- Dedup check prevents double enqueue

**PatientNoteObserver:**
- `created()`, `updated()`, `deleted()`: Enqueue sync
- Updated only fires on content change (good)
- No dedup check (but not needed since syncQueue dedups)

**Missing Observers:**
- No PatientObserver for create/update/delete!
- Patient sync relies entirely on HybridRepository or mobile controller enqueuing
- If a patient is created via EloquentPatientRepository directly (not Hybrid), no sync is enqueued

### 2.3 Database Layer

**SQLite (Local):**
- Full schema mirroring MySQL
- sync_states table for: lock, api_token, credentials, counters
- sync_queue table for pending operations
- DoctorIsolationScope applied globally

**MySQL (Remote):**
- Same schema structure
- Accessed via REST API through HybridRepo or Mobile Controllers

### 2.4 Middleware

**SyncMiddleware:**
- Intercepts mobile API write requests
- Online: passes through
- Offline: enqueues and returns success (no controller execution)
- **Problem**: When offline, returns `success: true` without actually saving locally. The controller never runs. So the write is queued but the local SQLite is NEVER updated until sync completes. This means the UI shows stale data even though the user thinks the operation succeeded.

**DoctorIsolationScope:**
- Applies to all Patient/PatientFile/PatientNote queries
- Filters by doctor's own patients + shared patients
- Uses subqueries that may not perform well on SQLite

---

## 3. KEY ARCHITECTURAL FLAWS

### Flaw 1: HTTP API as Data Source (Not SQLite)
**Problem**: The Vue frontend calls HTTP endpoints to read patient data instead of querying SQLite directly.
**Impact**: When online, data comes from MySQL. When offline, from SQLite. Two different data sources = inconsistent behavior.
**Fix**: Vue should read directly from SQLite (via local API) and use background sync for bidirectional consistency.

### Flaw 2: Duplicate Sync Enqueueing
**Problem**: Both HybridRepository AND Observers enqueue sync operations for the same event.
**Impact**: Double queue items, wasted bandwidth, potential duplicate records on server.
**Fix**: One should enqueue. Either controllers use HybridRepo and skip observers, or controllers go direct to Eloquent and let observers handle sync.

### Flaw 3: Missing Patient Observer
**Problem**: Patient creates/updates/deletes via EloquentPatientRepository don't enqueue sync. Only HybridPatientRepository does.
**Impact**: If a patient is created locally (via mobile controller without NativePHP), it never syncs.
**Fix**: Add PatientObserver that mirrors PatientFileObserver/PatientNoteObserver.

### Flaw 4: SyncMiddleware Bypasses Local Save
**Problem**: Offline writes via SyncMiddleware return success:true but don't save to local SQLite.
**Impact**: User thinks operation succeeded, but next SQLite query shows old data. Page refresh shows no change.
**Fix**: Middleware should save to local SQLite AND enqueue for remote sync.

### Flaw 5: 50-File Hard Limit
**Problem**: `patientData()` loads all files but returns only 50 via `array_slice`.
**Impact**: Patients with >50 files silently lose access to extra files. No pagination in UI.
**Fix**: Implement proper pagination or increase limit with lazy loading.

### Flaw 6: 10-Patient Pagination
**Problem**: `patientList()` uses `per_page=10` hardcoded. The frontend `refreshPatientList()` doesn't specify per_page.
**Impact**: Doctors with >10 patients see only recent 10. New patients on page 2+ are invisible.
**Fix**: Increase per_page or implement search-based filtering instead of pagination.

### Flaw 7: Unbounded Tracking Sets
**Problem**: `locallyCreatedPatients`, `locallyAddedFileUuids`, `locallyAddedNoteUuids` in useWorkspace grow forever.
**Impact**: Memory leak over long sessions. Sets contain UUIDs of failed operations that never get cleaned.
**Fix**: Periodic cleanup or move tracking to SQLite.

### Flaw 8: No Cascade Sync for Patient Deletion
**Problem**: When a patient is deleted, only the patient record is synced. Child records (files, notes, visits) remain on the server.
**Impact**: Orphaned records on server. Next sync tries to re-download them, recreating the patient locally.
**Fix**: On patient delete, enqueue delete for all child records in sync_queue.

### Flaw 9: Multiple Refresh Triggers
**Problem**: Data refresh triggered from:
1. sync-completed event (app.js periodic)
2. Pull-to-refresh (sidebar + workspace)
3. DoctorWorkspace onMounted  
4. AppLayout connectivity change
5. DoctorWorkspace watch(isOnline)
6. Manual refreshPatientList() calls

**Impact**: Race conditions, excessive API calls, UI flickering.
**Fix**: Single refresh orchestrator with proper debouncing and dedup.

### Flaw 10: ShallowRef + Deep Mutation
**Problem**: `workspaceData` is shallowRef but code does `workspaceData.value.files.push()`, `workspaceData.value.files = filtered`, etc.
**Impact**: Reactivity breaks silently. UI doesn't update despite data changes.
**Fix**: Use `ref` (deepRef) or always reassign entire object via spread.
