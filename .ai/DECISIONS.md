# Architecture Decisions
## Medical Plus Mobile Application

Version: 1.0

Status: Active

---

# AD-001: Phase 5 Architecture Simplification

Date: 2026-07-23

Status: Implemented

## Context

The previous offline architecture (Phase 4 and earlier) used a complex multi-layered approach:

- `HybridPatientRepository` delegating between Eloquent and SQLite
- `PendingOperation` for queuing offline mutations
- `FullSyncService` / `SyncStatusService` / `NetworkStatusService` for orchestration
- `ApiProxy` for intercepting and routing requests
- `SyncPendingOperationsJob` for background sync

Although technically functional, this architecture produced persistent production issues:

- Data appearing only after application restart
- UI state falling out of sync with backend
- Debugging complexity
- High maintenance burden

## Decision

Simplify the offline architecture for Phase 5 by:

1. **Removing 12 legacy files** — all hybrid repositories, services, and sync infrastructure that were no longer needed.
2. **Introducing `sync_status` column** on the `patients` table — replaces the entire `pending_operations` table and associated queue logic.
3. **New `PatientRepository`** at `app/Repositories/PatientRepository.php` — single repository handling both online (API) and offline (SQLite) operations, with a clear read-through cache pattern.
4. **Direct sync flow** — `PendingSyncController@sync` pushes pending patients directly to the API instead of through a multi-stage queue.
5. **Legacy tables preserved as deprecated** — `sync_queue`, `sync_states`, `sync_jobs`, `pending_operations`, `sync_meta` remain in the database but are not used by any production code.

## Rationale

- The `sync_status` approach is simpler: one column replaces an entire table and its associated services.
- Removing 12 files eliminates the primary source of synchronization bugs.
- Single repository with clear read-through cache is easier to debug and maintain.
- Direct sync (controller → API) is predictable and testable.

## Consequences

- Offline CRUD works but sync requires explicit user action (via Settings or auto-trigger).
- No background sync or conflict resolution — deferred to Phase 10.
- Legacy tables remain as dead schema; they can be dropped in a future cleanup migration.
- The repository layer must be kept clean — avoid re-introducing hybrid patterns.

## Files Affected

- Removed: 12 files (HybridPatientRepository, PendingOperation, FullSyncService, SyncStatusService, NetworkStatusService, ApiProxy, SyncPendingOperationsJob, etc.)
- Created: `app/Repositories/PatientRepository.php`
- Modified: `WorkspaceController`, related Vue components
- Added: `2026_07_22_000001_add_sync_status_to_patients_table.php`

---

# AD-002: Phase 6 Read-Only Files Cache Architecture

Date: 2026-07-23

Status: Implemented

## Context

Phase 6 requires allowing doctors to view previously downloaded patient files (images, PDFs, videos) while offline without internet connectivity. High memory usage and lack of Range/seeking support in previous file streaming implementations caused memory spikes and slow seeking on mobile devices.

## Decision

Implement read-only file caching using a 3-tier architecture:

1. **Database Layer (`file_cache` SQLite table)** — Tracks metadata (`file_uuid`, `patient_uuid`, `file_name`, `mime_type`, `size`, `local_path`, `cached_at`, `last_accessed_at`).
2. **Repository & Service Layer (`FileCacheRepository` + `FileCacheService`)**:
   - `FileCacheService` streams files using a 1MB chunked `fread()` buffer to prevent memory spikes, and parses HTTP `Range` headers to support 206 Partial Content video seeking.
   - `FileCacheRepository` manages disk persistence, uses `ApiService::download()` (`Http::sink()`) for zero-memory streaming downloads, and enforces a 500MB LRU cache quota based on `last_accessed_at`.
3. **Local Route Layer (`_native/cache`)**:
   - 5 dedicated endpoints on the local web server (`streamCached`, `cacheFile`, `cacheStatus`, `removeCached`, `removePatientCached`).
   - Every endpoint is protected with `auth` middleware and `Gate::authorize('view', $file->patient)` checks.
4. **Frontend Reactive Fallback (`useWorkspace.js` + `InlineFilePreview.vue` + `FileActions.vue`)**:
   - Reactive `cachedFiles` store tracks cache state.
   - `InlineFilePreview` automatically falls back to `/_native/cache/files/{uuid}` when signed URLs or API requests fail offline.

## Rationale

- Streaming via 1MB buffer ensures large video files stream without memory spikes.
- HTTP Range header support allows video scrubbers and HTML5 video players to seek smoothly without reading entire files into memory.
- `Http::sink()` writes incoming stream bytes directly to disk during caching.
- LRU eviction maintains disk usage strictly under 500MB without manual user maintenance.
- All endpoints enforce Gate authorization to prevent unauthorized cross-doctor file access.

## Consequences

- Read-only file caching is fully functional offline.
- File uploads created while offline are not yet supported — deferred to Phase 7.

## Files Affected

- Created: `database/migrations/2026_07_23_000003_create_file_cache_table.php`, `app/Contracts/Repositories/FileCacheRepositoryInterface.php`, `app/Repositories/FileCacheRepository.php`, `app/Services/Mobile/FileCacheService.php`
- Modified: `app/Http/Controllers/Api/FileAccessController.php`, `routes/web.php`, `app/Providers/RepositoryServiceProvider.php`, `resources/js/Composables/useWorkspace.js`, `resources/js/Components/workspace/InlineFilePreview.vue`, `resources/js/Components/workspace/FileActions.vue`
- Deleted: `app/Services/Mobile/FileRepository.php` (dead code)

---

# AD-003: Phase 7 Offline File Upload Architecture

Date: 2026-07-23

Status: Implemented

## Context

Phase 7 requires allowing doctors to capture and upload medical files (photos, PDFs, videos, audio, documents) while offline. The existing architecture from Phase 5 (dual-write PatientRepository with sync_status) and Phase 6 (streaming file cache) provides a proven template.

## Decision

1. **Repository Pattern** — `OfflineFileRepository` mirrors Phase 5's `PatientRepository` pattern: local SQLite writes with `sync_status` state machine (`pending_upload → uploading → synced/failed`).

2. **Streaming Only** — All file operations use streaming: `storeAs()` for persistence, `fread()` 1MB buffer for SHA-256 hashing, `fopen()` resource streams for HTTP upload via `ApiService::upload()`. No `file_get_contents()`, no memory spikes.

3. **Android Permissions via useNativeBridge** — Camera (`CAMERA`), Storage (`READ_MEDIA_IMAGES`), and Audio (`RECORD_AUDIO`) permissions are requested BEFORE accessing native APIs, with proper denied/permanently-denied handling.

4. **Rehydration on Workspace Load** — After app restart/process death/WebView recreation, `useWorkspace.js:selectPatient()` fetches pending offline files from `/_native/api/offline/uploads?patient_uuid={uuid}` and merges them into the workspace file list via `addFileLocally()`. This ensures files survive all restart scenarios.

5. **Sync Command with Stuck State Recovery** — `SyncPendingUploadsCommand` runs every 5 minutes via Laravel scheduler. At startup, it automatically resets records stuck in `uploading` for >10 minutes back to `pending_upload`. Batching (5 files), retry limits (max 5), connection error handling, and individual file error isolation prevent cascading failures.

6. **Phase 6 Cache Reuse** — Offline file preview uses the existing `FileAccessController::streamCached()` endpoint with an `offline_files` table fallback. No new preview system is created.

## Rationale

- The Phase 5 repository pattern is battle-tested in production. Reusing it for file uploads ensures consistency.
- Streaming is mandatory for 500MB files on memory-constrained mobile devices.
- Rehydration is the only reliable way to handle WebView state loss — the SQLite database is the persistent source of truth.
- Stuck state recovery is essential: the sync command can crash at any point during the `markUploading → HTTP request → response` window.
- Reusing Phase 6 cache for preview avoids duplicating the file serving infrastructure.

## Consequences

- Offline file upload is fully functional and survives all restart scenarios.
- Pending uploads are synced to the remote API automatically when connectivity returns (max 5-minute delay).
- Synced records remain in `offline_files` with `sync_status='synced'` and `remote_uuid` for audit trail.
- Local files deleted after successful sync to free disk space.
- Phase 5 and Phase 6 architectures are completely preserved — no regressions.

## Files Affected

- Created: `database/migrations/2026_07_23_000004_create_offline_files_table.php`, `app/Contracts/Repositories/OfflineFileRepositoryInterface.php`, `app/Repositories/OfflineFileRepository.php`, `app/Services/OfflineUploadService.php`, `app/Console/Commands/SyncPendingUploads.php`, `app/Http/Controllers/Api/OfflineUploadController.php`, `resources/js/Composables/useOfflineUploads.js`
- Modified: `app/Http/Controllers/Api/FileAccessController.php`, `app/Http/Controllers/WorkspaceController.php`, `app/Providers/RepositoryServiceProvider.php`, `app/Services/Mobile/ApiService.php`, `routes/web.php`, `bootstrap/app.php`, `resources/js/Composables/useWorkspace.js`, `resources/js/Components/workspace/CategoryBlock.vue`, `.ai/ROADMAP.md`, `.ai/ARCHITECTURE.md`, `.ai/PROJECT_CONTEXT.md`, `.ai/DECISIONS.md`, `.ai/WORKLOG.md`
