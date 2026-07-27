# PROJECT_BRAIN.md — Medical Plus Architecture Knowledge Base

> **Reverse-engineered from the entire codebase. This is the permanent reference for debugging, feature development, and architecture understanding.**

---

## 1. High-Level Architecture

### System Overview

Medical Plus is a **dual-deployment Laravel + Vue 3 medical record management system** with offline-first capabilities. It runs in two modes:

1. **Production Server (MySQL)** — Traditional Laravel web app serving doctors via browser.
2. **Embedded Laravel (SQLite via NativePHP Android)** — Local-first mobile app running inside an Android WebView. This is the primary deployment: a self-contained PHP process on the phone.

### Frontend

| Layer | Technology | Location |
|-------|-----------|----------|
| UI Framework | Vue 3 (Composition API, `<script setup>`) | `resources/js/` |
| SPA Routing | Inertia.js | `resources/js/app.js` |
| HTTP Client | Axios (global + dedicated upload instance) | `resources/js/bootstrap.js`, `resources/js/Composables/useUploads.js` |
| State Management | Module-level reactive state in composables (NO Vuex/Pinia) | `resources/js/Composables/` |
| Theming | Tailwind CSS + dark mode | `resources/css/app.css` |
| i18n | vue-i18n (Arabic + English) | via `useLocale()` composable |
| Build | Vite | `vite.config.js` |

### Backend

| Layer | Technology | Location |
|-------|-----------|----------|
| Framework | Laravel 11 | `app/`, `routes/` |
| Database (server) | MySQL | `config/database.php` |
| Database (embedded) | SQLite | `storage/data/medical_plus.sqlite` |
| Authentication (server) | Laravel Sanctum | `config/sanctum.php` |
| Authentication (embedded) | Auto-login (single-user device) | `routes/web.php` session/restore |
| Queue | Laravel Queue (server only) | `config/queue.php` |
| Cache | Laravel Cache | `config/cache.php` |

### The Two-Database Architecture

```
┌─────────────────────────────────────────────────────────┐
│  PRODUCTION SERVER (MySQL)                              │
│  ┌─────────────────┐  ┌──────────────────┐             │
│  │  patients        │  │  patient_files   │             │
│  │  patient_notes   │  │  patient_visits  │             │
│  │  patient_shares  │  │  users           │             │
│  │  upload_sessions │  │  cache           │             │
│  └─────────────────┘  └──────────────────┘             │
└────────────────────────┬────────────────────────────────┘
                         │ SyncEngineService
                         │ (patients → files → notes → deletes)
                         │
┌────────────────────────▼────────────────────────────────┐
│  EMBEDDED LARAVEL (SQLite on Android phone)              │
│  ┌─────────────────┐  ┌──────────────────┐             │
│  │  patients        │  │  patient_files   │             │
│  │  patient_notes   │  │  offline_files   │  ← NEW     │
│  │  patient_visits  │  │  file_cache      │  ← NEW     │
│  │  patient_shares  │  │  cached_categories│ ← NEW     │
│  │  sync_meta       │  │  upload_sessions │             │
│  └─────────────────┘  └──────────────────┘             │
│                                                          │
│  storage/app/uploads/pending/  ← physical offline files  │
│  storage/app/cache/files/      ← cached remote files     │
└──────────────────────────────────────────────────────────┘
```

### Repository Pattern (Orchestrator Pattern)

The repository layer is the **core architectural pattern**. Each entity has:

```
PatientRepositoryInterface (contract)
       │
       ▼
PatientRepository (orchestrator)
  ├── ApiPatientRepository    → calls remote production API
  └── EloquentPatientRepository → reads/writes local SQLite
```

**Decision logic in orchestrator:**
- **Reads**: Try API first → fallback to local SQLite
- **Writes**: Try API first → on failure, save locally with `sync_status = 'pending_*'`
- **Deletes**: Mark `sync_status = 'pending_delete'`, soft-delete locally, try API delete

**All 8 repository bindings** (from `RepositoryServiceProvider`):

| Interface | Binding | Purpose |
|-----------|---------|---------|
| `PatientRepositoryInterface` | `PatientRepository` | Orchestrator (API + local) |
| `UserRepositoryInterface` | `EloquentUserRepository` | Local only |
| `PatientFileRepositoryInterface` | `EloquentPatientFileRepository` | Local only |
| `PatientNoteRepositoryInterface` | `EloquentPatientNoteRepository` | Local only |
| `PatientVisitRepositoryInterface` | `EloquentPatientVisitRepository` | Local only |
| `FileCacheRepositoryInterface` | `FileCacheRepository` | Phase 6 offline cache |
| `OfflineFileRepositoryInterface` | `OfflineFileRepository` | Phase 7 pending uploads |
| `CategoryRepositoryInterface` | `CategoryRepository` | Orchestrator (API + local cache) |

### Authentication

**Two separate auth systems coexist:**

1. **Web Session Auth** (embedded Laravel):
   - Auto-login at `/api/session/restore` — finds `User::first()` in local SQLite
   - Session stored in `storage/framework/sessions/`
   - No Sanctum needed locally

2. **Sanctum Bearer Token** (production API):
   - Obtained via `ApiService::loginToRemote(email, password)`
   - Stored encrypted in 3 places: `session('api_token')`, `storage/app/.api_sync_token`, and frontend `localStorage['np_api_token']`
   - Used by SyncEngineService for all production API calls
   - Token refresh: `SyncEngineService::refreshToken()` uses stored encrypted credentials

### Offline Layer

**5 detection paths for network state:**
1. Browser `online`/`offline` events
2. Network Information API (`navigator.connection`)
3. Visibility/focus changes (user returns to app)
4. Native Android bridge callbacks (`window.__onNetworkAvailable`)
5. Periodic heartbeat (30s interval)

### Upload System

**Two upload paths:**

1. **Online Chunked Upload** (`useUploads.js`):
   - 5 MB chunks, 4 parallel slots
   - Separate Axios instance (`uploadHttp`) to avoid blocking app requests
   - Priority scheduler: normal app requests pause upload chunks
   - Resume via `localStorage['upload_sessions']`
   - Endpoint: `/api/v1/chunk/init` → `/api/v1/chunk/chunk` → `/api/v1/chunk/complete`

2. **Offline Upload** (`useOfflineUploads.js`):
   - POST to `/_native/api/offline/uploads`
   - Saves to `storage/app/uploads/pending/{uuid}.{ext}`
   - Metadata in `offline_files` table with `sync_status = 'pending_upload'`
   - SyncEngineService uploads when connectivity returns

---

## 2. Request Lifecycle

### Patient List Load

```
DoctorWorkspace.vue (onMounted)
  │
  ├─► setPatients(props.patients)           ← Inertia SSR props (instant)
  │
  └─► refreshPatientList()                  ← async
        │
        ├─ STEP 0: Snapshot all current patients (safety net)
        ├─ STEP 1: POST /_native/api/sync/patients  ← sync pending patients to server
        ├─ STEP 1b: refreshCategoryCache()
        ├─ STEP 2: GET /_native/api/patients/pending ← load pending from SQLite
        ├─ STEP 3: GET /api/v1/workspace/patients-list ← fetch from API
        └─ STEP 4: Merge (local pending + safety-net preserved + API data)
              │
              ▼
        patients.value = merged list
```

### Patient Selection

```
User clicks patient in PatientListSidebar
  │
  └─► selectPatient(uuid)
        │
        ├─► window.history.pushState({ view: "patient", uuid }, "", "#patient")
        ├─► GET /api/v1/workspace/{uuid}        ← WorkspaceController::patientData()
        │     │
        │     ├─ PatientRepository::findByUuid()   ← reads from local SQLite (EloquentPatientRepository)
        │     ├─ PatientFileRepository::forPatient() ← reads from local SQLite
        │     ├─ OfflineFileRepository::findByPatientUuid() ← merge pending offline uploads
        │     ├─ PatientNoteRepository::forPatient()
        │     ├─ PatientVisitRepository::forPatient()
        │     ├─ CategoryRepository::all()          ← API-first, local fallback
        │     ├─ PatientPolicy::canEdit/canDelete   ← permission checks
        │     └─ Return: { patient, files, notes, visits, categories, stats, permissions }
        │
        ├─► workspaceData.value = response.data
        ├─► Auto-expand all categories
        └─► Rehydrate offline pending uploads from /_native/api/offline/uploads
```

### Create Patient

```
AddPatientModal → submitForm()
  │
  └─► useWorkspace().addPatient(formData)
        │
        ├─ [online]  → POST /api/v1/mobile/patients    ← goes to production server
        └─ [offline] → POST /api/v1/workspace/patients  ← goes to embedded Laravel
              │
              ├─ WorkspaceController::storePatient()
              │     ├─ Captures Bearer token from request (always sent)
              │     ├─ PatientRepository::create()
              │     │     ├─ [API success] → createOnRemote() → syncSingleToLocal() → sync_status = 'synced'
              │     │     └─ [API fail]    → save locally with sync_status = 'pending_create'
              │     └─ Return patient with uuid
              │
              └─► upsertPatient(patient)  ← adds to patients.value immediately
              └─► selectedPatientId.value = patient.uuid
```

### Create Note

```
DoctorWorkspace.vue → submitNoteForm()
  │
  ├─ [online]  → POST /api/v1/mobile/patients/{uuid}/notes
  ├─ [offline] → POST /api/v1/mobile/patients/{uuid}/notes  ← same endpoint
  │     │
  │     └─ NoteController::store()
  │           ├─ [has user]  → create note normally
  │           └─ [no user]   → create note with sync_status = 'pending_create'
  │
  ├─► addNoteLocally(note)    ← IMMEDIATELY adds to workspaceData
  ├─► refreshWorkspaceData()   ← re-fetches (merges local pending notes)
  └─► POST /_native/api/sync/engine  ← fire-and-forget sync trigger
```

### File Upload (Online)

```
CategoryBlock → handleFileUpload(file)
  │
  ├─ [navigator.onLine = true]
  │     └─► useUploads().uploadFile(file, patientUuid, metadata)
  │           │
  │           ├─ createJob() → add to uploads.value
  │           ├─ startUpload()
  │           │     ├─ Resume check (localStorage)
  │           │     ├─ POST /api/v1/chunk/init     ← ChunkUploadController::init()
  │           │     │     └─ UploadSessionService::create()
  │           │     ├─ runPool() → parallel chunk uploads
  │           │     │     └─ POST /api/v1/chunk/chunk  ← ChunkUploadController::chunk()
  │           │     │           └─ ChunkUploadService::storeChunk()
  │           │     └─ POST /api/v1/chunk/complete  ← ChunkUploadController::complete()
  │           │           └─ ChunkMergeService::merge() → creates PatientFile
  │           └─► addFileLocally({ uuid, url, ... }) ← UI update
  │
  └─ [navigator.onLine = false]
        └─► useOfflineUploads().uploadFile(file, patientUuid)
              │
              ├─ POST /_native/api/offline/uploads
              │     └─ OfflineUploadController::store()
              │           ├─ OfflineUploadService::saveLocally() → disk
              │           └─ OfflineFileRepository::create() → SQLite (sync_status = 'pending_upload')
              │
              └─► addFileLocally({ uuid, sync_status: 'pending_upload', ... })
```

### Offline Sync (When Internet Returns)

```
useSyncEngine detects online transition (any of 5 paths)
  │
  └─► attemptSync('source')
        │
        └─► triggerSync()
              │
              └─► POST /_native/api/sync/engine
                    │
                    └─ SyncEngineService::syncAll()
                          │
                          ├─ STEP 1: syncPendingPatients()
                          │     ├─ Find patients with sync_status = 'pending_create' or 'pending_update'
                          │     ├─ Atomic claim: status → 'syncing' (prevents duplicate uploads)
                          │     ├─ patientRepo->createOnRemote() or updateOnRemote()
                          │     ├─ On success: sync_status → 'synced' + update local record
                          │     ├─ On failure: revert to 'pending_create'
                          │     └─ Recover stuck 'syncing' records (>30 min)
                          │
                          ├─ STEP 2: syncPendingFiles()
                          │     ├─ Find offline_files with sync_status = 'pending_upload' or 'failed'
                          │     ├─ CRITICAL CHECK: skip if patient is not yet synced
                          │     ├─ Atomic claim: status → 'uploading'
                          │     ├─ uploadSingleFile() → ApiService::upload()
                          │     ├─ On success: markSynced() + deleteLocal()
                          │     └─ On failure: incrementRetry() + revert to 'pending_upload'
                          │
                          ├─ STEP 3: syncPendingNotes()
                          │     ├─ Find PatientNote with sync_status = 'pending_create'
                          │     ├─ Skip if patient is not synced
                          │     ├─ POST /patients/{uuid}/notes via ApiService
                          │     └─ Mark as synced or log failure
                          │
                          └─ STEP 4: processPendingDeletes()
                                ├─ Find Patient with sync_status = 'pending_delete'
                                ├─ patientRepo->deleteOnRemote()
                                └─ forceDelete() locally
```

### Refresh Workspace Data

```
refreshWorkspaceData()
  │
  ├─ Snapshot entire workspaceData (JSON clone)
  ├─ GET /api/v1/workspace/{uuid}
  ├─ Merge strategy:
  │     ├─ Notes: keep local notes where sync_status = 'pending_create' AND not in server response
  │     ├─ Files: keep local files where sync_status = 'pending_upload'/'uploading' AND not in server response
  │     ├─ Visits: preserve local visits not in server response
  │     ├─ Categories: fallback to snapshot if server returns empty
  │     └─ Stats: fallback to snapshot if server returns empty
  └─ workspaceData.value = merged data
```

---

## 3. Dependency Graph

### Frontend Composable Dependencies

```
useWorkspace.js  ← CORE STATE HUB
  │
  ├── useUploads.js
  │     ├── useUploadDiagnostics.js
  │     └── useWorkspace.js (addFileLocally)
  │
  ├── useSyncEngine.js
  │     └── useWorkspace.js (refreshPatientList)
  │
  ├── useOfflineUploads.js
  │     ├── useNativeBridge.js
  │     └── useWorkspace.js (addFileLocally, updateFileLocally, removeFileLocally)
  │
  └── Used by 15+ components:
        ├── DoctorWorkspace.vue
        ├── PatientListSidebar.vue
        ├── CategoryBlock.vue
        ├── PatientSummary.vue
        ├── FileActions.vue
        ├── AddPatientModal.vue
        ├── AddRecordModal.vue
        ├── InlineFilePreview.vue
        ├── WorkspaceHeader.vue
        ├── MobileBottomBar.vue
        ├── UploadManager.vue
        └── AppLayout.vue
```

### Backend Service Dependencies

```
SyncEngineService
  ├── PatientRepository (orchestrator)
  │     ├── ApiPatientRepository
  │     │     └── MakesApiRequests → ApiService
  │     └── EloquentPatientRepository (SQLite)
  ├── OfflineFileRepositoryInterface → OfflineFileRepository
  ├── OfflineUploadService
  └── ApiService (for token, HTTP calls)

WorkspaceController
  ├── PatientRepositoryInterface
  ├── PatientFileRepositoryInterface
  ├── PatientNoteRepositoryInterface
  ├── PatientVisitRepositoryInterface
  ├── UserRepositoryInterface
  ├── OfflineFileRepositoryInterface
  └── CategoryRepositoryInterface

ApiService (singleton)
  ├── Token storage: session('api_token') + file + constructor restore
  ├── HTTP client: Http::timeout(30)
  └── loginToRemote() → production /login endpoint
```

### API Routing Architecture

```
routes/web.php:
  /                         → redirect to /dashboard or /admin/doctors
  /login                    → AuthController::showLogin
  /workspace                → WorkspaceController::index (Inertia render)
  /dashboard                → DashboardController::index
  /admin/*                  → Admin routes (role:super-admin)
  /settings/*               → SettingsController

  api/v1/*                  → JSON API (CSRF excluded)
    /workspace/patients-list    → WorkspaceController::patientList
    /workspace/{uuid}           → WorkspaceController::patientData
    /workspace/patients         → WorkspaceController::storePatient
    /chunk/*                    → ChunkUploadController (upload pipeline)
    /patients/{uuid}/notes      → NoteController
    /categories                 → CategoryController
    /search                     → GlobalSearchController

  _native/api/offline/*     → Offline endpoints (CSRF excluded)
    /uploads                 → OfflineUploadController
    /notes                   → OfflineNoteController

  _native/api/sync/*        → Sync endpoints (CSRF excluded)
    /engine                  → SyncEngineService::syncAll()
    /patients                → PatientRepository::syncPending()
    /pending-summary         → SyncEngineService::getPendingSummary()

  _native/cache/*           → File cache endpoints (CSRF excluded)
    /files/{uuid}            → FileAccessController::streamCached

routes/api.php:
  /v1/login                 → Api AuthController::login
  /v1/mobile/*              → Mobile API (Sanctum on production, no auth on embedded)
    /patients               → PatientController
    /patients/{uuid}/notes  → NoteController
    /patients/{uuid}/files  → FileController
    /uploads/*              → UploadsController (resumable)
```

---

## 4. File Responsibilities

### Frontend Core Files

| File | Purpose | Who calls it | What enters | What leaves |
|------|---------|--------------|-------------|-------------|
| `useWorkspace.js` | **Central state hub.** Owns patients, workspaceData, selectedPatient, all computed states. | All workspace components | Patient UUIDs, API responses | Reactive state, async data | **NEVER**: Business logic, API calls to other entities, component-specific UI state |
| `useUploads.js` | Online chunked upload pipeline. 5MB chunks, 4 parallel slots, resume support. | CategoryBlock, AddRecordModal, UploadManager | File objects, patientId | Upload jobs with progress | **NEVER**: Patient/note CRUD, offline logic |
| `useSyncEngine.js` | Monitors connectivity. Triggers sync when online. Singleton pattern. | AppLayout, PatientListSidebar | Network events | isOnline, isSyncing, pendingSummary | **NEVER**: Direct API calls, data transformations |
| `useOfflineUploads.js` | Offline file saving. Strictly offline-only (throws if online). | CategoryBlock, AddRecordModal | File, patientUuid | Offline job with sync_status | **NEVER**: Online upload logic, chunked uploads |
| `useNativeBridge.js` | Android WebView bridge for camera, file picker, permissions. | useOfflineUploads, CategoryBlock | Permission requests | File objects | **NEVER**: Business logic, API calls |
| `useDialog.js` | Global dialog state (confirm, alert). Singleton. | 10+ components | Dialog config | Promise<boolean> | **NEVER**: Data fetching, state management |
| `useToast.js` | Global toast notifications. Singleton. | 15+ components | Toast message | None (fire & forget) | **NEVER**: Dialog logic, blocking operations |
| `useTheme.js` | Dark/light mode toggle. Persists to localStorage. | AppLayout, SettingsModal | Theme name | Current theme | **NEVER**: API calls, complex state |
| `useLocale.js` | Arabic/English locale. Persists to localStorage. | AppLayout, SettingsModal | Locale name | Current locale, isRtl | **NEVER**: API calls, translation loading |
| `usePullToRefresh.js` | Touch gesture handling for pull-to-refresh. | DoctorWorkspace, PatientListSidebar | Touch events | pullDistance, isRefreshing | **NEVER**: Data fetching, state mutations |
| `Utils/api.js` | URL prefix detection (NativePHP vs browser). Token config. | CategoryBlock, AddRecordModal | API path | Correct URL, headers | **NEVER**: State management, side effects |

### Backend Core Files

| File | Purpose | What enters | What leaves |
|------|---------|-------------|-------------|
| `SyncEngineService.php` | **Heart of offline sync.** Ordered: patients→files→notes→deletes. Atomic claims. Recovery logic. | Triggered by frontend or heartbeat | Sync results (counts) | **NEVER**: Direct database queries outside repository pattern |
| `ApiService.php` | **HTTP client to production server.** Token management (session+file). Retry logic. Singleton. | SyncEngine, Repositories | API responses | **NEVER**: Business logic, data transformation |
| `PatientRepository.php` | **Orchestrator.** API-first reads, write-with-fallback. Cache sync. | WorkspaceController, SyncEngine | Patient data | **NEVER**: Direct Eloquent queries (delegate to sub-repos) |
| `CategoryRepository.php` | **Orchestrator.** API-first with local SQLite cache fallback. | WorkspaceController | Category data | **NEVER**: Direct API calls (delegate to ApiCategoryRepository) |
| `FileCacheRepository.php` | Manages local file cache. LRU eviction (500MB limit). Range request support. | FileAccessController | StreamedResponse | **NEVER**: Upload logic, sync operations |
| `OfflineFileRepository.php` | CRUD for offline_files table. Tracks pending uploads. | SyncEngine, OfflineUploadController | Status info | **NEVER**: Physical file operations (delegate to OfflineUploadService) |
| `OfflineUploadService.php` | Physical file operations. Save to disk, hash, delete. | OfflineUploadController, SyncEngine | File metadata | **NEVER**: Database operations (delegate to repositories) |
| `WorkspaceController.php` | **Primary controller.** Patient list, data, CRUD. Assembles full workspace payload. | Inertia routes, API routes | JSON responses | **NEVER**: Business logic (delegate to repositories/services) |
| `ChunkUploadController.php` | Chunked upload pipeline. Init→chunk→complete→merge. | Frontend useUploads | Upload session state | **NEVER**: File processing (delegate to services) |
| `OfflineNoteController.php` | Note creation when offline. sync_status = pending_create. | Frontend (offline path) | Note data | **NEVER**: Online note creation (use NoteController) |

### Critical Configuration

| File | Key Setting | Impact |
|------|-------------|--------|
| `config/app.php` | `mobile_api_url` | Base URL for production API calls |
| `config/database.php` | `default` → `sqlite` or `mysql` | Determines embedded vs production mode |
| `config/sanctum.php` | Guards, stateful domains | Authentication behavior |
| `config/categories.php` | Default category definitions | Fallback when offline + no cache |

---

## 5. Critical Files (Ranked)

### ★★★★★ Extremely Critical

| File | Why |
|------|-----|
| `resources/js/Composables/useWorkspace.js` | **The brain of the frontend.** Owns ALL shared state. Every patient/note/file mutation flows through here. One wrong change here breaks everything. |
| `app/Services/SyncEngineService.php` | **The brain of sync.** Ordered synchronization, atomic claims, recovery logic. A bug here causes data loss or duplication. |
| `app/Services/Mobile/ApiService.php` | **The bridge to production.** Token management, all remote HTTP calls. Token bugs cause permanent 401 loops. |
| `app/Repositories/PatientRepository.php` | **The patient orchestrator.** API-first with local fallback. Cache sync. A bug here causes patient data inconsistency. |
| `routes/web.php` | **Route map.** CSRF exemptions, auth middleware, _native routes. Misconfiguration breaks offline or auth. |

### ★★★★ Important

| File | Why |
|------|-----|
| `resources/js/Composables/useUploads.js` | Chunked upload pipeline with concurrency control. Upload failures are hard to debug. |
| `resources/js/Composables/useSyncEngine.js` | Network detection + sync triggering. 5 detection paths. Singleton. |
| `resources/js/Composables/useOfflineUploads.js` | Offline file save path. Strictly offline-only guard. |
| `app/Http/Controllers/WorkspaceController.php` | Primary controller. Assembles workspace payload with permissions. |
| `app/Repositories/CategoryRepository.php` | Category orchestrator with 3-level fallback (API→SQLite→config). |
| `app/Services/OfflineUploadService.php` | Physical file operations for offline uploads. Disk management. |
| `resources/js/Utils/api.js` | Environment detection (NativePHP vs browser). URL rewriting. |
| `app/Providers/RepositoryServiceProvider.php` | All 8 repository bindings. Wrong binding = wrong behavior. |

### ★★★ Medium

| File | Why |
|------|-----|
| `app/Http/Controllers/Api/ChunkUploadController.php` | Upload init/chunk/complete pipeline. |
| `app/Http/Controllers/Api/OfflineUploadController.php` | Offline file CRUD. |
| `app/Http/Controllers/Api/NoteController.php` | Note CRUD with offline fallback. |
| `app/Http/Controllers/Api/OfflineNoteController.php` | Offline note creation. |
| `app/Repositories/FileCacheRepository.php` | File caching with LRU. |
| `app/Repositories/OfflineFileRepository.php` | offline_files table CRUD. |
| `resources/js/Pages/DoctorWorkspace.vue` | Main workspace page. Orchestrates all components. |
| `resources/js/Components/workspace/CategoryBlock.vue` | Category display + file/note management. Upload routing. |
| `app/Domains/Patients/Models/Patient.php` | Patient model. SoftDeletes. DoctorIsolationScope. UUID auto-generation. |
| `app/Domains/Patients/Models/PatientNote.php` | Note model. sync_status support. |

### ★★ Low

| File | Why |
|------|-----|
| `app/Http/Controllers/AuthController.php` | Login/logout. Token acquisition. |
| `app/Domains/Auth/Actions/LoginAction.php` | Token creation for production API. |
| `app/Services/Mobile/FileCacheService.php` | Low-level file streaming. |
| `app/Jobs/ProcessUploadedFileJob.php` | Background video processing. |
| `app/Observers/PatientFileObserver.php` | Triggers video optimization job. |
| `app/Policies/PatientPolicy.php` | Authorization rules. |

---

## 6. Problem Locator

| # | Problem | Likely Files | Reason |
|---|---------|-------------|--------|
| 1 | Patient list not refreshing | `useWorkspace.js` (refreshPatientList), `WorkspaceController::patientList`, `PatientRepository::paginated` | refreshPatientList owns state. 4-step merge logic. |
| 2 | Patient disappeared after sync | `useWorkspace.js` (refreshPatientList STEP 4), `SyncEngineService::syncPendingPatients` | Safety-net preservation logic or UUID mismatch |
| 3 | Patient created but invisible | `useWorkspace.js` (addPatient), `PatientRepository::create` | Missing upsertPatient() or sync_status issue |
| 4 | Note duplicated after sync | `useWorkspace.js` (refreshWorkspaceData merge), `SyncEngineService::syncPendingNotes` | Local note not filtered when server has remote UUID |
| 5 | Note not appearing | `useWorkspace.js` (addNoteLocally), `NoteController::store` | addNoteLocally not called or sync_status not set |
| 6 | Attachment upload fails | `useUploads.js` (startUpload), `ChunkUploadController::init/chunk/complete`, `UploadSessionService` | Chunk init failure, merge failure, disk full |
| 7 | Attachment stuck "pending_upload" | `SyncEngineService::syncPendingFiles`, `ApiService::upload`, token issue | Patient not synced yet, token expired, API unreachable |
| 8 | Offline file not uploading | `SyncEngineService::syncPendingFiles` (patient check), `ApiService` (token) | Patient must sync before files. Token must be valid. |
| 9 | File preview broken | `FileAccessController::streamDirect`, `FileCacheRepository::stream`, `FileCacheService` | Cache miss, file deleted, wrong path |
| 10 | Authentication failing (401 loop) | `ApiService.php` (token management), `SyncEngineService::refreshToken` | Token cleared on 401, credential refresh fails |
| 11 | Token not persisting after restart | `ApiService::setToken`, `routes/web.php` session/restore, `bootstrap.js` | 3 storage locations must all sync |
| 12 | Sync engine not running | `useSyncEngine.js` (5 detection paths), `SyncEngineService::syncAll` | No online detection firing, token missing, stuck guard |
| 13 | Sync stuck at "syncing" | `SyncEngineService::syncPendingPatients` | API call failed after atomic claim. Recovery runs after 30 min. |
| 14 | Workspace data stale | `useWorkspace.js` (refreshWorkspaceData), merge logic | Production response overwrites local pending data |
| 15 | Category block empty | `CategoryRepository::all`, `EloquentCategoryRepository`, `config/categories.php` | API fail + empty cache + missing config defaults |
| 16 | Upload speed very slow | `useUploads.js` (POOL_SIZE, CHUNK_SIZE, normalRequestsPending) | Navigation blocking uploads, chunk too large, pool too small |
| 17 | UI not updating reactively | `useWorkspace.js` (workspaceData shallowRef), spread operator pattern | Missing `workspaceData.value = { ...workspaceData.value }` reassignment |
| 18 | Offline note not syncing | `SyncEngineService::syncPendingNotes`, `NoteController::store` | sync_status not set to 'pending_create', or patient not synced |
| 19 | Patient count wrong | `useWorkspace.js` (syncWorkspaceStats), stats calculation | Manual stat adjustment after add/remove |
| 20 | Search not returning results | `GlobalSearchController`, `PatientRepository::search` | API fallback to local SQLite search |
| 21 | Mobile layout broken | `DoctorWorkspace.vue` (isMobile, responsive classes) | Window width detection, CSS breakpoint |
| 22 | Export/print fails | `WorkspaceController::exportPatient`, `ExportPatientFilesJob` | Large file set, queue not running |
| 23 | Category files not loading | `CategoryFileController`, `CategoryFileService` | Lazy loading issue, slug mismatch |
| 24 | Share permission denied | `PatientPolicy`, `PatientShareController` | is_primary check, access_level |
| 25 | Video not playing | `FileAccessController::streamDirect` (Range headers), `VideoPlayer.vue` | Range request handling, mime type |
| 26 | Upload session orphaned | `ChunkUploadController::cancel`, `UploadSessionService` | Cancel not called, cleanup not running |
| 27 | SQLite schema mismatch | Migrations, `PatientRepository::doSyncSingleToLocal` (Schema::getColumnListing) | Production returns columns not in local schema |
| 28 | Backend 500 on patient create | `WorkspaceController::storePatient`, `PatientRepository::create` | Validation failure, UUID collision, disk full |
| 29 | Note content HTML stripped | `PatientNote` model, `NoteController::store` | Content sanitization, encoding issue |
| 30 | Timer/clock skew | `SyncEngineService` (updated_at comparisons), recovery logic | Device clock wrong, 30-min recovery threshold |
| 31 | Pull-to-refresh not working | `usePullToRefresh.js`, scroll container ref | Scroll container not bound, touch event handling |
| 32 | Dark mode not persisting | `useTheme.js` (localStorage), `AppLayout.vue` | Theme class not applied on mount |
| 33 | RTL layout broken | `DoctorWorkspace.vue` (dir="rtl"), `useLocale.js` | Direction attribute not set, CSS issues |
| 34 | Patient shared but not visible to other doctor | `DoctorIsolationScope`, `PatientShareController`, `PatientShare` model | Scope not including shared patients correctly |
| 35 | Doctor seeing other doctor's patients | `DoctorIsolationScope` | Scope bypass or missing filter |
| 6 | Attachment upload fails | `useUploads.js` (startUpload), `ChunkUploadController::init/chunk/complete`, `UploadSessionService` | Chunk init failure, merge failure, disk full |
| 36 | Dashboard not loading stats | `DashboardController::index`, `PatientRepository::stats` | Stats calculation failing, empty database |
| 37 | Settings save failing | `SettingsController::updateProfile/updatePassword`, `EloquentUserRepository` | Validation failure, avatar upload issue |
| 38 | Visit not saving | `VisitController::store`, `PatientVisitRepository` | Missing required fields, date format issue |
| 39 | Share expiry not working | `PatientShare.expires_at`, `DoctorIsolationScope` | Expiry check logic, timezone issue |
| 40 | Token auto-refresh failing | `SyncEngineService::refreshToken`, `session('auth_credentials')` | Stored credentials invalid, encryption mismatch |
| 41 | CSRF 419 errors offline | `routes/web.php` CSRF exclusions | Missing CSRF exemption on new route |
| 42 | Session lost after WebView restart | `routes/web.php` session/restore, `ApiService` constructor | Session driver issue, token not restored |
| 43 | Category cache stale | `CategoryRepository::refresh`, `EloquentCategoryRepository` | Cache not refreshed, API returning old data |
| 44 | File thumbnail not generating | `ProcessUploadedFileJob`, `PatientFileObserver` | Queue not running, video codec issue |
| 45 | Upload progress stuck at 0% | `useUploads.js` (onUploadProgress), `ChunkUploadController::chunk` | Progress callback not firing, chunk not accepted |
| 46 | Multiple tabs conflict | `useWorkspace.js` (module-level state), `useSyncEngine.js` | Shared state across tabs, concurrent sync |
| 47 | SQLite database locked | `SyncEngineService`, concurrent requests | Multiple processes writing to SQLite simultaneously |
| 48 | Memory leak on large uploads | `useUploads.js` (blob slicing), `uploadHttp` instance | Blobs not garbage collected, too many concurrent chunks |
| 49 | CORS errors on production API | `config/cors.php`, ApiService baseUrl | Wrong CORS configuration, missing headers |
| 50 | Inertia page reload losing state | `DoctorWorkspace.vue` (onMounted), `useWorkspace.js` | SSR props not hydrating, async race condition |
| 51 | Patient update not reflected in sidebar | `useWorkspace.js` (updatePatient), `refreshPatientList()` | updatePatient calls refreshPatientList but sidebar uses different sort |
| 52 | Offline patient UUID collision | `PatientRepository::create` (offline), UUID generation | UUID::uuid() should be unique but verify generation path |
| 53 | Sync engine token check bypass | `SyncEngineService::syncAll` (auth guard), `ApiService::getToken` | Token present but expired, guard passes but API fails |
| 54 | File upload memory exhaustion | `useUploads.js` (blob.slice), large files | 5MB chunks fine but 100+ in-flight could exhaust memory |
| 55 | Upload session expired on server | `UploadSessionService::findOrFail`, session TTL | Default TTL exceeded, chunks lost |
| 56 | Note sync creates duplicate on server | `SyncEngineService::syncPendingNotes`, `NoteController::store` | Note created via both mobile API and offline, same content |
| 57 | Patient code generation collision | `WorkspaceController::storePatient` (random_int) | 6-digit code with random_int(100000, 999999) — low collision risk but exists |
| 58 | Category file endpoint 404 | `CategoryFileController::files`, route parameter binding | patientUuid not found in local DB or API |
| 59 | Upload chunk checksum mismatch | `ChunkUploadService::storeChunk`, `UploadChecksumService` | Network corruption, client/server chunk size mismatch |
| 60 | Offline upload file deleted from disk | `OfflineUploadService::deleteLocal`, cleanup process | File deleted before sync completes, or manual cleanup |
| 61 | Workspace permissions not updating | `WorkspaceController::patientData` (permissions), `PatientPolicy` | Policy check using wrong user, cached permissions |
| 62 | Mobile bottom bar not showing | `DoctorWorkspace.vue` (v-if condition), `isMobile` | isMobile detection delayed, CSS hiding |
| 63 | Category rename not persisting | `CategoryController::update`, `CategoryRepository` | API update fails but local cache not refreshed |
| 64 | Patient export missing files | `WorkspaceController::exportPatient`, `PatientFileRepository::forPatient` | DoctorIsolationScope filtering out shared files |
| 65 | Download ZIP empty or incomplete | `ExportPatientFilesJob`, `WorkspaceController::downloadZip` | Job timeout, disk full, file access denied |
| 66 | Global search returning stale results | `GlobalSearchController::search`, Eloquent query | No caching but query hits SQLite directly |
| 67 | Settings preferences not applying | `SettingsController::updatePreferences`, `useTheme/useLocale` | LocalStorage not updated, Vue reactivity issue |
| 68 | Share modal showing wrong doctors | `PatientShareController::searchDoctors`, `User` query | DoctorIsolationScope affecting search, inactive doctors showing |
| 69 | Visit date display wrong | `PatientVisit.visit_date`, timezone handling | UTC vs local timezone mismatch |
| 70 | Profile avatar upload failing | `SettingsController::updateProfile`, Storage disk | Public disk not configured, file size limit |
| 71 | Category block expand/collapse broken | `useWorkspace.js` (toggleCategory, expandedCategories) | State not toggling, animation conflict |
| 72 | Inline file preview not opening | `useWorkspace.js` (openPreview), `InlineFilePreview.vue` | Preview state not set, file data missing |
| 73 | Video player not loading | `VideoPlayer.vue`, `FileAccessController::streamDirect` | CORS issue, Range header missing, mime type wrong |
| 74 | Toast notification not showing | `useToast.js`, `ToastContainer.vue` | Toast state not triggering reactivity, container not mounted |
| 75 | Dialog confirm not blocking | `useDialog.js`, `GlobalDialog.vue` | Promise not resolving, dialog state not toggling |
| 76 | Add record modal wrong category | `AddRecordModal.vue`, category slug passing | Slug mismatch, default category fallback |
| 77 | Patient summary stats wrong | `WorkspaceController::patientData` (stats), `syncWorkspaceStats` | Stats calculated from filtered data, not all files |
| 78 | Share access level not enforced | `PatientPolicy`, `WorkspaceController::patientData` (permissions) | access_level check missing, canEdit always true |
| 79 | Offline notes showing wrong author | `OfflineNoteController::store`, author fallback chain | primary_doctor_id null, author_id not set |
| 80 | Sync engine heartbeat firing too often | `useSyncEngine.js` (setInterval), 30s timer | Multiple intervals registered, singleton pattern broken |
| 81 | Token file corruption | `ApiService::loadTokenFromFile`, `writeTokenToFile` | Concurrent writes, disk error, decrypt fails |
| 82 | Session encrypt/decrypt mismatch | `ApiService::setToken`, Laravel encrypt() | APP_KEY changed, session driver issue |
| 83 | DoctorIsolationScope bypassed in admin | `Admin/DoctorController`, `withoutGlobalScope` | Admin correctly bypasses but verify non-admin paths |
| 84 | Patient notes not showing for shared patient | `DoctorIsolationScope` on `PatientNote`, `EloquentPatientNoteRepository` | Scope filtering notes by doctor, shared patients not included |
| 85 | File cache LRU evicting active files | `FileCacheRepository::ensureQuota`, 500MB limit | Heavy usage exceeding cache, active files evicted |
| 86 | Upload resume not working | `useUploads.js` (resume check), `ChunkUploadController::status` | Session expired, chunks lost, upload_id mismatch |
| 87 | Pending summary showing wrong count | `SyncEngineService::getPendingSummary`, query logic | Counting synced records, duplicate counting |
| 88 | Category block lazy loading failure | `useWorkspace.js` (isCategoryLoaded, markCategoryLoaded) | Load flag not set, data not fetched |
| 89 | Patient restore not undoing soft delete | `PatientRepository::restore`, Eloquent restore | sync_status not updated to pending_update |
| 90 | Force delete not removing from server | `PatientRepository::forceDelete`, `SyncEngineService::processPendingDeletes` | API delete fails, local forceDelete succeeds but server保留 |
| 91 | Upload speed calculation wrong | `useUploads.js` (makeSpeedTracker), sliding window | Window too short, speed fluctuating wildly |
| 92 | Concurrent patient creation duplicate | `useWorkspace.js` (addPatient), rapid double-click | Two API calls, two patients created |
| 93 | Category files pagination broken | `CategoryFileController::files`, offset/limit | Missing pagination parameters, all files returned |
| 94 | Workspace header not showing patient name | `WorkspaceHeader.vue`, `selectedPatient` computed | selectedPatient null, workspaceData not loaded |
| 95 | Mobile back button not working | `DoctorWorkspace.vue` (closePatient), history API | pushState/popstate not wired correctly |
| 96 | Category manager modal not saving | `CategoryManagerModal.vue`, `CategoryController::update` | API call failing, form validation error |
| 97 | Patient share notification not sent | `ShareService`, `ActivityLogger` | Activity log created but no notification system implemented |
| 98 | Upload file name garbled | `ChunkUploadController::init`, file_name parameter | Unicode characters in filename, encoding issue |
| 99 | Sync engine not recovering stuck records | `SyncEngineService::syncPendingPatients` (30 min threshold) | updated_at not being updated during claim, threshold too long |
| 100 | NativePHP bridge not responding | `useNativeBridge.js`, window.NativePHP | WebView bridge not initialized, Android configuration wrong |
| 101 | Category color not applying | `CategoryBlock.vue` (color prop), CSS variable | Inline style not generated, dark mode override |
| 102 | Patient search filtering too aggressively | `useWorkspace.js` (filteredPatients), searchQuery | Search includes deleted patients, case sensitivity |
| 103 | Upload cancel not cleaning up server | `useUploads.js` (cancelUpload), `ChunkUploadController::cancel` | Cancel endpoint not called, orphaned chunks on server |
| 104 | Offline file preview showing 404 | `FileAccessController::streamCached`, `FileCacheRepository::stream` | File not in cache, offline_files table not checked |
| 105 | Dashboard redirect loop | `DashboardController::index`, role check | Doctor role redirects to workspace, but workspace redirects back |
| 106 | Settings page Inertia render failing | `SettingsController::index`, `Inertia::render` | Missing component, props serialization error |
| 107 | Patient print page missing styles | `WorkspaceController::printPatient`, `PatientPrint.vue` | CSS not loaded, print stylesheet missing |
| 108 | Category icon not mapping | `DoctorWorkspace.vue` (getCategoryIcon), icon value | Icon string not in map, default fallback |
| 109 | Upload session localStorage full | `useUploads.js` (savePersisted), STORAGE_KEY | Too many pending uploads, localStorage quota exceeded |
| 110 | Sync engine skipping due to guard | `useSyncEngine.js` (onlineSyncGuard), concurrent triggers | Guard not cleared after error, permanently stuck |
| 111 | Patient file type detection wrong | `OfflineUploadController::store`, mime_type | Missing mime type from client, extension-based fallback |
| 112 | Category block search not clearing | `CategoryBlock.vue` (clearFilters), filter state | Date/time filters not resetting, search query persistent |
| 113 | Workspace modal z-index conflict | `WorkspaceModal.vue`, `BaseDialog.vue` | Multiple modals open, z-index stacking issue |
| 114 | Pull to refresh false trigger | `usePullToRefresh.js`, touch event threshold | Touch movement too small, threshold too low |
| 115 | Share expiry check timezone issue | `DoctorIsolationScope`, `now()` comparison | Server UTC vs local timezone, expired shares still visible |
| 116 | Patient notes count mismatch | `WorkspaceController::patientData` (stats), actual notes | Notes filtered by scope, count includes soft-deleted |
| 117 | Category block file count wrong | `CategoryBlock.vue` (mergedCategoryItems), pagination | Total count includes notes, file-only count missing |
| 118 | Upload chunk size mismatch | `useUploads.js` (CHUNK_SIZE), `ChunkUploadController::init` | Client 5MB, server accepts different size |
| 119 | Offline upload file hash mismatch | `OfflineUploadService::calculateHash`, streaming hash | Hash calculated before upload, file modified during save |
| 120 | Sync engine note upload fails silently | `SyncEngineService::syncPendingNotes`, error handling | Note sync failure logged but count not incremented correctly |

---

## 7. Feature Maps

### Feature: Patient Management

```
Files involved:
  DoctorWorkspace.vue, PatientListSidebar.vue, AddPatientModal.vue, EditPatientModal.vue,
  PatientSummary.vue, useWorkspace.js, WorkspaceController.php, PatientRepository.php,
  PatientController.php (mobile API), PatientPolicy.php

Execution order:
  1. User clicks "Add Patient" → AddPatientModal opens
  2. Form submit → useWorkspace.addPatient()
  3. POST to /api/v1/mobile/patients (online) or /api/v1/workspace/patients (offline)
  4. PatientRepository.create() → API-first, local fallback
  5. upsertPatient() updates UI immediately
  6. selectPatient() loads workspace

API endpoints:
  POST   /api/v1/mobile/patients          → create
  PUT    /api/v1/mobile/patients/{uuid}   → update
  DELETE /api/v1/workspace/patients/{uuid} → archive
  POST   /api/v1/workspace/patients/{uuid}/restore → restore
  DELETE /api/v1/workspace/patients/{uuid}/force   → permanent delete
  GET    /api/v1/workspace/patients-list   → paginated list
  GET    /api/v1/workspace/{uuid}          → full patient data
```

### Feature: File Upload

```
Files involved:
  CategoryBlock.vue, AddRecordModal.vue, useUploads.js, useOfflineUploads.js,
  ChunkUploadController.php, OfflineUploadController.php, OfflineUploadService.php,
  UploadSessionService.php, ChunkUploadService.php, ChunkMergeService.php

Online path:
  1. File input → handleFileUpload()
  2. navigator.onLine? → useUploads().uploadFile()
  3. POST /api/v1/chunk/init → session created
  4. POST /api/v1/chunk/chunk × N (parallel pool, 5MB each)
  5. POST /api/v1/chunk/complete → merge → PatientFile created
  6. addFileLocally() → UI update

Offline path:
  1. File input → handleFileUpload()
  2. navigator.onLine=false → useOfflineUploads().uploadFile()
  3. POST /_native/api/offline/uploads → saved to disk + SQLite
  4. addFileLocally() → UI update (sync_status = 'pending_upload')
  5. When online: SyncEngineService uploads to production
```

### Feature: Note Management

```
Files involved:
  DoctorWorkspace.vue (inline note form), CategoryBlock.vue (inline notes),
  AddRecordModal.vue, NoteController.php, OfflineNoteController.php,
  SyncEngineService.php (syncPendingNotes)

Flow:
  1. User types note → submitNoteForm()
  2. POST /api/v1/mobile/patients/{uuid}/notes
  3. NoteController creates in local SQLite
  4. addNoteLocally(note) → immediate UI update
  5. refreshWorkspaceData() → merge with server data
  6. Sync engine pushes to production in background
```

### Feature: Offline Sync

```
Files involved:
  useSyncEngine.js, SyncEngineService.php, ApiService.php,
  PatientRepository.php, OfflineFileRepository.php, OfflineUploadService.php

Sync order (mandatory):
  1. Patients (pending_create / pending_update)
  2. Files (pending_upload / failed) — only if patient is synced
  3. Notes (pending_create / pending_delete)
  4. Deletes (pending_delete)

Triggered by:
  - Network state change (5 detection paths)
  - Heartbeat (every 30s if pending operations exist)
  - Manual trigger from frontend
```

### Feature: Authentication

```
Files involved:
  AuthController.php, ApiService.php, LoginAction.php,
  routes/web.php (session/restore), bootstrap.js

Login lifecycle:
  1. POST /login → Auth::attempt() → session created
  2. ApiService::loginToRemote() → get production API token
  3. Token stored in: session, disk file, encrypted
  4. Encrypted credentials stored for auto-refresh

Session restore (app restart):
  1. Frontend POST /api/session/restore with api_token from localStorage
  2. Auto-login User::first() → session established
  3. ApiService::setToken() restores production token
```

---

## 8. Data Ownership

### Patient

| Action | Who | Where |
|--------|-----|-------|
| Creates | `PatientRepository::create()` | API (online) or local SQLite (offline) |
| Modifies | `PatientRepository::update()` | API-first, local fallback |
| Deletes | `PatientRepository::delete()` | Soft-delete locally + mark pending_delete |
| Syncs | `SyncEngineService::syncPendingPatients()` | Local → production |
| Displays | `DoctorWorkspace.vue`, `PatientListSidebar.vue` | `useWorkspace().patients` |
| Caches | `PatientRepository::doSyncSingleToLocal()` | Production → local SQLite |

### File (PatientFile)

| Action | Who | Where |
|--------|-----|-------|
| Creates (online) | `ChunkMergeService::merge()` → creates PatientFile | Production + local SQLite |
| Creates (offline) | `OfflineUploadController::store()` → offline_files table | Local SQLite only |
| Modifies | `FileAccessController::update()` | Production or local |
| Deletes | `FileAccessController::destroy()` | Production or local |
| Syncs | `SyncEngineService::syncPendingFiles()` | offline_files → production |
| Caches locally | `FileCacheRepository::cache()` | storage/app/cache/files/ |
| Displays | `CategoryBlock.vue` | `useWorkspace().allFiles` |

### Note (PatientNote)

| Action | Who | Where |
|--------|-----|-------|
| Creates (online) | `NoteController::store()` | Local SQLite (sync_status depends on user) |
| Creates (offline) | `OfflineNoteController::store()` | Local SQLite (sync_status = 'pending_create') |
| Modifies | `NoteController::update()` | Local SQLite + production |
| Deletes | `NoteController::destroy()` | Soft-delete locally |
| Syncs | `SyncEngineService::syncPendingNotes()` | Local → production |
| Displays | `CategoryBlock.vue`, `DoctorWorkspace.vue` | `useWorkspace().allNotes` |

### Visit (PatientVisit)

| Action | Who | Where |
|--------|-----|-------|
| Creates | `VisitController::store()` | Local SQLite |
| Syncs | Not implemented in SyncEngineService | — |
| Displays | `DoctorWorkspace.vue` | `useWorkspace().visits` |

### Category

| Action | Who | Where |
|--------|-----|-------|
| Creates/Edits | Production server (admin) | Remote API |
| Caches | `CategoryRepository::refresh()` | local SQLite (cached_categories) |
| Fallback | `config/categories.php` | Hardcoded defaults |
| Displays | `DoctorWorkspace.vue` | `useWorkspace().categories` |

---

## 9. State Management Map

### Where State Lives

```
Module-level reactive state (shared singletons):
  useWorkspace.js:
    patients, patientsMeta, archivedPatients, selectedPatientId,
    workspaceData (shallowRef!), loading, searchQuery, sidebarOpen,
    expandedCategories, previewFile, showPreview, isMobile, etc.

  useSyncEngine.js:
    isOnline, isSyncing, lastSyncResult, pendingSummary

  useUploads.js:
    uploads (ref([]))

  useOfflineUploads.js:
    offlineUploads (ref([]))

  useDialog.js:
    state (reactive)

  useToast.js:
    toasts (ref([]))
```

### State Flow

```
┌──────────────────────────────────────────────────────────┐
│                    useWorkspace (Hub)                      │
│                                                            │
│  patients.value ◄── refreshPatientList()                   │
│                    ◄── upsertPatient()                     │
│                    ◄── addPatient()                        │
│                                                            │
│  workspaceData.value ◄── selectPatient()                  │
│                       ◄── refreshWorkspaceData()           │
│                       ◄── addFileLocally()                 │
│                       ◄── addNoteLocally()                 │
│                       ◄── updateFileLocally()              │
│                       ◄── removeFileLocally()              │
│                                                            │
│  selectedPatientId ◄── selectPatient()                     │
│                    ◄── closePatient()                      │
│                                                            │
│  filteredPatients (computed) ◄── patients + searchQuery    │
│  selectedPatient (computed)   ◄── patients + selectedId    │
│  categories (computed)        ◄── workspaceData.categories │
│  allFiles (computed)          ◄── workspaceData.files      │
│  allNotes (computed)          ◄── workspaceData.notes      │
└──────────────────────────────────────────────────────────┘
```

### Who Can Accidentally Break State

| Risk | File | Issue |
|------|------|-------|
| Losing workspaceData | `refreshWorkspaceData()` | If merge logic drops local pending items |
| Disappearing patients | `refreshPatientList()` | If safety-net logic fails |
| Stale file list | `useUploads.js` | If addFileLocally not called after upload |
| Duplicate notes | `refreshWorkspaceData()` | If pending note filtering misses UUID match |
| Broken reactivity | `useWorkspace.js` | If workspaceData.value is mutated without spread reassignment |
| Token state loss | `ApiService.php` | If token not persisted to all 3 storage locations |

---

## 10. API Map

### Workspace API (Primary)

| Endpoint | Controller | Method | Purpose |
|----------|-----------|--------|---------|
| `GET /api/v1/workspace/patients-list` | WorkspaceController | patientList | Paginated patient list |
| `GET /api/v1/workspace/{uuid}` | WorkspaceController | patientData | Full patient data payload |
| `POST /api/v1/workspace/patients` | WorkspaceController | storePatient | Create patient |
| `PUT /api/v1/workspace/patients/{uuid}` | WorkspaceController | updatePatient | Update patient |
| `DELETE /api/v1/workspace/patients/{uuid}` | WorkspaceController | deletePatient | Archive patient |
| `DELETE /api/v1/workspace/patients/{uuid}/force` | WorkspaceController | forceDeletePatient | Permanent delete |
| `POST /api/v1/workspace/patients/{uuid}/restore` | WorkspaceController | restorePatient | Restore archived |
| `GET /api/v1/workspace/{uuid}/export` | WorkspaceController | exportPatient | JSON export |
| `GET /api/v1/workspace/{uuid}/print` | WorkspaceController | printPatient | Print view |
| `POST /api/v1/workspace/{uuid}/download-files` | WorkspaceController | downloadFiles | ZIP download (async job) |

### Upload API

| Endpoint | Controller | Purpose |
|----------|-----------|---------|
| `POST /api/v1/chunk/init` | ChunkUploadController | Initialize upload session |
| `POST /api/v1/chunk/chunk` | ChunkUploadController | Upload single chunk |
| `POST /api/v1/chunk/complete` | ChunkUploadController | Merge chunks → PatientFile |
| `POST /api/v1/chunk/{uuid}/cancel` | ChunkUploadController | Cancel upload |
| `GET /api/v1/chunk/{uuid}/status` | ChunkUploadController | Check upload status |

### Mobile API (Production server auth)

| Endpoint | Controller | Purpose |
|----------|-----------|---------|
| `POST /api/v1/mobile/patients` | Mobile/PatientController | Create patient on server |
| `PUT /api/v1/mobile/patients/{uuid}` | Mobile/PatientController | Update patient on server |
| `GET /api/v1/mobile/patients/{uuid}/notes` | Mobile/NoteController | List notes |
| `POST /api/v1/mobile/patients/{uuid}/notes` | Mobile/NoteController | Create note |
| `POST /api/v1/mobile/patients/{uuid}/files` | Mobile/FileController | Upload file to server |

### Native/Sync API (Embedded Laravel, CSRF-excluded)

| Endpoint | Handler | Purpose |
|----------|---------|---------|
| `POST /_native/api/sync/engine` | SyncEngineService::syncAll() | Full sync cycle |
| `POST /_native/api/sync/patients` | PatientRepository::syncPending() | Sync pending patients |
| `GET /_native/api/sync/pending-summary` | SyncEngineService | Pending ops count |
| `POST /_native/api/offline/uploads` | OfflineUploadController | Save file offline |
| `GET /_native/api/offline/uploads` | OfflineUploadController | List pending files |
| `POST /_native/api/offline/notes` | OfflineNoteController | Create note offline |
| `POST /_native/api/categories/refresh` | CategoryRepository | Refresh local cache |
| `GET /_native/cache/files/{uuid}` | FileAccessController | Stream cached file |

### File Access API

| Endpoint | Controller | Purpose |
|----------|-----------|---------|
| `GET /api/v1/files/{uuid}` | FileAccessController | Stream file (direct or cached) |
| `GET /api/v1/files/{uuid}/thumbnail` | FileAccessController | Get thumbnail |
| `DELETE /api/v1/files/{uuid}` | FileAccessController | Delete file |
| `PUT /api/v1/files/{uuid}` | FileAccessController | Update file metadata |

---

## 11. Offline System

### SQLite Schema (Key Tables)

```sql
-- Core tables (dual-deploy: MySQL on server, SQLite on phone)
patients          -- sync_status: synced|pending_create|pending_update|pending_delete|syncing
patient_files     -- upload_status, url, thumbnail_url
patient_notes     -- sync_status: synced|pending_create|pending_delete
patient_visits    -- (no sync status yet)
patient_shares    -- doctor access control

-- Phase 6: Offline File Cache
file_cache        -- file_uuid, patient_uuid, local_path, mime_type, size, last_accessed_at

-- Phase 7: Offline File Uploads
offline_files     -- uuid, patient_uuid, local_path, original_name, mime_type, size, hash,
                     sync_status: pending_upload|uploading|synced|failed, retry_count

-- Phase 8: Category Cache
cached_categories -- user_id, slug, name, icon, color

-- Sync Infrastructure
sync_meta         -- key-value sync state
pending_operations -- (legacy table, may be unused)
upload_sessions   -- chunked upload sessions
```

### What Happens When Internet Disappears

1. `useSyncEngine` sets `isOnline.value = false`
2. All writes go to local SQLite (pending_* status)
3. File uploads save to `storage/app/uploads/pending/`
4. Frontend shows sync status badges on pending items
5. User can continue working normally

### What Happens When Internet Returns

1. Any of 5 detection paths fires → `attemptSync()`
2. `triggerSync()` → POST `/_native/api/sync/engine`
3. `SyncEngineService::syncAll()` runs in order:
   - Patients first (files reference patient UUIDs on server)
   - Files second (only if patient is synced)
   - Notes third
   - Deletes last
4. After sync, frontend refreshes patient list
5. Pending items get `sync_status = 'synced'`

### Conflict Resolution

**Strategy: Last-write-wins with local priority for pending items**
- Remote data is always considered authoritative for synced items
- Local pending items are merged into API responses during refresh
- No merge conflict UI — the production server is the single source of truth
- If local and remote differ for the same UUID, the API response wins

---

## 12. Upload System

### Online Upload Pipeline

```
1. Image selection → <input type="file"> or useNativeBridge.pickFiles()
     ↓
2. Route decision → navigator.onLine?
     ├── ONLINE → useUploads().uploadFile()
     └── OFFLINE → useOfflineUploads().uploadFile()
     ↓
3. Chunking → File.slice(start, end) → 5MB chunks
     ↓
4. Parallel pool → 4 concurrent chunks (global semaphore)
     ↓
5. Normal request priority → uploads PAUSE during navigation
     ↓
6. POST /api/v1/chunk/chunk (FormData with chunk blob)
     ↓
7. Retry → exponential backoff 500ms → 1s → 2s (cap 4s, max 3 retries)
     ↓
8. Complete → POST /api/v1/chunk/complete
     ↓
9. Merge → ChunkMergeService::merge() → create PatientFile
     ↓
10. Response → addFileLocally() → UI shows file immediately
```

### Offline Upload Pipeline

```
1. File selection (strictly offline)
     ↓
2. POST /_native/api/offline/uploads (multipart/form-data)
     ↓
3. OfflineUploadController::store()
     ↓
4. OfflineUploadService::saveLocally()
     ├── Generate UUID
     ├── Store in storage/app/uploads/pending/{uuid}.{ext}
     └── Calculate SHA-256 hash (streaming)
     ↓
5. OfflineFileRepository::create() → SQLite (sync_status = 'pending_upload')
     ↓
6. Return metadata → addFileLocally() → UI shows pending file
     ↓
7. Later, when online: SyncEngineService::syncPendingFiles()
     ├── Atomically claim: status → 'uploading'
     ├── Check patient is synced
     ├── ApiService::upload() → POST to production
     ├── markSynced() → status = 'synced'
     └── deleteLocal() → remove from disk
```

---

## 13. Authentication Flow

### Login Lifecycle

```
1. GET /login → AuthController::showLogin() → Inertia render Auth/Login.vue
2. User submits email + password
3. POST /login → AuthController::login()
     ├── Auth::attempt($credentials) → establish web session
     ├── session()->regenerate()
     ├── ApiService::loginToRemote(email, password) → POST to production /login
     │     └── Returns Sanctum Bearer token
     ├── ApiService::setToken($token) → stores in:
     │     ├── $this->token (in-memory)
     │     ├── session('api_token') → encrypted
     │     └── storage/app/.api_sync_token → encrypted
     ├── session(['auth_credentials' => encrypt(json_encode({email, password}))])
     └── Redirect based on role
```

### Token Lifecycle

```
Creation:
  LoginAction::execute() → $user->createToken('auth_token')
  OR
  ApiService::loginToRemote() → production server returns token

Storage (3 locations):
  1. $this->token (ApiService in-memory, per-request)
  2. session('api_token') → encrypt() → SQLite session table
  3. storage/app/.api_sync_token → encrypt() → disk file

Restore (app restart):
  1. Frontend sends api_token from localStorage via POST /api/session/restore
  2. ApiService constructor: try session → try file → empty
  3. Session/restore endpoint: ApiService::setToken()

Refresh (on 401):
  SyncEngineService::refreshToken()
    ├── Read session('auth_credentials') → decrypt → email + password
    ├── ApiService::loginToRemote(email, password) → new token
    └── ApiService::setToken(new_token)
```

### Authorization

```
Embedded Laravel (SQLite):
  - No auth middleware on _native/* routes (CSRF excluded too)
  - Controller-level authorization via Gate::authorize('update', $patient)
  - Falls back gracefully if no authenticated user (single-device)

Production Server (MySQL):
  - auth:sanctum middleware on /api/v1/mobile/* routes
  - Bearer token from ApiService
  - PatientPolicy for fine-grained access control
```

---

## 14. Event Flow

### Button Click → Patient Created

```
AddPatientModal.vue
  │ @submit.prevent
  ▼
submitForm()
  │ axios.post(url, formData, headers)
  ▼
WorkspaceController::storePatient() [or Mobile/PatientController::store()]
  │
  ├─ Captures Bearer token (SQLite mode)
  ├─ Validates request
  ├─ PatientRepository::create()
  │     ├─ [online]  → api->create() → syncSingleToLocal()
  │     └─ [offline] → local->create() with sync_status='pending_create'
  ▼
Response → { patient: { uuid, name, ... } }
  │
  ▼
useWorkspace.addPatient()
  │
  ├─ upsertPatient(patient) → patients.value = [patient, ...]
  ├─ selectedPatientId.value = patient.uuid
  └─ workspaceData.value = { patient, files: [], notes: [], ... }
  │
  ▼
Reactive UI update:
  PatientListSidebar → shows new patient
  DoctorWorkspace → shows patient workspace
  PatientSummary → shows patient details
```

### File Upload → UI Update

```
CategoryBlock.vue handleFileUpload(file)
  │
  ├─ [online]  → useUploads().uploadFile()
  │     ├─ createJob() → uploads.value.push(job)
  │     ├─ startUpload() → chunked upload
  │     │     ├─ addFileLocally({ uuid, status: 'uploading' })  ← shows progress
  │     │     └─ addFileLocally({ uuid, url, status: 'ready' }) ← shows complete
  │     └─ Reactive: CategoryBlock shows upload progress bars
  │
  └─ [offline] → useOfflineUploads().uploadFile()
        ├─ POST /_native/api/offline/uploads
        ├─ addFileLocally({ uuid, sync_status: 'pending_upload' })
        └─ Reactive: CategoryBlock shows file with "pending upload" badge
```

---

## 15. Circular Dependencies & Architecture Smells

### Circular Dependencies

| Pattern | Files | Risk |
|---------|-------|------|
| useWorkspace ↔ useUploads | useUploads calls addFileLocally; useWorkspace is imported by useUploads | Module-level imports prevent runtime cycles, but tight coupling |
| useWorkspace ↔ useSyncEngine | useSyncEngine calls refreshPatientList; useWorkspace imported | Same — works due to singleton pattern |
| PatientRepository ↔ SyncEngineService | SyncEngine uses PatientRepository::createOnRemote | Clean — one-directional |

### Tight Coupling

| Issue | Details |
|-------|---------|
| useWorkspace is a God Module | 40+ exports, 600+ lines. Owns ALL state. Every component depends on it. |
| workspaceData shallowRef | Reactivity requires manual `workspaceData.value = { ...workspaceData.value }` spread pattern |
| Module-level state | Composables use module-level refs (singleton). Multiple `useWorkspace()` calls share state — this is intentional but fragile. |
| Trace functions in production | `trace()` calls in useWorkspace.js and useOfflineUploads.js hit `/_native/api/debug/trace` on every call |

### Duplicated Logic

| Pattern | Where |
|---------|-------|
| Bearer token attachment | useWorkspace.addPatient(), useWorkspace.updatePatient(), Utils/api.js getApiConfig() |
| Online/offline branching | useWorkspace (addPatient, updatePatient), CategoryBlock (handleFileUpload), AddRecordModal |
| Patient resolution | NoteController::resolvePatient(), OfflineNoteController::resolvePatient(), ChunkUploadController::resolvePatient() — 3 copies |

### Dead Code / Unused

| Item | Location |
|------|----------|
| `pending_operations` table | Migration exists but appears unused by SyncEngineService |
| `ExportPatientFilesJob` | Referenced but queue may not be running on embedded |
| `Mobile/PatientController::show()` | May not be called by frontend |

---

## 16. Risk Zones

### Files That Can Break Many Things

| File | Risk Level | Why |
|------|-----------|-----|
| `useWorkspace.js` | 🔴 Extreme | Core state hub. Every component depends on it. Wrong merge logic = data loss. |
| `SyncEngineService.php` | 🔴 Extreme | Ordered sync. Wrong order = broken references. Atomic claim bugs = duplicate uploads. |
| `ApiService.php` | 🔴 Extreme | Token management. Wrong handling = permanent 401 loop. Singleton = all requests affected. |
| `routes/web.php` | 🟡 High | CSRF exemptions, auth middleware, route ordering. Wrong config = broken offline or auth. |
| `PatientRepository.php` | 🟡 High | Orchestrator. Wrong fallback = data inconsistency between API and local. |
| `RepositoryServiceProvider.php` | 🟡 High | Wrong binding = wrong implementation used everywhere. |
| `config/database.php` | 🟡 High | Wrong default = wrong mode (SQLite vs MySQL) for all queries. |
| `CategoryBlock.vue` | 🟠 Medium | 1000+ lines. Upload routing, note creation, file management all in one component. |

### Dangerous Operations

| Operation | Risk |
|-----------|------|
| Modifying refreshPatientList() merge logic | Can cause patients to disappear or duplicate |
| Modifying refreshWorkspaceData() merge logic | Can cause notes/files to disappear |
| Changing SyncEngineService sync order | Can cause files to upload before patient exists |
| Modifying ApiService token handling | Can break all production API communication |
| Changing RepositoryServiceProvider bindings | Can cause wrong database to be used |
| Modifying _native route middleware | Can break offline functionality |

---

## 17. Debugging Guide

### Patient Issues

```
Patient not showing:
  1. useWorkspace.js → refreshPatientList() → STEP 4 merge logic
  2. PatientRepository::paginated() → API vs local fallback
  3. Patient model → DoctorIsolationScope (may filter by doctor)
  4. patients.value assignment → check if safety-net preserves it

Patient created but disappears:
  1. useWorkspace.js → addPatient() → upsertPatient() called?
  2. refreshPatientList() → allPatientsBackup safety net
  3. SyncEngine → patient synced? UUID changed during sync?
  4. Console logs: [DIAG] addPatient, [INSTRUMENT] refreshPatientList

Patient sync stuck:
  1. SyncEngineService::syncPendingPatients() → atomic claim
  2. ApiService::getToken() → token present?
  3. Logs: [SyncEngine] token/auth related
  4. Check sync_status = 'syncing' stuck for >30 min
```

### Note Issues

```
Note not appearing:
  1. DoctorWorkspace.vue → submitNoteForm() → addNoteLocally() called?
  2. NoteController::store() → sync_status set correctly?
  3. refreshWorkspaceData() → merge logic preserves local pending notes?
  4. Check: note UUID not in server response AND sync_status = 'pending_create'

Note duplicated:
  1. refreshWorkspaceData() merge → serverNoteUuids.has(n.uuid) filter
  2. SyncEngineService::syncPendingNotes() → remote UUID assigned?
  3. Old local UUID vs new remote UUID not matched during merge
```

### Upload Issues

```
Upload fails:
  1. useUploads.js → startUpload() → init request
  2. ChunkUploadController::init() → session created?
  3. ChunkUploadController::chunk() → chunk stored?
  4. ChunkUploadController::complete() → merge successful?
  5. UploadSessionService, ChunkUploadService, ChunkMergeService
  6. Disk space, permissions

Offline upload not syncing:
  1. SyncEngineService::syncPendingFiles() → patient synced check
  2. ApiService::getToken() → token valid?
  3. offline_files sync_status → 'pending_upload' or 'failed'?
  4. File exists on disk? local_path correct?
```

### Sync Issues

```
Sync not running:
  1. useSyncEngine.js → onlineSyncGuard stuck? (should reset in finally)
  2. isOnline.value correct?
  3. Any of 5 detection paths firing?
  4. Heartbeat interval running? (30s)
  5. Console: [SyncEngine] initialization logs

Sync failing:
  1. SyncEngineService::syncAll() → auth guard (token check)
  2. ApiService::send() → 401 response → token cleared?
  3. SyncEngineService::refreshToken() → stored credentials?
  4. Network error vs auth error
```

### Authentication Issues

```
401 loop:
  1. ApiService token → present in all 3 storage locations?
  2. Session expired? Cookie valid?
  3. Token cleared on previous 401? (ApiService no longer clears on 401)
  4. SyncEngine trying to sync without token → skips gracefully

Token not persisting:
  1. ApiService::setToken() → session + file both written?
  2. Frontend localStorage['np_api_token'] → sent via session/restore?
  3. Constructor restoration: session → file → empty
```

### Vue UI Issues

```
UI not updating:
  1. workspaceData.value reassigned with spread? (shallowRef)
  2. Computed property dependencies correct?
  3. Module-level state shared correctly across components?

Component not rendering:
  1. Props received correctly?
  2. v-if/v-show conditions met?
  3. isMobile responsive breakpoint correct?
```

---

## 18. Knowledge Graph

### Patient Entity

```
Patient
├── Models
│   ├── Patient.php (Eloquent, SoftDeletes, DoctorIsolationScope)
│   └── PatientNote.php, PatientVisit.php, PatientShare.php
├── Backend
│   ├── PatientRepository.php (orchestrator)
│   ├── ApiPatientRepository.php (production API)
│   ├── EloquentPatientRepository.php (SQLite)
│   ├── WorkspaceController.php (primary controller)
│   ├── PatientController.php (resource controller)
│   ├── Mobile/PatientController.php (API endpoint)
│   ├── PatientPolicy.php (authorization)
│   └── SyncEngineService.php (sync to production)
├── Frontend
│   ├── useWorkspace.js (state: patients, selectedPatient, workspaceData)
│   ├── PatientListSidebar.vue (patient list + search)
│   ├── PatientSummary.vue (patient header)
│   ├── AddPatientModal.vue (create form)
│   ├── EditPatientModal.vue (edit form)
│   └── DoctorWorkspace.vue (orchestrator page)
├── Database
│   ├── patients table (uuid, sync_status, primary_doctor_id, ...)
│   ├── offline_files table (references patient_uuid)
│   └── Migrations: create_patients_table, add_sync_status_to_patients
└── Sync
    ├── SyncEngineService::syncPendingPatients()
    ├── PatientRepository::syncPending()
    └── useSyncEngine.js (triggers sync)
```

### File Entity

```
PatientFile
├── Models
│   └── PatientFile.php (Eloquent, UUID, upload_status)
├── Backend
│   ├── EloquentPatientFileRepository.php
│   ├── ApiPatientFileRepository.php
│   ├── ChunkUploadController.php (chunked upload)
│   ├── UploadController.php (direct upload)
│   ├── OfflineUploadController.php (offline save)
│   ├── FileAccessController.php (stream/thumbnail/delete)
│   ├── ChunkMergeService.php (merge chunks → file)
│   ├── OfflineUploadService.php (disk operations)
│   └── FileCacheRepository.php (offline viewing cache)
├── Frontend
│   ├── useUploads.js (online upload pipeline)
│   ├── useOfflineUploads.js (offline save pipeline)
│   ├── CategoryBlock.vue (file display + upload trigger)
│   ├── AddRecordModal.vue (upload form)
│   ├── InlineFilePreview.vue (preview)
│   ├── UnifiedMediaViewer.vue (media display)
│   ├── VideoPlayer.vue (video playback)
│   └── FileActions.vue (delete/rename)
├── Database
│   ├── patient_files table (uuid, url, thumbnail_url, upload_status)
│   ├── offline_files table (pending uploads)
│   ├── file_cache table (cached files for offline)
│   └── upload_sessions table (chunked upload sessions)
└── Sync
    ├── SyncEngineService::syncPendingFiles()
    ├── useSyncEngine.js (triggers)
    └── ApiService::upload() (sends to production)
```

### Note Entity

```
PatientNote
├── Models
│   └── PatientNote.php (Eloquent, UUID, sync_status, author)
├── Backend
│   ├── EloquentPatientNoteRepository.php
│   ├── NoteController.php (CRUD)
│   ├── OfflineNoteController.php (offline creation)
│   └── SyncEngineService::syncPendingNotes()
├── Frontend
│   ├── useWorkspace.js (addNoteLocally, allNotes)
│   ├── DoctorWorkspace.vue (inline note form)
│   ├── CategoryBlock.vue (note display)
│   └── AddRecordModal.vue (note creation)
├── Database
│   ├── patient_notes table (uuid, author_id, content, sync_status)
│   └── Migrations: add_sync_status_to_patient_notes
└── Sync
    ├── SyncEngineService::syncPendingNotes()
    └── POST /patients/{uuid}/notes via ApiService
```

### Category Entity

```
Category
├── Backend
│   ├── CategoryRepository.php (orchestrator)
│   ├── ApiCategoryRepository.php (production API)
│   ├── EloquentCategoryRepository.php (SQLite cache)
│   ├── CategoryController.php (CRUD)
│   └── CategoryFileController.php (files by category)
├── Frontend
│   ├── useWorkspace.js (categories, refreshCategoryCache)
│   ├── DoctorWorkspace.vue (renders CategoryBlocks)
│   ├── CategoryBlock.vue (file/note grid)
│   └── CategoryManagerModal.vue (add/edit/delete)
├── Database
│   └── cached_categories table (local cache)
└── Config
    └── config/categories.php (default fallback)
```

### Sync Entity

```
Sync
├── Backend
│   ├── SyncEngineService.php (ordered sync: patients→files→notes→deletes)
│   ├── ApiService.php (HTTP client, token management)
│   ├── PatientRepository.php (syncPending, syncSingleToLocal)
│   ├── OfflineFileRepository.php (pending upload tracking)
│   └── OfflineUploadService.php (disk operations)
├── Frontend
│   ├── useSyncEngine.js (5 detection paths, trigger, heartbeat)
│   ├── useWorkspace.js (refreshPatientList with merge)
│   └── AppLayout.vue (sync status display)
├── Database
│   ├── patients.sync_status (synced|pending_create|pending_update|pending_delete|syncing)
│   ├── patient_notes.sync_status (synced|pending_create|pending_delete)
│   ├── offline_files.sync_status (pending_upload|uploading|synced|failed)
│   └── sync_meta (key-value state)
├── Routes
│   ├── POST /_native/api/sync/engine (full sync)
│   ├── POST /_native/api/sync/patients (patient sync)
│   ├── GET /_native/api/sync/pending-summary (status)
│   └── POST /_native/api/sync/all (frontend trigger)
└── Detection Paths
    ├── 1. Browser online/offline events
    ├── 2. Network Information API
    ├── 3. Visibility/focus changes
    ├── 4. Native Android bridge callbacks
    └── 5. 30-second heartbeat
```

---

## 19. Architecture Summary

### For a Senior Engineer (10-Minute Overview)

**Medical Plus** is a medical record management system built with **Laravel 11 + Vue 3** that runs in two modes:

1. **Production server** (MySQL) — traditional web app for doctors
2. **Embedded on Android** (SQLite via NativePHP) — offline-first mobile app

The **core architectural challenge** is keeping the embedded SQLite database in sync with the production MySQL database. This is solved by:

- **Repository Pattern** — Each entity (Patient, Category) has an "orchestrator" repository that tries the production API first, then falls back to local SQLite. Writes attempt the API, and on failure, save locally with a `sync_status` flag.

- **SyncEngineService** — A robust ordered synchronization engine that runs when connectivity returns. It syncs in strict order: patients first (because files reference patient UUIDs), then files, then notes, then deletes. It uses atomic status transitions (`pending → syncing → synced`) to prevent duplicate uploads across concurrent processes.

- **Dual Upload Paths** — Online uploads use a chunked upload system (5MB chunks, 4 parallel slots, resume support). Offline uploads save files to disk and metadata to SQLite, then sync when online.

- **5-Path Network Detection** — The sync engine detects connectivity via browser events, Network Information API, visibility changes, native Android bridge callbacks, and a 30-second heartbeat.

The **frontend state** is managed entirely through module-level reactive composables (no Vuex/Pinia). `useWorkspace.js` is the central hub owning all patient/note/file state. Multiple components call the same composable to get shared singleton state.

**Key architectural decisions:**
- SQLite is the local source of truth on the phone
- The production API is the remote source of truth
- The orchestrator pattern provides seamless online/offline transitions
- Token management uses 3 storage locations (session, file, localStorage) for resilience across app restarts
- All `_native/*` routes are CSRF-excluded because the embedded Laravel runs without valid CSRF tokens when offline

**If you're debugging**, start with the Problem Locator table (Section 6). The most common issues are:
1. Token problems (ApiService token not persisted/restored)
2. Sync order violations (files syncing before patient exists)
3. Merge logic in refreshWorkspaceData() losing local pending items
4. workspaceData.value not being reassigned with spread pattern (reactivity break)
