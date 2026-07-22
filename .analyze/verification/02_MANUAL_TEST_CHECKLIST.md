# 02 — Manual Test Checklist

> **Purpose**: Step-by-step manual validation for QA testers.
> **Instructions**: Check each box after successful verification. Document failures with error messages and stack traces.

---

## 🔐 Authentication

- [ ] **Login with valid credentials** — token stored in `sync_states`, user redirected to workspace
- [ ] **Login with invalid credentials** — 422 returned, error message displayed
- [ ] **Logout** — token invalidated in `personal_access_tokens`, redirect to login
- [ ] **Auto-login from SQLite** — offline login works against locally cached user
- [ ] **Session expiry** — 401 from API shows "Session expired" message
- [ ] **Print/export with token** — new tab opens with `?token=` URL parameter

---

## 👥 Patient Management

- [ ] **Create patient** — appears in sidebar immediately (optimistic UI)
- [ ] **Create patient with duplicate code** — transaction retries with new random code
- [ ] **Create patient offline** — saved locally, queued for sync
- [ ] **Edit patient name** — optimistic update, rollback on failure
- [ ] **Edit patient offline** — saved to SQLite via SyncMiddleware, queued
- [ ] **Archive patient** — moves to archived list
- [ ] **Restore patient** — returns to active list
- [ ] **Force-delete patient** — permanently removed, cascade deletes files/notes/visits
- [ ] **Select patient** — workspace loads files/notes/visits from local SQLite
- [ ] **Patient with 100+ files** — all files visible (no truncation)
- [ ] **Patient list >10 patients** — all visible (100 per page)

---

## 📝 Notes

- [ ] **Add note** — appears in workspace immediately
- [ ] **Edit note** — content updates in workspace
- [ ] **Delete note** — removed from workspace, observer queues delete sync
- [ ] **Add note offline** — appears via `addNoteLocally()`, SyncMiddleware saves
- [ ] **Add note in category block** — note-scoped to the correct category
- [ ] **Note with HTML content** — rendered as safe HTML

---

## 📁 Files

- [ ] **Upload file (small)** — appears in correct category block
- [ ] **Upload file (large, >10MB)** — chunked upload succeeds
- [ ] **Upload image** — preview shows in media viewer
- [ ] **Upload video** — HLS streaming works
- [ ] **Upload PDF** — preview renders
- [ ] **Delete file** — removed locally, observer queues sync
- [ ] **Upload offline** — SyncMiddleware saves (if file < middleware bypass size)
- [ ] **Download all files as ZIP** — ExportPatientFilesJob processes, downloads link

---

## 🏥 Visits

- [ ] **Add visit** — appears in visits list
- [ ] **Edit visit** — all fields update correctly
- [ ] **Delete visit** — removed, observer queues sync
- [ ] **Add visit offline** — local state update + SyncMiddleware save
- [ ] **Visit with next_visit_date** — shows in upcoming appointments
- [ ] **Multiple visits** — correct latest/next calculation

---

## 🔄 Synchronization

- [ ] **Create while online** — data appears on server immediately (HybridRepo push)
- [ ] **Create while offline → go online** — SyncMiddleware saved locally; FullSyncService pushes on next cycle
- [ ] **Full sync** — `POST /api/native/sync` pushes pending + pulls metadata
- [ ] **Incremental sync** — only records updated since last sync fetched
- [ ] **Sync lock** — parallel sync calls blocked (dedup guard + 30s lock TTL)
- [ ] **Heartbeat** — long sync operations extend lock (touchLock)
- [ ] **Queue cleanup** — synced items older than 7 days removed daily
- [ ] **Permanently failed items** — cleared weekly (30 days retention)

---

## 🔀 Conflict Resolution

- [ ] **Local edit after last sync** — `hasPendingChanges()` detects `client_updated_at > last_sync_at`
- [ ] **Remote newer** — remote version wins, local changes preserved in queue
- [ ] **Local newer** — local version kept, remote overwrite prevented
- [ ] **Same age** — defaults to remote (safe default)
- [ ] **Cascade delete** — deleting patient cascades to files, notes, visits

---

## 🔍 Search

- [ ] **Search by name** — API searches entire dataset
- [ ] **Search by code** — finds by patient code
- [ ] **Search by phone** — matches partial phone numbers
- [ ] **Short query (<2 chars)** — no search triggered
- [ ] **Clear search** — resets to page 1

---

## 📱 Pull-to-Refresh

- [ ] **Sidebar PTR** — triggers `syncAndRefresh()` with current page
- [ ] **Workspace PTR** — refreshes patient list + workspace data
- [ ] **PTR while already syncing** — dedup guard returns in-progress promise
- [ ] **PTR indicator** — visual feedback (arrow rotation, spinner)

---

## ⚡ Performance

- [ ] **App startup** — loads and renders within 3 seconds
- [ ] **Patient list load** — 100 patients renders within 1 second
- [ ] **Patient workspace** — files/notes/visits render within 2 seconds
- [ ] **Search** — results return within 3 seconds
- [ ] **File upload** — 5MB upload completes within 10 seconds
- [ ] **Full sync** — 100 patients completes within 30 seconds

---

## 📱 Offline Mode

- [ ] **App loads offline** — data from local SQLite
- [ ] **Create patient offline** — appears in list immediately; synced when online
- [ ] **Edit patient offline** — changes saved locally; synced when online
- [ ] **Add note offline** — appears in workspace; synced when online
- [ ] **Add visit offline** — appears in workspace; synced when online
- [ ] **Upload file offline** — saved to SQLite; uploaded when online
- [ ] **Go online** — sync triggered automatically (connectivity listener)
- [ ] **Offline indicator** — red banner shows "Offline Mode"
- [ ] **Data consistency** — offline data matches online data after sync

---

## 🧪 Test Commands

```bash
# Run all feature tests
php artisan test --testsuite=Feature

# Run specific test suite
php artisan test --filter=PatientApiTest
php artisan test --filter=OfflineSyncTest
php artisan test --filter=AuthTest
php artisan test --filter=ChunkedUploadTest
php artisan test --filter=DoctorIsolationTest

# Run unit tests
php artisan test --testsuite=Unit

# Run all tests
php artisan test
```
