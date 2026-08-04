# PROJECT_MEMORY.md — Medical Plus (Medical_Plus_v4)

> **Audience: AI assistants only.** This file replaces re-reading the repository. Written for machine consumption: dense, file:line-referenced, no narrative filler.
> **Status:** Built from one full repository pass on 2026-08-05. Treat as accurate as of that date; if code contradicts this file, trust the code and then fix this file (see RULES at the end).
> **Companion file:** `.ai/WORKLOG.md` — chronological change history. Read both before touching code.

---

## 1. High-Level Architecture

Medical Plus is **one Laravel codebase that ships as two runtimes**:

1. **Web app** (server, MySQL, always online) — doctors/admins use it in a browser via Inertia.js + Vue 3.
2. **Native mobile app** (NativePHP/Android, SQLite, offline-first) — the *same* Laravel app, packaged into an Android APK via NativePHP, running its own embedded PHP server and local SQLite DB on-device.

The single codebase detects which mode it's in via **`config('database.default') === 'sqlite'`** — this one check is the master switch for offline-mode behavior across service providers, routes, and controllers. There is no separate mobile codebase; mobile-specific routes exist under `_native/*` and `v1/mobile/*` prefixes but share controllers/services where possible.

Data flows: Web UI and Mobile UI both talk to the same domain layer (`app/Domains/*`), but mobile writes go to local SQLite first and are queued in a `sync_queue` table to later reconcile with the MySQL server via HTTP API calls (mobile acts as an API *client* to the server's `v1/mobile/*` API when it needs to push/pull).

## 2. Technology Stack

- PHP `^8.3` (composer.json:8), Laravel `^13.8`
- Inertia.js: `inertiajs/inertia-laravel ^3.1` (server) + `@inertiajs/vue3 ^3.5` (client) — no separate SPA API, single Blade entry (`resources/views/app.blade.php`)
- Auth: `laravel/sanctum ^4.0`; Permissions: `spatie/laravel-permission ^8.1`
- Native packaging: `nativephp/mobile ^3.3` + plugins `mobile-camera`, `mobile-dialog`, `mobile-file`, `mobile-network`, `mobile-share`
- Frontend: Vue `^3.5.39`, Vite `^8`, Tailwind CSS `^4` (via `@tailwindcss/vite`), `vue-i18n ^11.4.6` (en/ar locales), `video.js`, `cropperjs`/`viewerjs`
- **No TypeScript. No Pinia/Vuex** — state lives in Vue Composables (`resources/js/Composables/*`).
- Test framework: PHPUnit `^12.5` (Pest not used).

## 3. Folder Structure Map

```
app/
  Auth/            custom auth (ApiUserProvider — mobile/API guard user provider)
  Console/         Kernel.php + Commands/ (PurgeExpiredUploads, OptimizeVideosCommand)
  Contracts/       Repository interfaces (contract-first pattern; see RepositoryServiceProvider)
  Domains/         DDD-style modules — see §4
  Http/            Controllers/, Middleware/
  Jobs/            ExportPatientFilesJob, ProcessUploadedFileJob
  Models/           CachedCategory (only stray top-level model; everything else lives under Domains)
  Observers/       PatientFileObserver
  Policies/        PatientPolicy
  Providers/       AppServiceProvider, NativeServiceProvider, RepositoryServiceProvider
  Repositories/    offline-first wrapper repos + Eloquent/ concrete impls + Api/Traits/DebugLogsHttp
  Services/        Sync/, Upload/, Mobile/, plus ManualSyncService, SyncEngineService, OfflineUploadService
config/            standard Laravel + custom: nativephp.php, categories.php
database/          migrations/ (37 files), seeders/ (1), factories/ (1)
docs/              OFFLINE_FIRST_MASTER_PLAN.md — the sprint roadmap, READ THIS TOO
nativephp/         android/ (full Gradle project, generated), binaries/, resources/
resources/
  js/              Pages/, Components/, Layouts/, Composables/, Plugins/, Utils/, Locales/
  css/, views/
routes/            web.php (534 lines), api.php (126 lines), console.php
tests/             Feature/, Unit/ (PHPUnit)
```

## 4. Laravel Architecture (Domain Layout)

`app/Domains/{ActivityLogs,Auth,Media,Mobile,Patients,Sync,Users}` — each domain has its own `Models/` and `Services/` (Media also `Jobs/`, `Resources/`; Auth also `Actions/`, `Scopes/`). This sits *alongside*, not instead of, classic Laravel `app/Http/Controllers` and `app/Repositories`. Controllers call domain Services; Services call Repositories (via bound interfaces) or Eloquent Models directly.

**Binding source of truth:** `app/Providers/RepositoryServiceProvider.php` — binds every `*RepositoryInterface` to its live implementation:
- `PatientRepositoryInterface` → `App\Repositories\PatientRepository` (offline-first wrapper)
- `UserRepositoryInterface` → `Eloquent\EloquentUserRepository`
- `PatientFileRepositoryInterface` → `Eloquent\EloquentPatientFileRepository`
- `PatientNoteRepositoryInterface` → `Eloquent\EloquentPatientNoteRepository`
- `PatientVisitRepositoryInterface` → `Eloquent\EloquentPatientVisitRepository`
- `FileCacheRepositoryInterface` → `FileCacheRepository`
- `OfflineFileRepositoryInterface` → `OfflineFileRepository`
- `CategoryRepositoryInterface` → `CategoryRepository`

**Important:** `app/Services/Mobile/PatientRepository.php`, `VisitRepository.php`, `NoteRepository.php` are **dead code** — same class-name pattern as the real repos above but in a different namespace, never bound, never imported anywhere. Do not confuse them with the real ones. Do not "helpfully" wire them in without re-auditing — they have zero error handling and no offline fallback (see Known Bugs).

## 5. Vue / Frontend Architecture

Inertia.js, not a decoupled SPA — `createInertiaApp` in `resources/js/app.js`, single Blade shell `resources/views/app.blade.php` with `@inertia`.

- `Pages/` — Inertia page components: `Auth/`, `Admin/(Doctors/)`, `Dashboard/`, `Settings/(Partials/)`
- `Components/` — shared UI incl. `workspace/`, `admin/` subfolders
- `Layouts/` — e.g. `AppLayout.vue`
- `Composables/` (this is where "frontend architecture" lives, no store library): `useDialog`, `useLocale`, `useNativeBridge`, `useOfflineUploads`, `usePullToRefresh`, `useSyncEngine`, `useTheme`, `useToast`, `useUploadDiagnostics`, `useUploads`, `useWorkspace`
- `Plugins/i18n.js`, `Utils/api.js`, `Locales/{en,ar}.json`

**Offline-state UI surface:** no generic `navigator.onLine` composable — offline/sync state is threaded through `useOfflineUploads.js`, `useSyncEngine.js`, `useWorkspace.js` and surfaced in `SyncCenterModal.vue`, `SyncDataCenter.vue` (Settings/Partials), `FileActions.vue`, `AddRecordModal.vue`, `AppLayout.vue`, `Login.vue`. Start here for any frontend sync-UI work.

Build: `vite.config.js` — `tailwindcss()`, `laravel-vite-plugin` (inputs `resources/css/app.css` + `resources/js/app.js`), `@vitejs/plugin-vue`. No path aliases configured.

## 6. NativePHP Architecture

- Config: `config/nativephp.php` — app id/version, Android SDK (compile 35, min 26, target 35), theme, iOS permissions, build/proguard settings, dev server ports (http 3000, ws 8081), and `cleanup_exclude_files` (strips `resources/js`, `resources/css`, dev configs, tests from the shipped APK bundle — source ships pre-compiled via Vite into `public/build`).
- `nativephp/android/` — a full generated Android Gradle project. **Do not hand-edit** (see §14, never-scan list).
- `app/Providers/NativeServiceProvider.php` — explicit plugin allowlist: Camera, File, Network, Dialog, Share.
- **Offline-mode trigger, one signal used everywhere:** `config('database.default') === 'sqlite'`.
  - `AppServiceProvider.php:20` (register) — creates storage dirs on boot (APK strips empty/dotfiles).
  - `AppServiceProvider.php:57` — forces `app.url`/`asset_url` to `http://127.0.0.1` on embedded builds.
  - `AppServiceProvider.php:74-81` — auto-runs `migrate --force` on SQLite boot (`runMigrationsIfNeeded()`).
  - Comment at `AppServiceProvider.php:53-56` explicitly notes this replaced an `env('NATIVEPHP_APP_ID')` check to avoid breaking prod — i.e. this detection method was already changed once; treat it as load-bearing and fragile.
  - `routes/web.php` and `routes/api.php` both branch mobile-route auth requirements on this same check (see §11).
  - `bootstrap/app.php` branches global exception-handling behavior on this same check (see §9, §13 known bugs).

## 7. Offline-First Architecture & Data Flow

Mobile writes always land in local SQLite first (never a direct-only-online write). Two queuing mechanisms coexist:
1. **`sync_queue` table** (current, primary mechanism, enhanced 2026-08-02) — entity-level create/update/delete operations queued for push to server. Written by `PatientRepository` (via `SyncQueueService`, inside `DB::transaction()`), `PatientSyncService`, `FileSyncService`, `CategorySyncService`, `VisitSyncService`, `NoteSyncService`.
2. **`pending_operations`, `offline_files`, `file_cache` tables** — older/parallel staging tables, accessed via raw `DB::` facade, **no Eloquent model exists for any of the three**. Treat these as legacy/lower-level plumbing under the newer `sync_queue` system; verify current call sites before assuming they're still the primary path for a given entity type.

**Data flow (mobile → server, "push"):** UI action → domain Service/Repository writes to local SQLite + enqueues `sync_queue` row → `ManualSyncService`/`SyncEngineService` (triggered by user tapping "Manual Sync", or possibly a background trigger) drains `sync_queue` → per-entity `*SyncService` (`PatientSyncService`, `NoteSyncService`, etc.) calls the server's `v1/mobile/*` API via `RemoteApiService`/`ApiService` → on success, local row gets `remote_uuid` + `sync_status = synced`; on failure, `retry_count` increments (capped at 5, then **silently stuck** — see Known Bugs #1).

**Data flow (server → mobile, "pull"/"download"):** `DownloadSyncService` fetches patients/notes/visits/files changed since last sync (`patients_last_sync` cursor in `sync_states`), paginated, writes into local SQLite. Cascading note/visit download is gated on the *parent patient's* `updated_at` changing, not a direct signal (see Known Bugs #3).

## 8. Sync Queue Flow (detail)

Table `sync_queue` (post `2026_08_02_000001_enhance_sync_queue_and_versioning` migration): `uuid`(u), `entity_type`(idx), `entity_uuid`(idx), `operation` (create/update/delete), `payload_version`, `payload`(array-cast longText), `status`(idx), `retry_count`, `last_error`, `last_attempt_at`.

Statuses actually used in code (`app/Domains/Sync/Services/SyncQueueService.php`): `pending`, `processing`, `synced`, `failed`, `conflict`. `getPending()` also auto-recovers rows stuck in `processing` for >15 min back to `pending` (crash recovery for the queue itself, but not for the underlying operation's correctness). Rows with `retry_count >= 5` are excluded from `getPending()` **forever**, with no UI-exposed recovery action — this is Known Bug #1.

Dashboard "synced" counter (`routes/web.php:448`-area) queries `status = 'completed'`, which **never matches** anything (real value is `'synced'`) — counter always reads zero, masking queue health (Known Bug, Medium).

## 9. Authentication Flow

- Web/production: standard Laravel session + Sanctum (`personal_access_tokens` table, `config/sanctum.php:40` guard `['web']`).
- `app/Auth/ApiUserProvider.php` — custom user provider for API/mobile guard.
- **`v1/mobile/*` API routes are Sanctum-protected in production (MySQL) but run UNAUTHENTICATED when `database.default === 'sqlite'`** (`routes/api.php`) — deliberate design assumption: one physical device = one authorized doctor, no per-request auth needed on-device. This assumption is currently **undermined** by `AuthController::showLogin` (see Known Bugs, Critical) which auto-logs in as the first `users` row with no password check at all — meaning even the one layer of protection the offline design relies on (a real login) is bypassed.
- `routes/web.php:15` also exposes `POST /api/session/restore` — an embedded auto-login/session-restore endpoint for the native app; audit alongside the AuthController bug before changing either.
- `AuthController::login` (server-side login) wraps remote-token acquisition in a broad `catch (Throwable)` that only logs a warning and lets the user proceed as "logged in" without a token — later API calls fail more confusingly instead of failing at login (Known Bug, Medium).

## 10. Upload Flow

Two upload code paths coexist:
1. **Chunked/resumable** (`app/Services/Upload/{ChunkUploadService,ChunkMergeService,UploadSessionService,UploadChecksumService,UploadValidationService,UploadCleanupService}.php`, controller `Api/ChunkUploadController.php`, mobile-side driver `FileSyncService::uploadLargeFileResumable`) — used for videos (forced since commit `53b6c7b`) and any large file. Session tracked in `upload_sessions` + `upload_chunk_receipts` tables.
2. **Direct upload** (`app/Domains/Media/Services/UploadService.php`, controllers `Api/UploadController.php`, `Api/UploadsController.php`) — smaller files, single request.

Both eventually produce a `PatientFile` row and dispatch `GenerateThumbnailJob`; video files additionally get `OptimizeVideoForStreaming` dispatched from `PatientFileObserver::created`.

**Known-broken in the chunked path (see Known Bugs, Critical/High):** checksum is never computed for the direct-write/video branch of `ChunkMergeService`; "resumable" sessions never actually resume (every retry starts a fresh session); cleanup (`UploadCleanupService`) doesn't delete orphaned direct-write partial files, only legacy chunk-dir files — and this is compounded by `Console/Kernel.php` having an **empty schedule**, so `PurgeExpiredUploads` (the artisan command that would run this cleanup) is never invoked automatically by anything in this repo.

## 11. Important Routes

- `routes/web.php` (534 lines, ~128 routes): Blade/Inertia UI (`/dashboard`, `patients` resource, `admin/*`, `/workspace`, `settings/*`) **plus** native-bridge JSON endpoints under `_native/api/*` (offline pending uploads/notes, sync dashboard/pause/resume/cancel/manual/engine triggers calling `ManualSyncService`/`SyncEngineService` inline, category/bootstrap refresh) and `_native/cache/*` (local file cache stream/base64/thumbnail/status). Also `api/v1/mobile/*` mirror routes guarded to SQLite-only.
- `routes/api.php` (126 lines, ~51 routes, `v1` prefix, mostly `auth:sanctum`): `GET /files/{uuid}/stream` (signed), `v1/login`/`logout`/`me`, `v1/mobile/*` (dashboard, bootstrap, patients, visits, notes, files+stream+thumbnail, doctors, shares, search, profile, resumable + chunk uploads) — **unauthenticated when SQLite**, sanctum-protected on MySQL.
- `routes/console.php` — no custom closures found beyond framework default.

## 12. Important Middleware

- `HandleInertiaRequests.php` — Inertia shared-props.
- `NativePHPProfilerMiddleware.php` — appended only on NativePHP builds (request profiling).
- `ParseMobileMultipartMiddleware.php` — **prepended globally** in `bootstrap/app.php`; normalizes multipart bodies from the mobile client before anything else runs — touch with extreme care, ordering-sensitive.
- `PreventBackHistory.php` — no-cache headers to block back-button access after logout.
- `bootstrap/app.php` also: enables `statefulApi()`, configures CSRF exceptions, `trustProxies` all.

## 13. Important Controllers (one-line each)

**Web:** `PatientController` (patients+notes CRUD), `DashboardController`, `AuthController` (⚠ auth-bypass bug), `SettingsController`, `WorkspaceController` (doctor workspace CRUD/export/print/zip), `Admin/AdminController`, `Admin/DoctorController`.
**API (`Api/`):** `AuthController` (sanctum login/logout/me), `NoteController`, `VisitController`, `ChunkUploadController`, `UploadController`, `UploadsController`, `CategoryController`, `CategoryFileController`, `FileAccessController` (stream/signed-url/thumbnail + cached variants), `GlobalSearchController`, `PatientShareController`, `CreatePatientDiagnosticController` (debug endpoint — verify it's not exposed in production).
**API/Mobile (`Api/Mobile/`):** `PatientController`, `VisitController`, `NoteController`, `FileController` (incl. `pendingIndex`), `DashboardController`, `DoctorController`, `ShareController`, `SearchController`, `BootstrapController`.

## 14. Important Jobs

- `Domains/Media/Jobs/GenerateThumbnailJob` — dispatched from `UploadService::store`, `ChunkMergeService` (post-merge), `ProcessUploadedFileJob`.
- `Domains/Media/Jobs/OptimizeVideoForStreaming` — dispatched from `PatientFileObserver::created` (video files) and `OptimizeVideosCommand` (batch backfill, itself never scheduled — see §10).
- `Jobs/ExportPatientFilesJob` — dispatched from `WorkspaceController::downloadFiles`.
- `Jobs/ProcessUploadedFileJob` — **no dispatch call site found** in current codebase grep. Likely dead/orphaned or dispatched from a path not yet traced — verify before relying on it, and flag if confirmed dead.

## 15. Important Repositories

- `PatientRepository` (offline-first wrapper, the *live* one — see §4 binding) — filters `pending_delete`, enqueues `sync_queue` inside `DB::transaction()`; SQLite is sole source of truth here, no runtime remote fallback (async sync only).
- `FileCacheRepository` — manages locally-cached file copies; streams from remote via `ApiService`/`Http::sink()` when not cached.
- `OfflineFileRepository` — tracks pending/failed offline file uploads, `markSynced()` on remote confirmation.
- `CategoryRepository` — local-first with config-defaults fallback (`buildFromDefaults()` if local table empty).
- `Eloquent/Eloquent{Patient,PatientFile,PatientNote,PatientVisit,User}Repository` — plain CRUD, the "local" layer under the wrapper repos above.
- ⚠ `app/Services/Mobile/{Patient,Note,Visit}Repository.php` — **dead code**, not bound anywhere, zero error handling. See §4.

## 16. Important Services

- `SyncEngineService.php` — "Phase 7" ordered sync engine; SQLite = local source of truth, API = remote source of truth.
- `ManualSyncService.php` — orchestrates modular entity sync from `sync_queue`, batch processing, dashboard metrics, pause/resume/cancel.
- `Sync/{Download,File,Category,Note,Patient,Visit}SyncService.php` — per-entity push/pull logic.
- `Sync/ConflictResolverService.php` — exists; **conflict-resolution maturity not fully confirmed** — the `version`/`server_updated_at`/`client_updated_at` columns needed for real optimistic-concurrency conflict detection were only added in the 2026-08-02 migration, so verify this service actually uses them before assuming conflict resolution is production-ready (treat as "infrastructure present, usage unverified" until Sprint 5 audits it — see `docs/OFFLINE_FIRST_MASTER_PLAN.md`).
- `Sync/CacheCleanupService.php` — local cache pruning.
- `Domains/Sync/Services/SyncQueueService.php` — the queue itself (push/pull/getPending/markFailed/markSynced).
- `Upload/{ChunkUpload,ChunkMerge,UploadChecksum,UploadCleanup,UploadSession,UploadValidation}Service.php` — chunked upload pipeline.
- `Mobile/{ApiService,FileCacheService,RemoteApiService}.php` — HTTP client layer to the server API; `ApiService` is registered as a **singleton** in `AppServiceProvider` (shared token state — be careful under concurrent/queued contexts).
- `OfflineUploadService.php` — offline-first upload queuing (confirmed correct: streams checksums, no full-file memory loads).
- `Domains/Patients/Services/{PatientService,ShareService}.php`, `Domains/Media/Services/UploadService.php`, `Domains/Users/Services/PermissionService.php`, `Domains/ActivityLogs/Services/ActivityLogger.php`.

## 17. Important Models & Relationships

| Model | Location | Relationships |
|---|---|---|
| `Patient` | Domains/Patients/Models | `primaryDoctor()->User`, `visits()->PatientVisit` (hasMany), `shares()->PatientShare` (hasMany), `files()->PatientFile` (hasMany), `notes()->PatientNote` (hasMany) |
| `PatientNote` | Domains/Patients/Models | `patient()->Patient`, `author()->User` (FK `author_id`) |
| `PatientVisit` | Domains/Patients/Models | `patient()->Patient` |
| `PatientShare` | Domains/Patients/Models | `patient()->Patient`, `doctor()->User`, `sharedBy()->User` — **no uuid column, no soft deletes** (inconsistent with the rest of the schema — verify intentional) |
| `PatientFile` | Domains/Media/Models | `patient()->Patient`, `uploader()->User` (FK `uploaded_by_id`); accessors for url/thumbnail_url/name/extension |
| `FileCategory` | Domains/Media/Models | none; `$fillable`: uuid, name, icon, color, client_updated_at |
| `UploadSession` | Domains/Media/Models | `patient()->Patient`, `user()->User`; casts metadata/received_chunk_indexes → array |
| `SyncQueue` | Domains/Sync/Models | none; casts payload → array |
| `User` | Domains/Users/Models | `patients()->Patient` (hasMany, FK `primary_doctor_id`) |
| `ActivityLog` | Domains/ActivityLogs/Models | `user()->User`; casts payload → array |
| `CachedCategory` | app/Models (only stray top-level model) | none; per-user offline category cache; casts is_visible → boolean |

No encrypted casts anywhere in the model layer.

## 18. Database Relationships (FK summary)

`patients.primary_doctor_id → users` (nullOnDelete), `patients.created_by_id → users` (nullOnDelete) · `patient_files.patient_id → patients` (cascade), `patient_files.uploaded_by_id → users` (cascade) · `patient_visits.patient_id → patients` (cascade) · `patient_notes.patient_id → patients` (cascade), `patient_notes.author_id → users` (cascade, later made nullable) · `patient_shares.patient_id → patients` (cascade), `.doctor_id/.shared_by_id → users` (cascade / nullOnDelete), unique on `(patient_id, doctor_id)` · `upload_sessions.patient_id/.user_id → patients/users` (cascade) · `activity_logs.user_id → users` (nullOnDelete, keyed by `entity_uuid` string, not a real FK, for cross-entity audit trail).

## 19. UUID Strategy

- `uuid` column exists on: users, activity_logs, patients, file_categories, patient_files, patient_visits, patient_notes, sync_queue, sync_jobs, upload_sessions — **always a secondary, unique, indexed column**, never the primary key. `offline_files` is the one exception: `uuid` is its primary key (client-only staging table).
- **Generation:** client-side, in Eloquent `creating` boot hooks via `Str::uuid()` — happens identically whether the record is created on the mobile device or the server, so a UUID exists from the moment of creation regardless of connectivity.
- **`remote_uuid` column** (on patient_files, patient_notes, patient_visits, offline_files): holds the server-assigned UUID once an offline-created record has synced up — this is the local-UUID ↔ server-UUID mapping. **Never use the local autoincrement `id` for a remote API call — always resolve `remote_uuid` first** (a known bug in `VisitSyncService` currently violates this — see §21).
- Mobile API controllers accept a client-supplied `uuid` in the payload when present, falling back to server `Str::uuid()` — meaning the server currently **trusts client-supplied UUIDs as-is**. This is a hidden assumption (see §23) — there is no verified collision/spoofing check.

## 20. SQLite Schema vs MySQL Schema

Same migration set applies to both connections — **no structural table divergence** except two explicitly-guarded migrations that skip their `up()` entirely on SQLite because SQLite doesn't need the MySQL drop/re-add-FK workaround for nullable-FK changes:
- `2026_07_23_000005_make_primary_doctor_id_nullable...`
- `2026_07_25_223012_make_author_id_nullable...`

Beyond that, treat SQLite and MySQL schemas as **identical**. Runtime behavior differences (not schema) are driven by `DB::getDriverName()` branches scattered in services/controllers for SQL-dialect quirks (JSON functions, upsert syntax) — e.g. `FileCacheRepository`, `ChunkMergeService`. `config/database.php:20` default connection is `mysql` (env `DB_CONNECTION`); native builds set it to `sqlite` via env at build/boot time.

## 21. Known Bugs (carried from prior audit + this pass — see `docs/OFFLINE_FIRST_MASTER_PLAN.md` "Known Issues" for the authoritative, sprint-mapped version)

**Critical:**
- `AuthController::showLogin` auto-logs in as the first `users` row with zero password check when `database.default === 'sqlite'` (`app/Http/Controllers/AuthController.php`). Full auth bypass on the offline build.
- `bootstrap/app.php` swallows all non-API exceptions into silent redirects on SQLite builds with debug off — hides every other bug in production.
- `DownloadSyncService` compares differently-formatted timestamp strings lexicographically (`Y-m-d H:i:s` vs ISO `…T…Z`) — same-day changes silently excluded from incremental sync.
- Note/Visit incremental download gated on parent patient's `updated_at`, not a direct child-record signal — new notes/visits can silently never reach a device.
- Checksum validation bypassed entirely for the direct-write (video) upload branch of `ChunkMergeService`.

**High:**
- `sync_queue` items permanently excluded from `getPending()` after `retry_count >= 5`, with no UI recovery path.
- `VisitSyncService` uses `$visit->remote_uuid ?? $visit->id` — can leak a local integer ID to the remote API if `create` hasn't synced yet.
- "Resumable" video uploads never actually resume — every retry opens a fresh `UploadSession` from scratch.
- `UploadCleanupService` never deletes orphaned direct-write partial files (only legacy chunk-dir files) — **and** the cleanup command (`PurgeExpiredUploads`) is never scheduled anywhere in `Console/Kernel.php`, so this cleanup effectively never runs automatically at all.
- Dead duplicate repository classes (`app/Services/Mobile/{Patient,Note,Visit}Repository.php`) are a landmine for a future accidental import.

**Medium:**
- HTTP client blanket-retries `/chunk/complete`, which can turn a slow-but-successful merge into a reported failure.
- File handle leak in `FileSyncService`'s chunk-upload loop on exception (never `fclose`'d on the error path).
- Patient download cutoff captured after, not before, the paginated fetch loop — race window for mid-pagination changes.
- Dashboard "synced" counter queries `status = 'completed'`, which never matches the real `'synced'` value — always shows zero.
- CSRF-mismatch redirect has no loop cap in `bootstrap/app.php`.
- `AuthController::login`'s broad `catch (Throwable)` around token acquisition lets a failed login proceed as if successful.

**Newly noted this pass (not yet in the master plan's Known Issues table — add there before fixing):**
- `Jobs/ProcessUploadedFileJob` has no found dispatch call site — verify live vs dead before modifying.
- `Console/Kernel.php` schedule is entirely empty — neither `PurgeExpiredUploads` nor `OptimizeVideosCommand` runs on any cadence unless triggered externally (e.g. OS-level cron outside this repo, which would not be visible here).
- `PatientShare` model/table has no `uuid` and no soft deletes, unlike every sibling entity — verify if intentional (shares may be considered ephemeral/non-syncable) before assuming it needs the same treatment.
- `pending_operations`, `file_cache`, `offline_files` tables have no Eloquent model — all access is raw `DB::` — no schema-level validation guard.

## 22. Technical Debt

- Two parallel offline-staging mechanisms (`sync_queue` vs `pending_operations`/`offline_files`) — unclear if the latter is fully superseded; needs an explicit audit before any refactor touches either.
- `app/Services/Mobile/*Repository.php` dead code should be deleted (Sprint 6 of the master plan), not left as a trap.
- No automated scheduling for cleanup commands — cron/scheduler wiring is missing or lives outside the repo.
- No centralized sync success/failure metrics/logging.
- Mixed conditional-driver logic (`getDriverName()`) scattered across services instead of centralized — fine for now, but growing.

## 23. Hidden Assumptions (undocumented in code, inferred from behavior)

1. **"One device = one authorized doctor"** is the entire security model for the offline build (no per-request auth on `v1/mobile/*` when SQLite) — this assumption is currently violated by the auth-bypass bug, but even when "fixed," the underlying assumption itself (physical possession = authorization) is a product decision worth surfacing to the user, not just a bug.
2. **Client-supplied UUIDs are trusted without server-side collision/ownership verification.** Any code path that accepts a `uuid` field from a mobile payload assumes the client is honest.
3. **`config('database.default') === 'sqlite'` is treated as a universal, permanent proxy for "this is the offline native build."** It's checked independently in `AppServiceProvider`, `bootstrap/app.php`, `routes/web.php`, `routes/api.php`. If a future feature ever needs SQLite for a *non-native* reason (e.g. local dev, testing), all of this logic would misfire simultaneously. Grep for `database.default` before assuming this pattern is confined to one file.
4. **`ApiService` is a singleton** (`AppServiceProvider`) — assumes one shared auth-token/session per process. Fine for a single-user native app process; would silently break if this code ever ran under a multi-request queue worker context with different users.
5. **Manual cleanup commands assume an external scheduler** that does not exist in this repo (`Console/Kernel.php` schedule is empty) — someone/something outside this codebase may or may not be invoking `uploads:purge-expired` and `videos:optimize`. Do not assume they run.
6. **Raw-`DB::`-accessed tables (`pending_operations`, `file_cache`, `offline_files`) have no model-level contract** — any migration touching their columns must be manually cross-checked against every raw-query call site (grep `pending_operations`/`file_cache`/`offline_files` across `app/`).

## 24. Current Sprint / Current Project Status

- **Phase:** Pre-implementation / planning. No sprint work has started yet.
- **Roadmap:** `docs/OFFLINE_FIRST_MASTER_PLAN.md`, created 2026-08-05 — 6 sprints (Patient, Notes, Files & Attachments, Visits, Reliability & Sync Engine, Performance & Hardening), each Not Started.
- **Blocking prerequisite flagged before Sprint 1 can be considered safe to close:** the AuthController auth-bypass bug (§21, §23.1).
- Check `docs/OFFLINE_FIRST_MASTER_PLAN.md`'s "Sprint Progress Dashboard" table for the live status — it is the authoritative tracker, this file only summarizes.

---

## 25. Where Should Future AI Sessions Start Reading? (prioritized order)

1. **This file** (`.ai/PROJECT_MEMORY.md`) — full context, no repo scan needed for orientation.
2. **`.ai/WORKLOG.md`** — what's actually been done since this file was last updated; this file may lag reality.
3. **`docs/OFFLINE_FIRST_MASTER_PLAN.md`** — the authoritative sprint plan, acceptance criteria, and live Known Issues table (more current than §21 above for issue status/checkboxes).
4. **Only then**, for the specific task at hand: read the specific files named in the relevant section above (§13–§17), not the whole directory.
5. If the task touches sync/upload/auth and this file's Known Bugs section (§21) doesn't mention something you're seeing in code, **re-verify in code before trusting this file** — it may be stale (see RULES).

## 26. Files That Should Almost Never Be Scanned

- `vendor/`, `node_modules/` — vendored dependencies, not project code.
- `nativephp/android/` — a full **generated** Android Gradle project (app/, gradle/, .gradle/, .kotlin/); regenerated by the NativePHP build tooling, not hand-maintained. Reading it wastes tokens and teaches you nothing about app logic.
- `public/build/` — compiled Vite output, not source.
- `bootstrap/cache/`, `storage/framework/`, `storage/logs/` — runtime-generated caches/logs, not source.
- `.git/` — obviously.
- `storage/app/`, `storage/data/` — runtime data (uploaded files, SQLite DB file itself), not code.

## 27. Files That Are the Source of Truth

- **`database/migrations/*`** — the actual schema, ahead of any prose description (including this file's §17–§20).
- **`routes/web.php` + `routes/api.php`** — the actual endpoints and their auth requirements; this file's §11 is a summary, not a substitute.
- **`app/Providers/RepositoryServiceProvider.php`** — the actual live repository bindings; always check this before assuming any `*Repository.php` file is "the" implementation (there are known dead duplicates — §4, §15).
- **`config/database.php` + `.env`** — actual connection selection; §6/§20's description of the sqlite/mysql switch is a summary.
- **`docs/OFFLINE_FIRST_MASTER_PLAN.md`** — the authoritative, checkbox-tracked status of known issues and sprint progress; more current than this file's §21/§24 between memory updates.

## 28. How to Safely Modify This Project

1. Read this file + `WORKLOG.md` + the relevant `docs/OFFLINE_FIRST_MASTER_PLAN.md` sprint section first — do not start from a blind repo scan.
2. Respect the Architecture Principles in `docs/OFFLINE_FIRST_MASTER_PLAN.md` (UUID as identity, never delete before confirmed sync, small isolated commits, one feature at a time, errors must be visible not swallowed, idempotency by default).
3. Before touching any `*Repository.php`, check `RepositoryServiceProvider.php` to confirm it's actually the live/bound implementation, not a dead duplicate.
4. Before touching sync/queue status logic, grep every consumer of the `sync_queue.status` values (`SyncQueueService`, `ManualSyncService`, `SyncEngineService`, the dashboard counter query in `routes/web.php`) — they must stay in agreement.
5. Before touching anything gated on `config('database.default') === 'sqlite'`, grep for that exact string across the repo first — it's checked independently in multiple files (§23.3), not centralized.
6. Never edit an already-shipped migration — add a new one.
7. After any change: update `.ai/WORKLOG.md` (always) and `.ai/PROJECT_MEMORY.md` (if architecture/schema/routes/known-bugs changed) per the RULES section below.

## 29. What Must NEVER Be Changed (without an explicit, deliberate decision + master-plan update)

- **UUID columns must remain secondary/unique, never repurposed as primary keys** (except `offline_files`, which already uses uuid-as-PK by design) — breaking this breaks every `remote_uuid` mapping across the sync system.
- **The `config('database.default') === 'sqlite'` detection pattern must not be silently replaced or duplicated with a different signal** in only some of its call sites — it must change everywhere at once or not at all (§23.3).
- **`sync_queue.status` string values** (`pending`, `processing`, `synced`, `failed`, `conflict`) must not be renamed without updating every consumer in the same change — including the dashboard counter query, which is *already* broken from a past mismatch (§21).
- **Already-shipped migrations** — never edit in place; existing installed SQLite databases on doctors' devices must not be broken by a rewritten migration.
- **`ParseMobileMultipartMiddleware` ordering** in `bootstrap/app.php` (currently prepended globally) — reordering could break mobile multipart uploads silently.
- **`ApiService` singleton registration** in `AppServiceProvider` — do not casually change to non-singleton without auditing every caller that relies on shared token state.

## 30. RULES — Keep This Memory Alive

- After every coding task: update `.ai/WORKLOG.md` (always), and update this file (`PROJECT_MEMORY.md`) if the change affected architecture, schema, routes, services, known bugs, or sprint status.
- If this file and the actual code disagree, the code wins — fix this file to match, note the correction in `WORKLOG.md`.
- Do not let either file go stale. A future session trusts this file first (§25) — stale content here costs more than the time saved by not updating it.
