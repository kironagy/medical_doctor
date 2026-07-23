# Development Roadmap
## Medical Plus Mobile Application

Version: 1.0

Status: Active

---

# Purpose

This roadmap defines the order in which features are implemented.

The roadmap is mandatory.

Features must only be implemented when their phase becomes active.

Future phases must never be implemented early.

---

# Development Strategy

The project follows an incremental development model.

Only one phase is active at any given time.

Each phase must become fully stable before the next phase begins.

The objective is to minimize complexity while maintaining production stability.

---

# Current Phase

## ACTIVE PHASE

Phase 8

Only Phase 8 may be implemented.

If a requested task belongs to a future phase,

the conflict must be explained before any implementation is suggested.

---

# Phase 1

## API Only

Status:

Completed

Objective:

Remove the previous offline architecture.

Restore a stable production environment.

Completed Work:

- API-only communication
- Direct Laravel integration
- MySQL as single source of truth
- Removal of SQLite
- Removal of synchronization engine
- Removal of hybrid repositories
- Removal of pending queue
- Removal of conflict resolution

Result:

Stable online application.

---

# Phase 2

## Preserve WebView State

Status:

Completed

Objective:

Improve user experience when reopening the application without internet access.

Requirements:

- Preserve WebView state.
- Preserve rendered page.
- Restore navigation state if supported.
- Avoid displaying browser error pages after restart.

Allowed:

- WebView lifecycle improvements.
- NativePHP WebView state restoration.
- Navigation restoration.

Forbidden:

- SQLite
- Local database
- Offline business data
- Offline CRUD
- Background synchronization
- Cache layer
- Synchronization queue

Success Criteria:

The application restores the previous rendered page without requiring internet immediately after restart.

---

# Phase 3

## Authentication Persistence

Status:

Future

Objective:

Persist user session between application launches.

Expected Features:

- Authentication persistence
- Session persistence
- Cookies
- Last visited URL
- Navigation history

Not Included:

Offline data.

SQLite.

Synchronization.

---

# Phase 4

## Read-Only Patients Cache

Status:

Completed

Objective:

Allow previously viewed patients to be read while offline.

Scope:

Read-only.

No editing.

No synchronization.

No pending operations.

Completed Work:

- SQLite patient cache with schema matching patients table.
- Read-only cache via read-through cache pattern.
- Migration-based setup with `sync_meta` table for cache tracking.
- Offline fallback: patients page renders from SQLite when API unavailable.
- Cache invalidation by clearing `sync_meta` on settings page.

---

# Phase 5

## Offline Patients CRUD

Status:

Completed

Objective:

Allow patient creation and editing while offline.

Expected Features:

- Local persistence
- Offline create
- Offline update
- Offline delete

Synchronization is still limited.

Completed Work:

- Architecture simplified: removed 12 legacy files (Hybrid repos, PendingOperation, FullSyncService, SyncStatusService, NetworkStatusService, ApiProxy, SyncPendingOperationsJob).
- New `PatientRepository` at `app/Repositories/PatientRepository.php` replaces `EloquentPatientRepository` and `HybridPatientRepository`.
- `sync_status` column (`synced`, `pending_sync`, `conflict`) added to `patients` table — replaces `pending_operations` table.
- Offline create: patients created while offline receive `sync_status = 'pending_sync'` and a temporary UUID.
- Offline edit: edits while offline update patient with `sync_status = 'pending_sync'`.
- Offline delete: soft-deletes while offline set `sync_status = 'pending_sync'` and mark `deleted_at`.
- Sync flow: `PendingSyncController@sync` pushes pending patients to API and marks them `synced`.
- Legacy sync tables (`sync_queue`, `sync_states`, `sync_jobs`, `pending_operations`, `sync_meta`) remain in the database as deprecated — not dropped.
- Migration: `2026_07_22_000001_add_sync_status_to_patients_table.php`.

---

# Phase 6

## Files Cache

Status:

Completed

Objective:

Cache downloaded files for offline viewing.

Scope:

Read-only.

No upload synchronization.

Completed Work:

- SQLite `file_cache` table for metadata (`file_uuid`, `patient_uuid`, `file_name`, `mime_type`, `size`, `local_path`, `cached_at`, `last_accessed_at`).
- `FileCacheService`: 1MB chunked buffer streaming with HTTP Range (206 Partial Content) video seeking support and safe path resolution.
- `FileCacheRepository`: SQLite metadata CRUD, ApiService streaming download (`Http::sink()`), LRU eviction (500MB quota).
- `FileAccessController` & `web.php`: 5 `_native/cache` local routes guarded with `auth` middleware and `Gate::authorize()` checks.
- `useWorkspace.js`: reactive `cachedFiles` state, `checkCacheStatus`, `cacheForOffline`, `removeFromCache`, `clearPatientCache`.
- `InlineFilePreview.vue` & `FileActions.vue`: offline preview fallback chain, toolbar & sheet cache/remove controls with loading states.

---

# Phase 7

## Offline File Upload

Status:

Completed

Objective:

Allow uploading files created while offline.

Completed Work:

- `offline_files` SQLite table for pending upload metadata (uuid, patient_uuid, local_path, original_name, mime_type, extension, size, hash, sync_status, remote_uuid, error_message, retry_count, timestamps).
- `OfflineFileRepository` with full CRUD and state machine (pending_upload → uploading → synced/failed) matching Phase 5 repository architecture.
- `OfflineUploadService` for streaming local file persistence to `storage/app/uploads/pending/` with SHA-256 hash via 1MB chunked streaming.
- `OfflineUploadController` with 5 endpoints (store, status, retry, destroy, index), each with Gate authorization.
- `SyncPendingUploadsCommand` (every 5 minutes): batch of 5, max 5 retries, automatic recovery of stuck uploading state (>10 min timeout), streaming upload via fopen().
- `FileAccessController.streamCached()` extended to serve offline pending files through the Phase 6 cache system.
- `useOfflineUploads.js` composable: Android permission handling (Camera/Storage/Audio), file picker, offline upload, retry, delete, status badges.
- `CategoryBlock.vue`: sync status badges (⏳/↑/⚠/✓) and retry/delete buttons on offline file cards.
- `useWorkspace.js selectPatient()`: automatic rehydration of offline pending uploads on workspace load — files survive app restart, process death, WebView recreation.
- ApiService::upload() converted to streaming via fopen() — no file_get_contents(), no OOM risk for 500MB uploads.
- Phase 5 and Phase 6 architecture preserved — no regressions.

---

# Phase 8

## Offline Notes

Status:

Future

Objective:

Support offline notes.

Requires:

Stable local persistence.

Reliable synchronization.

---

# Phase 9

## Pending Queue

Status:

Future

Objective:

Queue operations created while offline.

Expected Features:

- Pending operations
- Retry mechanism
- Failure recovery

---

# Phase 10

## Background Synchronization

Status:

Future

Objective:

Synchronize local changes automatically.

Expected Features:

- Background sync
- Conflict detection
- Conflict resolution
- Incremental synchronization

This is the final phase because it is the most complex.

---

# Rules

Never implement work from a future phase.

Never prepare infrastructure for future phases.

Never add placeholder code for future functionality.

Each phase must remain independent.

---

# Phase Completion

A phase is considered complete only if:

All planned functionality works.

No known blocking bugs remain.

The implementation is production-ready.

Regression testing passes.

Documentation is updated.

Only then may development proceed to the next phase.

---

# If a Request Conflicts

If a requested feature belongs to another phase:

Stop.

Explain the conflict.

Recommend waiting until the appropriate phase.

Do not silently implement future functionality.

---

# Final Principle

The roadmap exists to protect the project from unnecessary complexity.

Stable progress is more valuable than rapid progress.

The project grows one verified phase at a time.
