# NativePHP Mobile & Laravel System Audit & Root Cause Analysis

**Version:** 1.0.0  
**Date:** August 1, 2026  
**Target Application:** Medical Plus v3 (NativePHP Mobile & Laravel Backend)  
**Document Purpose:** Comprehensive System Technical Audit, Data Flow Analysis, Architecture Blueprint, and Root Cause Analysis.

---

## 1. Executive Summary & Architecture Overview

Medical Plus v3 is an hybrid **Offline-First Medical Workspace Application** built on top of Laravel 11, Inertia.js (Vue 3), and NativePHP Mobile (Android WebView + Embedded PHP Runtime).

The application operates in two distinct execution environments:
1. **Mobile Client (Offline-First / Embedded PHP)**: Runs an embedded PHP SAPI engine directly inside the Android application process, using SQLite as the local source of truth.
2. **Production Server (Remote Backend)**: Runs a standard web server (Nginx/PHP-FPM) backed by MySQL/MariaDB, acting as the central data authority.

```
+-------------------------------------------------------------------------------+
|                             NATIVEPHP MOBILE APP                              |
|                                                                               |
|   Vue 3 (Inertia Frontend) <---> Local HTTP / Web Bridge                      |
|                                          |                                    |
|                                          v                                    |
|                        Embedded PHP Runtime (SQLite Driver)                   |
|                        - Stores Patients, Notes, Files locally                |
|                        - Manages Pending Upload Queue (offline_files)         |
+-------------------------------------------------------------------------------+
                                           |
                                           |  Sync Engine (SyncEngineService)
                                           |  REST API / Bearer Token Auth
                                           v
+-------------------------------------------------------------------------------+
|                            PRODUCTION LARAVEL SERVER                          |
|                                                                               |
|   Nginx / PHP-FPM Backend (MySQL Driver)                                      |
|   - Central Database of Record                                                |
|   - Authenticated via Laravel Sanctum (Bearer Token)                          |
|   - Handles Storage, Processing & File Streaming                              |
+-------------------------------------------------------------------------------+
```

### Key Architectural Pillars
- **Dual-Database Abstraction**: The code detects its execution context dynamically via `config('database.default') === 'sqlite'`.
- **Decoupled Offline-First Storage**: Operations performed offline are written directly to SQLite with state markers (`pending_create`, `pending_update`, `pending_upload`, `pending_delete`).
- **Background Synchronization Engine**: `SyncEngineService` handles bidirectional background syncing using atomic database status transitions and multi-path connectivity triggers.

---

## 2. Environment & Routing Architecture

### 2.1 Route Partitioning & Guarding Architecture
The application splits routes into two distinct categories:

| Route Group | Base Endpoint | Authentication Strategy | Primary Target |
| :--- | :--- | :--- | :--- |
| **Native Local API** | `/_native/api/*`, `/_native/cache/*` | Bypassed / Local User Resolver (`database.default === 'sqlite'`) | Local Embedded SQLite & Storage |
| **Production Remote API** | `/api/v1/*` | Laravel Sanctum (`auth:sanctum` Bearer Token) | Production Remote MySQL Database |

```
Route Request
  ├── Starts with `/_native/*`
  │     └── Directs to Local Controllers (e.g. OfflineUploadController, FileAccessController)
  │           ├── SQLite DB Read/Write
  │           └── Local Storage (`storage/app/uploads/pending`)
  │
  └── Starts with `/api/v1/*`
        ├── Mobile (SQLite context): Handled via ChunkUploadController / Local direct handler
        └── Production (MySQL context): Authenticated via Sanctum -> Remote Database & S3/Local Disk
```

### 2.2 Android Web Bridge & Multipart Request Parsing
In the NativePHP Android environment, Chromium's WebView communicates with the embedded C SAPI bridge (`php_bridge.c`). 
- **The Issue**: Non-JSON multipart form submissions sent from WebView often have `Content-Type` headers altered or boundary strings wrapped differently.
- **The Solution**: `ParseMobileMultipartMiddleware` intercepts incoming `POST`/`PUT`/`PATCH` requests, inspects `php://input`, extracts raw boundary tokens, creates temporary file objects via `UploadedFile`, and populates `$request->request` and `$request->files`.

---

## 3. Data Storage & Lifecycle Management

### 3.1 SQLite Schema & Local Models
When operating on mobile (`sqlite`), four primary tables govern local operations:
1. `patients`: Stores local patient demographics with `uuid`, `sync_status` (`pending_create`, `pending_update`, `syncing`, `synced`, `pending_delete`).
2. `patient_files`: Stores file records uploaded locally or synced from remote.
3. `patient_notes`: Stores clinical notes created offline.
4. `offline_files`: Dedicated queue table for pending file uploads created while offline.

### 3.2 State Machine Transitions

#### Patient Lifecycle State Machine
```
[User Action: Create Patient]
         │
         ▼
 (pending_create) ──────► (syncing) ──────► (synced)
         ▲                    │
         │ (API Failed)       │ (New Remote UUID Assigned)
         └────────────────────┴───────────► Re-maps local UUID -> Remote UUID
```

#### Offline File Lifecycle State Machine
```
[User Action: Add File Offline]
         │
         ▼
  (pending_upload) ──────► (uploading) ──────► (synced)
         ▲                      │                  │
         │ (Retry < 10)         │ (Failed)         └─► Deletes local pending binary
         └──────────────────────┴────────────► (failed)
```

---

## 4. File Upload & Media Storage Systems

The application provides three separate pathways for file uploads depending on connection status and file type.

```
                            [Upload Request]
                                   │
                    ┌──────────────┴──────────────┐
                    │  Is Network Online?         │
                    └──────────────┬──────────────┘
                                   │
               ┌───────────────────┴───────────────────┐
               │ YES                                   │ NO
               ▼                                       ▼
     Is File a Video?                      Use `useOfflineUploads()`
      ┌────────┴────────┐                              │
      │ YES             │ NO                           ▼
      ▼                 ▼                  POST `/_native/api/offline/uploads`
  Chunk Upload      Direct Upload                      │
(useUploads.js)    (uploadDirectly)                    ▼
      │                 │                 Saved to `uploads/pending/{uuid}.{ext}`
      ▼                 ▼                  Inserted into `offline_files` table
  `/api/v1/chunk`   `/api/v1/mobile/...`               │
      │                 │                              │ (Sync Engine Runs Later)
      └────────┬────────┘                              ▼
               ▼                             Pushed to Remote API
     `patient_files` Table                             │
                                                       ▼
                                            Status updated to `synced`
```

### 4.1 Upload Pipelines Comparison

| Pipeline | Trigger Condition | Handler Controller | Binary Storage Location | DB Table Used |
| :--- | :--- | :--- | :--- | :--- |
| **Direct Upload (Online Non-Video)** | Online + Non-video file (Image, PDF, Document) | `UploadsController` / `UploadService` | `storage/app/patients/{patient_uuid}/{file_uuid}.{ext}` | `patient_files` |
| **Chunked Upload (Video Files)** | Online + Video file | `ChunkUploadController` (`init`, `upload`, `complete`) | Chunks written directly / Merged via `ChunkMergeService` | `upload_sessions` & `patient_files` |
| **Offline Upload (Any File)** | Device is Offline | `OfflineUploadController` | `storage/app/uploads/pending/{uuid}.{ext}` | `offline_files` |

### 4.2 File Viewing & Preview Mechanisms
File access on mobile uses `FileAccessController`:
- **Route**: `GET /_native/cache/files/{uuid}`
- **Resolution Strategy**:
  1. Checks `patient_files` table first. If found on local disk, streams directly via `streamDirect()` with byte-range support (`206 Partial Content`).
  2. If not in `patient_files`, checks `offline_files` queue table and streams directly from `storage/app/uploads/pending/`.
  3. If missing locally, falls back to `FileCacheRepositoryInterface` to download from remote server.

- **Thumbnail Generation**:
  - `FileAccessController::thumbnailDirect()` checks for existing `thumbnail_path`.
  - On mobile WebViews, `ffmpeg` or GD library thumbnail generation is invoked on-demand.
  - If thumbnail generation fails or image is already small, falls back to returning the full original image binary.

---

## 5. Synchronization Engine Architecture

The sync engine (`SyncEngineService` in PHP and `useSyncEngine.js` in Vue) is responsible for data convergence between local SQLite and remote MySQL.

### 5.1 Strict Operational Sync Order
To preserve relational integrity, the Sync Engine executes tasks in a strict sequential order:

1. **Pending Patients** (`syncPendingPatients`):
   - Pushes `pending_create` and `pending_update` patients to remote API.
   - If the remote server assigns a new UUID, `SyncEngineService` atomically updates the local `uuid` and remaps linked records in `offline_files`.
2. **Pending Offline Files** (`syncPendingFiles`):
   - Uploads binary files registered in `offline_files`.
   - **Pre-requisite Check**: Verifies that the associated patient has `sync_status === 'synced'` on remote before uploading.
3. **Local Unsynced Patient Files** (`syncLocalPatientFiles`):
   - Finds records in `patient_files` created locally with `remote_uuid = NULL` and pushes them to remote API.
4. **Pending Clinical Notes** (`syncPendingNotes`):
   - Pushes unsynced clinical notes to remote API.
5. **Pending Deletions**:
   - `processPendingDeletes()` (Patients)
   - `processPendingFileDeletes()` (Files)
   - `processPendingFileUpdates()` (File metadata updates)

### 5.2 Network Detection & Resilience (5 Trigger Paths)
`useSyncEngine.js` employs 5 redundant triggers to guarantee syncing when connectivity returns:
1. **Browser Online Event**: `window.addEventListener('online')`
2. **Network Information API**: `navigator.connection.addEventListener('change')`
3. **App Visibility & Focus**: `document.addEventListener('visibilitychange')` & `window.addEventListener('focus')`
4. **Native Android Bridge Callback**: Direct JS evaluation from Android `NetworkStateManager` (`window.__onNetworkAvailable()`).
5. **30-Second Heartbeat**: Background interval that checks pending summary counts and triggers auto-heal sync if un-synced data exists.

---

## 6. Root Cause Analysis of Specific Bug Scenarios

### Bug Scenario A: "Uploaded images appear as empty cards or fail to preview"
* **Root Cause 1 - Schema Attribute Mismatch**: `FileResource.php` and local JS composables expect properties like `url` and `thumbnail_url`. `PatientFile.php` provides `getUrlAttribute()` which generates `/_native/cache/files/{uuid}` on SQLite. However, when an image is saved via offline upload, it exists in `offline_files` table rather than `patient_files`. If the UI attempts to query `PatientFile` before sync, it returns 404.
* **Root Cause 2 - Thumbnail Generation on Mobile**: `thumbnailDirect()` in `FileAccessController.php` attempts to execute system `ffmpeg` via `exec()`. In standard Android WebView/NativePHP environments, shell `exec()` is restricted or `ffmpeg` binary is absent, causing thumbnail generation to fail without fallback if `mime_type` check misses.
* **Root Cause 3 - Image Upload Routing**: Image uploads were previously routed to chunk upload handlers when network state fluctuated, leading to zero-byte final chunks or chunk size validation errors.

### Bug Scenario B: "Upload completion failed: Direct-write file empty"
* **Root Cause**: In `ChunkMergeService.php`, when direct-write chunk streaming is used, `final_path` is expected to be populated continuously. If the mobile network connection drops mid-chunk or if `ParseMobileMultipartMiddleware` strips part of a chunk payload during a retry, the output file on disk remains 0 bytes, triggering `RuntimeException("Direct-write file is empty")`.

### Bug Scenario C: "Patient 404 during offline file upload"
* **Root Cause**: Previously, `OfflineUploadController::store()` called `Patient::where('uuid', ...)->firstOrFail()`. When a patient was created offline (`pending_create`), its record existed locally, but if the patient UUID had not yet synced to the server, certain API routes threw a 404. 
* **Fix Applied**: Implemented stub patient fallback creation in `OfflineUploadController` to ensure SQLite integrity.

---

## 7. Recommendations & Stabilization Roadmap

### Short-Term Recommendations
1. **Unified Image Preview Fallback**: Update `FileAccessController` to immediately serve the original image binary for thumbnails when `mime_type` starts with `image/`, avoiding unnecessary shell execution attempts.
2. **Explicit Offline Upload Handling in UI**: Ensure `UnifiedMediaViewer.vue` handles `/_native/cache/files/{uuid}` endpoints gracefully for both `patient_files` and `offline_files` records.
3. **Session & Auth Token Persistence**: Continue utilizing the Bearer token header directly in `useSyncEngine.js` requests to avoid session loss in stateless API routes.

### Long-Term Recommendations
1. **SQLite Database Concurrency Optimizations**: Enable Write-Ahead Logging (WAL mode) on local SQLite databases in NativePHP to prevent database lock contention between background sync engine runs and UI user operations.
2. **Automated End-to-End Verification Pipeline**: Implement regression test suites specifically testing offline binary file creation, network toggle simulation, and sync completion.

---
*End of Audit Document.*
