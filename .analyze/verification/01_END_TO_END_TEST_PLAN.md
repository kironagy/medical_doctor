# 01 — End-to-End Test Plan

> **Purpose**: Verify every complete user workflow from start to finish.
> **Scope**: All CRUD operations, sync, offline, and edge cases.
> **Environment**: NativePHP (mobile) + Web browser + API server.

---

## Workflow 1: Login → Patient List

| Step | Action | Expected Result | Failure Points | Recovery |
|------|--------|----------------|----------------|----------|
| 1.1 | Open app | Login page displayed | Blank screen → check build | Restart app |
| 1.2 | Enter valid credentials | 200 response, token stored in sync_states | 422 → invalid creds; 500 → server down | Retry; check .env API URL |
| 1.3 | After login | Dashboard/Workspace loads with patient list | Empty list → sync not started; 500 → server error | Wait 5s for auto-sync; Pull-to-refresh |
| 1.4 | Verify patient list | Patients from API appear in sidebar | Patients on page 2+ missing → pagination | Refresh pulls all 100 per page |

**Validation command**: `php artisan test --filter=AuthTest`

---

## Workflow 2: Patient CRUD

| Step | Action | Expected Result | Failure Points | Recovery |
|------|--------|----------------|----------------|----------|
| 2.1 | Create patient (form) | Patient appears immediately in sidebar | Code collision → transaction retry; API down → saves locally + queues | Refresh after sync |
| 2.2 | Click patient | Workspace loads with files/notes/visits tabs | 404 → race condition with sync; empty → no data yet | Pull-to-refresh |
| 2.3 | Edit patient name | Optimistic UI updates immediately; API call in background | API fails → rollback to snapshot; 422 → validation error | Fix form; retry |
| 2.4 | Archive patient | Patient moves to archived list | 404 → already archived; 500 → server error | Refresh; retry |
| 2.5 | Restore patient | Patient returns to active list | 404 → permanently deleted | Cannot restore |
| 2.6 | Force-delete patient | Patient removed permanently | 500 → server error; FK constraint | Resolve constraints first |

**Validation command**: `php artisan test --filter=PatientApiTest`

---

## Workflow 3: Notes CRUD

| Step | Action | Expected Result | Failure Points | Recovery |
|------|--------|----------------|----------------|----------|
| 3.1 | Add note to patient | Note appears in patient workspace | API error → fallback to addNoteLocally() | Sync when online |
| 3.2 | Edit note content | Note updated in workspaceData | 404 → note deleted elsewhere; 422 → validation | Refresh |
| 3.3 | Delete note | Note removed from workspaceData | 404 → already deleted; API down → observer queues | Sync |
| 3.4 | Add note offline | Note added to local reactive state + SyncMiddleware saves to SQLite | SyncMiddleware fails → data lost | Check sync_queue |

---

## Workflow 4: Files CRUD

| Step | Action | Expected Result | Failure Points | Recovery |
|------|--------|----------------|----------------|----------|
| 4.1 | Upload file | File appears in category block | Chunked upload merge fails → retry; OOM → chunk too large | Reduce chunk size |
| 4.2 | View file preview | Preview modal shows file content | No signed URL → token expired; unsupported type | Fallback to download |
| 4.3 | Delete file | File removed locally; observer queues delete sync | API down → queued; 404 → already deleted | Sync when online |
| 4.4 | Upload offline | SyncMiddleware saves to SQLite; file queued for upload | File too large → middleware skips; missing metadata | Check middleware logs |

---

## Workflow 5: Visits CRUD

| Step | Action | Expected Result | Failure Points | Recovery |
|------|--------|----------------|----------------|----------|
| 5.1 | Add visit | Visit appears in workspace visits list | API error → fallback to local state | Retry online |
| 5.2 | Edit visit date | Visit updated in workspaceData | Visit not found → 404; validation error | Fix data; retry |
| 5.3 | Delete visit | Visit removed; observer queues delete | API down → queued; FK constraint | Sync |
| 5.4 | Add visit offline | Visit added locally + SyncMiddleware saves | SyncMiddleware skips → check saveLocally() | Manual retry |

---

## Workflow 6: Synchronization

| Step | Action | Expected Result | Failure Points | Recovery |
|------|--------|----------------|----------------|----------|
| 6.1 | Create patient online | HybridRepo pushes to API + saves locally | API timeout → NetworkStatusService marks offline → local save + queue | Retry sync |
| 6.2 | Create patient offline | Patient saved locally; observer enqueues sync | Observer fails → no sync; DB full → write fails | Free disk space |
| 6.3 | Go online → sync | FullSyncService pushes queued items to API | Lock contention → 30s TTL + heartbeat prevents | Auto-retry next cycle |
| 6.4 | Conflict detection | ConflictResolver checks client_updated_at vs last_sync_at | Timestamp parse fails → defaults to remote | Check timezone config |
| 6.5 | Incremental sync | Only records updated since last sync are fetched | last_sync_at missing → full sync fallback | Seed timestamp |

**Validation command**: `php artisan test --filter=OfflineSyncTest`

---

## Workflow 7: Pull-to-Refresh

| Step | Action | Expected Result | Failure Points | Recovery |
|------|--------|----------------|----------------|----------|
| 7.1 | Pull down on patient list | syncAndRefresh() called | Dedup guard prevents parallel calls | Wait |
| 7.2 | Wait for refresh | Patient list updated from SQLite (after sync) | Sync fails → still refreshes from local data | Non-fatal |
| 7.3 | PTR with selected patient | WorkspaceData also refreshed | Dedup guard returns in-progress promise | Works correctly |

---

## Workflow 8: Search

| Step | Action | Expected Result | Failure Points | Recovery |
|------|--------|----------------|----------------|----------|
| 8.1 | Type search query (≥2 chars) | Debounced (400ms) API search triggered | <2 chars → no search sent | Type more |
| 8.2 | Verify results | Search queries entire dataset, not just loaded page | API doesn't support search → falls back to client filter | Check API compat |
| 8.3 | Clear search | Patient list resets to page 1 | Debounce fires extra call | Non-fatal |

---

## Workflow 9: Print / Export

| Step | Action | Expected Result | Failure Points | Recovery |
|------|--------|----------------|----------------|----------|
| 9.1 | Click Print | Opens new tab with printable patient record | New tab has no session → token in URL verifies | Token mismatch → 401 |
| 9.2 | Click Export | Downloads JSON file | New tab auth fails → token param fallback | Check meta tag |

---

## Workflow 10: Conflict Resolution

| Step | Action | Expected Result | Failure Points | Recovery |
|------|--------|----------------|----------------|----------|
| 10.1 | Edit patient offline → other doctor edits online | Local edit saved in SQLite with later client_updated_at | ConflictResolver: remote wins if newer | Local changes queued |
| 10.2 | Sync after conflict | hasPendingChanges() detects local edit > last_sync_at | Timestamp comparison overflow | Check Carbon versions |
| 10.3 | Verify result | Local edit preserved if newer; remote wins if same age | Default resolution → remote wins | Acceptable default |
