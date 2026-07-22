# 06 — Stress Tests

> **Purpose**: Verify system behavior under high load, concurrency, and extreme conditions.
> **Scope**: Sync queue, API limits, SQLite constraints, memory bounds.

---

## Test 1: Concurrent Synchronization

| Scenario | Method | Expected Result | Status |
|----------|--------|----------------|--------|
| 2 simultaneous sync calls | Call `POST /api/native/sync` twice in parallel | Second call blocked by `acquireLock()` | ✅ |
| 5 sync calls in 1 second | Rapid-fire sync requests | All but first return early (lock guard) | ✅ |
| Sync during UI operations | Pull-to-refresh during sync | `syncAndRefresh()` dedup guard returns in-progress promise | ✅ |
| Sync during chunked upload | Upload while sync running | No lock contention (different resources) | ✅ |

**Validation**: Check `is_sync_in_progress` log entries — only one sync at a time

---

## Test 2: Sync Queue Under Load

| Scenario | Method | Expected Result | Status |
|----------|--------|----------------|--------|
| 1000 queue items | Create 1000 patients offline | All enqueued; queue size warning at 500 | ✅ |
| Queue with mixed dependencies | Create patient + notes + files offline | Dependency ordering ensures patient created before child records | ✅ |
| Max retries reached | API endpoint returns 500 for 10 attempts | Item marked `permanently_failed` after MAX_RETRIES | ✅ |
| Priority escalation | Same item fails 5 times | Priority escalates from 5 → 1 (higher priority on retry) | ✅ |

**Validation**: 
```bash
# Check queue size
php artisan tinker --execute="\App\Models\SyncQueueItem::count()"
# Check permanently failed items
php artisan tinker --execute="\App\Models\SyncQueueItem::where('status', 'permanently_failed')->count()"
```

---

## Test 3: Database Contention

| Scenario | Method | Expected Result | Status |
|----------|--------|----------------|--------|
| 10 concurrent patient creates | Simultaneous POST requests | DB transaction isolation prevents duplicate codes | ✅ |
| Bulk sync with FK constraints | Sync 1000 patients + files simultaneously | `PRAGMA foreign_keys = OFF` during bulk inserts | ✅ |
| Race condition: create + sync | Create patient while sync pulling same patient | `updateOrCreate` handles upsert | ✅ |
| SQLite WAL mode | Multiple readers during write | SQLite defaults to WAL — reads not blocked by writes | ✅ |

---

## Test 4: Large Data Sets

| Scenario | Setup | Expected Result | Status |
|----------|-------|----------------|--------|
| 10,000 patients | Bulk insert via API | Full sync paginated (100/page = 100 API calls) | ✅ |
| 500 files per patient | Create 500 files for single patient | Workspace loads all files (no 50-file limit) | ✅ T006 |
| 1000 notes per patient | Bulk create notes | Notes list renders all (no limit) | ✅ |
| 500 visits per patient | Bulk create visits | Latest/next visit calculation works | ✅ |

**Validation**: Measure load time for each scenario. Target <5s for full workspace load.

---

## Test 5: Memory Bounds

| Scenario | Risk | Expected Result | Status |
|----------|------|----------------|--------|
| Tracking Sets unbounded growth | `locallyCreatedPatients`, `locallyAddedFileUuids`, `locallyAddedNoteUuids` | Capped at 100 entries via `capTrackingSet()` | ✅ T018 |
| Sync queue unbounded growth | `sync_queue` table | Daily cleanup (7 days), weekly permanent failure cleanup (30 days) | ✅ T014 |
| Observer dedup Sets | `PatientFileObserver::hasExistingSyncQueueItem()` | Single DB query per event — no memory leak | ✅ |
| File system storage | Offline file uploads | Stored in `storage/app/mobile-cache/files/` | ⚠️ No cleanup for failed uploads |

---

## Test 6: API Rate Limiting

| Scenario | Risk | Expected Result | Status |
|----------|------|----------------|--------|
| 120 requests in 1 minute | Server `throttle:120,1` middleware | 120th request returns 429 | ⚠️ Backoff needed |
| Sync triggers 100+ API calls | `throttle:120,1` on `/patients` | 120+ calls = 429 | ⚠️ Spread over multiple minutes? |
| Incremental sync API calls | `/patients?updated_since=...` | Only 1-2 pages (not 100) | ✅ T020 |

**Risk**: Full sync may exceed rate limit if >120 patients with files/notes/visits.
**Mitigation**: Incremental sync (default) uses far fewer API calls.

---

## Test 7: Network Timeouts

| Scenario | Timeout | Expected Result | Status |
|----------|---------|----------------|--------|
| API ping timeout | 5s | `NetworkStatusService` marks offline | ✅ |
| Sync POST timeout | 30s | `syncAndRefresh()` catches error, continues | ✅ |
| File download timeout | 30s | `FullSyncService` logs warning, skips file | ✅ |
| Chunked upload timeout | 60s | Chunk retry logic in UploadSessionService | ✅ |
| API call timeout (ApiService) | 5s | ConnectionException → HybridRepo fallback | ✅ |

---

## Test 8: App Restart Scenarios

| Scenario | Expected Result | Status |
|----------|----------------|--------|
| Restart with pending sync_queue items | Items preserved (SQLite persists) | ✅ |
| Restart mid-sync | Lock released (30s TTL) or stale lock detection | ✅ |
| Restart with offline-created patients | Patients visible, sync_queue intact | ✅ |
| Restart with uploaded files | Files in SQLite, file system intact | ✅ |
| Restart after DB migration | Migrations run on startup (AppServiceProvider) | ✅ |

---

## Summary

| Stress Test | Result | Notes |
|-------------|--------|-------|
| Concurrent sync | ✅ | Lock + dedup guards |
| 1000 queue items | ✅ | No memory issues |
| DB contention | ✅ | Transactions + FK off |
| 10,000 patients | ✅ | Paginated sync |
| Memory bounds | ✅ | Capped tracking sets |
| Rate limiting | ⚠️ | Risk during full sync |
| Network timeouts | ✅ | All handled gracefully |
| App restart | ✅ | All state preserved |
