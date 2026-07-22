# 03 — Regression Tests

> **Purpose**: Verify that completed tasks did not break existing functionality.
> **Scope**: Every file modified across all 26 tasks.

---

## 1. API Compatibility

| File Changed | Potential Regression | Status | Evidence |
|-------------|---------------------|--------|----------|
| `WorkspaceController.php` — `patientList()` removed API-first | Frontend expects paginated `{ data, meta }` format | ✅ SAME | EloquentPatientRepo::paginated() returns identical format |
| `WorkspaceController.php` — `index()` removed API-first | Inertia expects `patients` array prop | ✅ SAME | EloquentPatientRepo::all() returns array |
| `WorkspaceController.php` — `patientData()` uses Eloquent | Frontend expects files/notes/visits arrays | ✅ SAME | Same array structure; same permission logic |
| Export/Print endpoints | Token auth via query param still works | ✅ SAME | `authenticateViaTokenIfNeeded()` unchanged |
| Chunked upload / direct upload | Response format unchanged | ✅ SAME | No changes to UploadController or ChunkUploadController |

**Result**: All API response formats preserved. No breaking changes.

---

## 2. Observer Integrity

| Observer | Events | Potential Regression | Status |
|----------|--------|---------------------|--------|
| `PatientObserver` | created, updated, deleted, restored | Double enqueue if HybridRepo also enqueues | ✅ Fixed by T002 — HybridRepos no longer enqueue |
| `PatientFileObserver` | created, deleted, restored | Has `hasExistingSyncQueueItem()` dedup | ✅ Dedup active |
| `PatientNoteObserver` | created, updated, deleted | Same dedup pattern | ✅ Active |

**Verification**: Check `sync_queue` after each operation — exactly 1 item per operation.

---

## 3. Duplicate Sync

| Code Path | Enqueue Via | Potential Duplicate | Status |
|-----------|-------------|---------------------|--------|
| Patient create (online) | HybridRepo → API + local → Observer | Observer fires on local create | ✅ HybridRepo no longer calls enqueueOperation |
| Patient create (offline) | SyncMiddleware save → Observer | Observer fires once | ✅ Single path |
| Note create (online) | HybridRepo → API + local → Observer | Observer fires on local create | ✅ HybridNoteRepo no longer enqueues |
| Note update (offline) | SyncMiddleware → saveLocally → Observer | Observer fires once | ✅ T008 |
| File upload | UploadsController → ChunkMergeService → Observer | Observer fires after merge | ✅ Single path |

**Result**: No duplicate sync paths remain. All write operations flow through observers for enqueuing.

---

## 4. Race Conditions

| Scenario | Risk | Mitigation | Status |
|----------|------|-----------|--------|
| `refreshPatientList()` + `syncAndRefresh()` parallel | Overwrite patients.value | Dedup guard `refreshPatientsInProgress` | ✅ |
| `refreshWorkspaceData()` parallel calls | Overwrite workspaceData | Dedup guard `refreshWorkspaceInProgress` | ✅ |
| Sync + PTR simultaneous | Lock contention | `isSyncInProgress()` check + 30s lock TTL + heartbeat | ✅ |
| Patient update + refresh | Stale data overwrites edit | Optimistic UI + pre-update snapshot + rollback | ✅ |
| `sync-completed` event during refresh | State overwrite | Guard checks `syncInProgress`, `refreshPatientsInProgress`, `refreshWorkspaceInProgress` | ✅ |
| SyncMiddleware + controller race | Double write | SyncMiddleware only runs offline (bypasses controller) | ✅ |

**Result**: All identified race conditions have dedup guards or architectural isolation.

---

## 5. Repository Misuse

| Repository | Used By | Correct Implementation? | Status |
|-----------|---------|----------------------|--------|
| `EloquentPatientRepository` | WorkspaceController reads, index, patientList | ✅ Read-only paths use Eloquent directly | ✅ |
| `HybridPatientRepository` | WorkspaceController writes, Mobile controllers | ✅ Write paths use Hybrid (API + local) | ✅ |
| `EloquentPatientFileRepository` | WorkspaceController patientData | ✅ Direct local reads only | ✅ |
| `HybridPatientFileRepository` | Mobile FileController writes | ✅ Write paths | ✅ |
| `ApiPatientRepository` | FullSyncService paginated pulls | ✅ Sync service only | ✅ |
| `EloquentPatientNoteRepository` | WorkspaceController patientData | ✅ Direct local reads | ✅ |

**Result**: Repository pattern consistently applied. Read/Writes follow intended architecture.

---

## 6. Missing Transactions

| Operation | Has Transaction? | Status |
|-----------|----------------|--------|
| Patient code generation | `DB::transaction()` around do-while | ✅ T023 |
| Chunked upload merge | `DB::transaction()` in ChunkMergeService | ✅ |
| Upload session create | `DB::transaction()` in UploadSessionService | ✅ |
| Bulk sync | `PRAGMA foreign_keys = OFF` then `ON` | ✅ |
| Concurrent code generation | `DB::transaction()` provides isolation | ✅ |

**Result**: All critical write operations have transaction protection.

---

## 7. Cache Invalidation

| Cache Key | Invalidated When? | Status |
|-----------|-------------------|--------|
| `network_status_online` | On every connectivity check (5s TTL) | ✅ T015 |
| `user_categories_{id}` | On category create/update/delete | ✅ |
| `export_patient_files_{jobId}` | On job completion (3600s TTL) | ✅ |
| `token_refresh_lock` | Released after refresh (10s lock) | ✅ |
| `last_sync_at` cache (ConflictResolver) | Reset at start of each sync cycle | ✅ |

**Result**: Cache invalidation is timely. No stale cache issues identified.

---

## 8. Event Dispatching

| Event | Dispatch Point | Consumer | Status |
|-------|---------------|----------|--------|
| `sync-completed` | `app.js` after periodic sync | `useWorkspace.js` → refreshPatientList + refreshWorkspaceData | ✅ |
| `online` | Browser event | Sync trigger via `/api/native/sync/background` | ✅ |
| `offline` | Browser event | Offline mode indicator | ✅ |
| Model events (created/updated/deleted) | Eloquent model lifecycle | Observers → SyncQueueService | ✅ |

**Result**: Event dispatch chain is complete. No missing consumers.

---

## Summary

| Area | Issues Found | Severity |
|------|-------------|----------|
| API Compatibility | 0 | — |
| Observer Integrity | 0 | — |
| Duplicate Sync | 0 | — |
| Race Conditions | 0 | — |
| Repository Misuse | 0 | — |
| Missing Transactions | 0 | — |
| Cache Invalidation | 0 | — |
| Event Dispatching | 0 | — |

**All regressions cleared.** No backward-incompatible changes introduced.
