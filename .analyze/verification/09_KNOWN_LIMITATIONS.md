# 09 — Known Limitations

> **Purpose**: Honest documentation of remaining issues and trade-offs.
> **Severity**: Critical / High / Medium / Low / Cosmetic

---

## Functional Limitations

### LIM-001: Offline Search Unavailable
**Severity**: Medium
**Description**: Search requires API connectivity. When offline, `GlobalSearch.vue` calls `GET /api/v1/search` which fails silently.
**Impact**: Users cannot search patients while offline.
**Workaround**: Scroll through patient list manually.
**Fix**: Add local SQLite search endpoint as fallback.

### LIM-002: File Upload Offline (Large Files)
**Severity**: Medium
**Description**: `SyncMiddleware::saveLocally()` skips file uploads with operation 'upload' (comment: "File upload middleware is handled via multipart — skip for offline"). Large files bypassed entirely.
**Impact**: Large file uploads queued but not saved locally; lost on app restart if not synced.
**Workaround**: Only upload files while online.
**Fix**: Implement offline file caching + deferred upload for large files.

### LIM-003: Multi-Device Offline Edits
**Severity**: High
**Description**: Two devices editing the same patient offline produce conflicts resolved by last-writer-wins (timestamp comparison). There is no CRDT or merge algorithm.
**Impact**: One doctor's offline edits silently overwritten if the other device syncs later.
**Workaround**: Coordinate offline edits with the team.
**Fix**: Implement operational transform or CRDT for patient records.

### LIM-004: No Push Notifications
**Severity**: Low
**Description**: No real-time notification when sync completes or when another doctor shares a patient with you.
**Impact**: User must manually refresh or wait for periodic sync (5 min).
**Workaround**: Pull-to-refresh gets latest data immediately.
**Fix**: Integrate Firebase Cloud Messaging (FCM) for push notifications.

---

## Technical Limitations

### LIM-005: SQLite in Plaintext
**Severity**: Medium
**Description**: By default, SQLite database is stored in plaintext on the device. `APP_KEY` encryption option exists but is disabled by default.
**Impact**: If device is lost, patient data is accessible by anyone with root access to the device filesystem.
**Workaround**: Enable `ENCRYPT_TOKEN=true` in `.env`. Note: this only encrypts the API token, not the database itself.
**Fix**: Implement full SQLite encryption (SQLCipher or similar).

### LIM-006: No Database Backup
**Severity**: Critical
**Description**: SQLite database has no automated backup mechanism. If the device is lost, factory reset, or app data is cleared, all offline data is lost.
**Impact**: Patients created offline, notes, and files not yet synced are permanently lost if device data is cleared.
**Workaround**: None — data exists only on the device until synced.
**Fix**: Implement periodic SQLite backup to remote storage (API endpoint, S3, or cloud drive).

### LIM-007: No Migration Rollback on Failure
**Severity**: Medium
**Description**: `AppServiceProvider::boot()` runs `php artisan migrate` on startup. If migration fails, app may be in inconsistent state.
**Impact**: Failed migration could leave app non-functional on next restart.
**Workaround**: Manual `php artisan migrate:rollback` via ADB/SSH.
**Fix**: Wrap migration in try-catch with rollback logic and user-facing error message.

### LIM-008: Sync Lock Orphan on Fatal Error
**Severity**: Low
**Description**: If PHP process crashes with a fatal error (OOM, segfault), the `finally` block releasing the sync lock never executes. Lock auto-expires after LOCK_TTL (30s).
**Impact**: Sync blocked for up to 30 seconds after a crash.
**Workaround**: None — auto-recovers after 30s.
**Fix**: Already mitigated — LOCK_TTL reduced from 300s to 30s (T011). Stale lock detection in place.

### LIM-009: Observer Dedup Race
**Severity**: Low
**Description**: `hasExistingSyncQueueItem()` queries `sync_queue` with a `whereIn('status', ['pending', 'failed'])` check. If an observer is called before a previous observer's enqueue is committed (non-atomic), both observers could pass the check.
**Impact**: Rare duplicate queue entries (theoretical — not observed in practice).
**Workaround**: None — dedup is best-effort.
**Fix**: Wrap observer + enqueue in DB transaction with exclusive lock.

---

## Performance Limitations

### LIM-010: File Preview Download Costs
**Severity**: Low
**Description**: Each file preview requires at least one API call per unique file (signed URL fetch). For patients with hundreds of files, scrolling through categories triggers many API calls.
**Impact**: Higher data usage on mobile.
**Workaround**: Files are cached locally after first download (`FileCacheService`).
**Fix**: Implement batch signed URL endpoint or full local file caching.

### LIM-011: Full Sync Rate Limit Risk
**Severity**: Medium
**Description**: Full sync (first run or after 24h offline) makes 100+ API calls. If server throttle is 120 requests/minute, this could trigger 429.
**Impact**: Sync fails midway; remaining items stay queued.
**Workaround**: Incremental sync (default) uses 5-10 calls.
**Fix**: Add rate-limit awareness to FullSyncService (backoff + retry).

---

## UX Limitations

### LIM-012: No Sync Progress Indicator
**Severity**: Low
**Description**: User sees "Offline Mode" banner when offline, but no "Syncing X items" progress indicator.
**Impact**: User doesn't know when pending changes are being processed.
**Workaround**: Pull-to-refresh triggers sync with spinner.
**Fix**: Add sync progress bar with pending item count and item type.

### LIM-013: No Connectivity Quality Indicator
**Severity**: Low
**Description**: Only binary online/offline indicator. No signal strength or latency display.
**Impact**: Poor connectivity masquerades as "online" with slow API responses.
**Workaround**: None — user experiences slow loads.
**Fix**: Add latency measurement and connectivity quality indicator.

---

## Summary

| ID | Limitation | Severity | Status |
|----|-----------|----------|--------|
| LIM-001 | Offline search unavailable | Medium | ⏳ Backlog |
| LIM-002 | Large file upload offline bypassed | Medium | ⏳ Backlog |
| LIM-003 | Multi-device offline conflict | High | ⏳ Future |
| LIM-004 | No push notifications | Low | ⏳ Future |
| LIM-005 | SQLite in plaintext | Medium | ⏳ Backlog |
| LIM-006 | No database backup | **Critical** | ⏳ **Required** |
| LIM-007 | No migration rollback | Medium | ⏳ Backlog |
| LIM-008 | Sync lock orphan (30s) | Low | ✅ Mitigated |
| LIM-009 | Observer dedup race | Low | ⏳ Future |
| LIM-010 | File preview API calls | Low | ⏳ Backlog |
| LIM-011 | Full sync rate limit risk | Medium | ⏳ Backlog |
| LIM-012 | No sync progress indicator | Low | ⏳ Backlog |
| LIM-013 | No connectivity quality indicator | Low | ⏳ Backlog |
