# 07 — Architecture Audit

> **Purpose**: Verify the final architecture matches the target "Offline-First" design.
> **Reviewer**: Automated codebase analysis.

---

## Audit Criteria

| # | Criterion | Target | Current State | Score |
|---|-----------|--------|---------------|-------|
| 1 | **Single Source of Truth** | Local SQLite reads only | ✅ WorkspaceController reads from Eloquent repos (SQLite/MySQL) only | 10/10 |
| 2 | **No Duplicate Sync Engines** | One sync engine | ✅ SyncManager removed in T001; FullSyncService is single engine | 10/10 |
| 3 | **Offline-First Reads** | All reads from local | ✅ index(), patientList(), patientData() all use Eloquent repos | 10/10 |
| 4 | **Controller Thinness** | No business logic in controllers | ⚠️ Mobile controllers have some logic (file type detection, code gen) | 7/10 |
| 5 | **Repository Pattern** | Writes through repos | ✅ All write operations through repository layer (T022) | 10/10 |
| 6 | **Direct Eloquent Writes** | None in controllers | ✅ No `Model::create()`, `::update()`, `::delete()` in controllers | 10/10 |
| 7 | **Sync Determinism** | Same input → same output | ✅ Lock, dependency ordering, conflict resolution | 9/10 |
| 8 | **Read/Write Separation** | Reads = Eloquent, Writes = Hybrid | ✅ WorkspaceController: reads via Eloquent, writes via interface (Hybrid) | 10/10 |
| 9 | **Observer Integrity** | Single enqueue per event | ✅ HybridRepos no longer enqueue; observers handle all (T002) | 10/10 |
| 10 | **Incremental Sync** | Delta-only fetching | ✅ `IncrementalSyncService` with `updated_since` param (T020) | 9/10 |

---

## Detailed Audit

### 1. Single Source of Truth

**Before**: Two sources of truth — API (MySQL) when online, SQLite when offline.
**After**: Single source — local database (SQLite on NativePHP, MySQL on web). API only for background sync.

**Files verified**:
- ✅ `app/Http/Controllers/WorkspaceController.php` — `index()` reads from Eloquent
- ✅ `app/Http/Controllers/WorkspaceController.php` — `patientList()` reads from Eloquent
- ✅ `app/Http/Controllers/WorkspaceController.php` — `patientData()` reads from Eloquent
- ✅ `resources/js/Composables/useWorkspace.js` — `syncAndRefresh()` uses sync, not direct API reads

### 2. No Duplicate Sync Engines

| Engine | Status |
|--------|--------|
| `FullSyncService` | ✅ Primary engine — syncPendingOperations, syncMetadataOnly, syncAll |
| `SyncManager` | ✅ REMOVED (T001) |
| `BackgroundSyncService` | ✅ Adapter only — calls FullSyncService |
| `SyncQueueService` | ✅ Queue management — not a sync engine |
| `IncrementalSyncService` | ✅ Extension — called by FullSyncService |

### 3. Offline-First Reads

**Data flow**: `Vue → Axios → WorkspaceController → EloquentRepo → SQLite`
**Exception**: Export/Print use `$this->patientRepo` (interface = Hybrid in Native mode) — acceptable as these are special operations.

### 4. Controller Thinness

| Controller | Lines | Logic Rating | Notes |
|-----------|-------|-------------|-------|
| `WorkspaceController` | ~500 | ⚠️ Decent | Some data processing (stats, visits calculation) — acceptable for a controller |
| `PatientController` (Mobile) | ~300 | ⚠️ Improved | Still has file type detection, code generation |
| `NoteController` (Mobile) | ~200 | ✅ Good | Thin — delegates to repos |
| `VisitController` (Mobile) | ~200 | ✅ Good | Thin — delegates to repos |
| `FileController` (Mobile) | ~300 | ⚠️ Improved | Still has file MIME type detection (acceptable infrastructure code) |

### 5. Repository Pattern Consistency

| Entity | Reads | Writes | Status |
|--------|-------|--------|--------|
| Patient | EloquentRepo | HybridRepo | ✅ |
| Files | EloquentFileRepo | HybridFileRepo | ✅ |
| Notes | EloquentNoteRepo | HybridNoteRepo | ✅ |
| Visits | EloquentVisitRepo | HybridVisitRepo | ✅ |
| Users | — | HybridUserRepo | ✅ |

### 6. No Direct Eloquent Writes

Verified across all controllers and services:
- ✅ `app/Http/Controllers/` — No `Model::create()`, `::update()`, `::delete()`, `save()`, `forceDelete()`
- ✅ `app/Http/Controllers/Api/Mobile/` — All operations through repos (T022)
- ✅ `app/Services/` — Services use repos or direct Eloquent (acceptable for sync services)
- ✅ `app/Observers/` — Use Eloquent directly (expected — observers are tied to model lifecycle)

### 7. Sync Determinism

| Operation | Order | Guarantee |
|-----------|-------|-----------|
| Push pending queue | Dependency-ordered | Patient → Files/Notes/Visits |
| Pull remote data | Users → Patients → Child records | ✅ |
| Lock acquisition | Mutual exclusion | 30s TTL + heartbeat |
| Conflict resolution | Timestamp-based | Deterministic: remote wins on tie |
| Queue cleanup | Scheduled | Daily (7d) / Weekly (30d) |

### 8. Read/Write Separation

**Read flow** (WorkspaceController):
```
Vue → GET /api/v1/workspace/{uuid} → EloquentFileRepo → SQLite
```

**Write flow** (WorkspaceController):
```
Vue → POST /api/v1/workspace/patients → HybridRepo → SQLite + API
```

**Sync flow** (FullSyncService):
```
Background → Push queue items to API → Pull remote metadata → SQLite
```

### 9. Observer Integrity

| Observer | Enqueues On | Dedup | Status |
|----------|------------|-------|--------|
| `PatientObserver` | create, update, delete, restore | `hasExistingSyncQueueItem()` | ✅ |
| `PatientFileObserver` | create, delete, restore | `hasExistingSyncQueueItem()` | ✅ |
| `PatientNoteObserver` | create, update, delete | `hasExistingSyncQueueItem()` | ✅ |

### 10. Incremental Sync

- ✅ `IncrementalSyncService` with timestamp tracking
- ✅ `updated_since` parameter in all 4 API repos
- ✅ 24-hour threshold for incremental vs full sync
- ✅ `seedTimestamp()` avoids duplicate sync after full sync
- ⚠️ No frontend indicator showing last incremental sync time

---

## Audit Score

| Category | Score | Notes |
|----------|-------|-------|
| Single Source of Truth | 10/10 | All reads from local DB |
| Sync Engines | 10/10 | Single engine |
| Offline-First Consistency | 10/10 | All read paths verified |
| Controller Thinness | 7/10 | Some logic remains in Mobile controllers |
| Repository Pattern | 10/10 | All writes through repos |
| Direct Writes | 10/10 | No bypasses |
| Sync Determinism | 9/10 | Deterministic with timestamp tiebreaker |
| Read/Write Separation | 10/10 | Clean separation |
| Observer Integrity | 10/10 | Single enqueue path |
| Incremental Sync | 9/10 | Missing UI indicator |

**Overall Architecture Score**: **94/100**
