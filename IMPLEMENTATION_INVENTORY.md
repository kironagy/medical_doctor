# Implementation Inventory (Phase 0 — Source Inventory, No Code Changes)

Produced before any code was modified. Every claim below is a direct source read (this session) or a subagent trace whose output was spot-checked against source by direct reads of the same files (noted inline). Where a prior document (`ARCHITECTURE_ANALYSIS.md`, `TARGET_ARCHITECTURE.md`) said something that turned out to be wrong, it is called out explicitly rather than silently fixed — those docs are not corrected in place.

---

## 0. Correction to prior docs

`ARCHITECTURE_ANALYSIS.md` §4 and `TARGET_ARCHITECTURE.md` §4.1 both name `Api\Mobile\PatientController::store()` as *the* patient-creation entry point. **That is incomplete and, for the primary flow, wrong.** The actual chain the main Vue workspace UI uses for patient CREATE (and most of UPDATE/DELETE) is:

```
AddPatientModal.vue → useWorkspace.js addPatient()
  → axios.post('/api/v1/workspace/patients', ...)   [hardcoded, not prefix-swapped]
  → routes/web.php:225 → WorkspaceController::storePatient()
  → app/Repositories/PatientRepository.php::create()   ← the real sync_status branch point
  → app/Repositories/Eloquent/EloquentPatientRepository.php::create()
  → Patient::create()
```

`Api\Mobile\PatientController` is a **second, parallel implementation** of patient CRUD, not dead code — it's genuinely reachable (see §2). The two implementations are inconsistent with each other (e.g. `Mobile\PatientController::update()` never calls `SyncQueueService::push()`, while `PatientRepository::update()` always does). Any Phase 2 work must fix **both**, not just one, or offline behavior will differ depending on which URL the frontend happened to call.

The same dual-path shape recurs for Notes (`Api\NoteController` vs `Api\Mobile\NoteController`) and Visits (`Api\VisitController` vs `Api\Mobile\VisitController`) — see §2.2/§2.3.

---

## 1. Kotlin routing layer (confirmed by direct read)

`nativephp/android/app/src/main/java/com/nativephp/mobile/network/RequestRouter.kt` is the single routing authority. Header comments confirm this is mid-migration ("Phase 7 Offline Architecture") — not a from-scratch design.

**`UrlNormalizer.isInternalRoute()`** (`UrlNormalizer.kt:104-127`) — broader than just `/api/*`: also `/_native/`, `/sanctum/`, `/broadcasting/`, `/workspace`, `/dashboard`, `/login`, `/logout`, `/patients`, `/settings`, `/admin`, `/`, and any `.php`. Page-level Inertia routes are already classified "internal" the same as API routes.

**Current rules** (`RequestRouter.kt:100-135`):

| Rule | Condition | Target today | Correct today? |
|---|---|---|---|
| 2 | any `/_native/*` path | `LOCAL_PHP`, always | Yes — keep |
| 3 | internal route + GET + ONLINE | `EXTERNAL` (production) | Yes — matches target |
| 4 | internal route + POST/PUT/PATCH/DELETE on `/api/`, `/sanctum/`, `/broadcasting/` + ONLINE | `LOCAL_PHP` (embedded Laravel) | **No — this is the line to cut** |
| — | internal route + POST/PUT/PATCH/DELETE **not** matching the `/api/` prefix check above (e.g. `/login`, `/logout` form posts) + ONLINE | falls through to `else` at `:126-129` → `EXTERNAL` | Already correct (web-form posts already go to production) |
| 5 | internal route + OFFLINE (any method) | `LOCAL_PHP` | Stays — but see §4 for what Laravel must do differently here |

**Required Phase-1 change**: delete the `isApiMutation` special case (`RequestRouter.kt:114-119`, branch at `:122-125`) so internal-route + ONLINE always returns `EXTERNAL` regardless of method. No change needed to Rule 2, Rule 5, `UrlNormalizer`, `NetworkStateManager`, or `RouteTarget`.

**`NetworkStateManager.kt`** (128 lines, read in full) — real device connectivity via `ConnectivityManager` callbacks (`:56-80`), not `navigator.onLine`. Already authoritative; not currently exposed to JS (no `@JavascriptInterface` method reads it, confirmed by grep — only `RequestRouter.route()` at `WebViewManager.kt:304,399-402` consumes it). Exposing it to JS is a **UX nicety for Phase 4**, not a Phase 1 correctness requirement — the actual online/offline enforcement is the Kotlin router (Phase 1) plus the Laravel-side rejection (Phase 2), neither of which needs JS to know the real state.

**Page origin**: confirmed separately (`nativephp/android/.../MainActivity.kt:147,236-265`) that the WebView itself already loads from `https://prof-hosam-fekry.online` when online and only falls back to `127.0.0.1` when offline — independent of `RequestRouter`. This part needs no change.

---

## 2. Entity call graphs

### 2.1 Patient

| Op | Frontend call | Route(s) | Controller | Repository/Service | sqlite branch |
|---|---|---|---|---|---|
| LIST | `useWorkspace.js:691,848` `axios.get('/api/v1/workspace/patients-list')` | `web.php:216`, no-auth-middleware `api/v1` group | `WorkspaceController::patientList` (`:80-111`) | `PatientRepository::paginated()` → `EloquentPatientRepository::paginated()` | none in list path |
| READ (single) | `useWorkspace.js:196,224` `/api/v1/workspace/{uuid}` or `/_native/api/workspace/{uuid}` | `web.php:217`, `:542` | `WorkspaceController::patientData` | reads local/remote per connection | see `WorkspaceController.php:262` (triggers `DownloadSyncService` pull) |
| CREATE | `useWorkspace.js:511-543` **always** `axios.post('/api/v1/workspace/patients', ...)` | `web.php:225` | `WorkspaceController::storePatient` (`:113-192`) | `PatientRepository::create()` (`:137-152`) | `PatientRepository.php:139-151` — non-sqlite: `sync_status='synced'`; sqlite: `DB::transaction`, `pending_create`, `SyncQueueService::push()` |
| CREATE (2nd path, live) | reachable at `POST /api/v1/mobile/patients` | `api.php:68` (prod, `auth:sanctum`) / `web.php:272` (sqlite alias, no auth) | `Api\Mobile\PatientController::store()` (`:84-278`) | direct `Patient::create()`, no repository | `:125-127`, `:252-258` — has its own client-supplied-`uuid` idempotency check (`:130-140`) and `QueryException` race fallback (`:265-277`) that the workspace path does **not** have |
| UPDATE | `useWorkspace.js:592-612` — **branches on `navigator.onLine`**: online → `PUT /api/v1/mobile/patients/{uuid}`; offline → `PUT /api/v1/workspace/patients/{uuid}` | `api.php:69`/`web.php:273` vs `web.php:226` | `Api\Mobile\PatientController::update()` (`:280-322`) vs `WorkspaceController::updatePatient` (`:194-227`) | Mobile path bypasses the repository entirely (`Patient::where(...)->firstOrFail()->update()` directly); Workspace path uses `PatientRepository::update()` | Mobile: `:308-315`, sets `pending_update` but **does not** call `queueService->push()` (inconsistent with repo path). Workspace/repo: `:156-168`, sets `pending_update` **and** pushes queue. |
| DELETE/RESTORE/FORCE-DELETE | `useWorkspace.js:865-897` `DELETE/POST /api/v1/workspace/patients/{uuid}[/force\|/restore]` | `web.php:227-229` | `WorkspaceController::deletePatient/forceDeletePatient/restorePatient` (`:229-245`) | `PatientRepository::delete/forceDelete/restore` (`:171-260`) | Full logic in `PatientRepository.php` — non-sqlite plain delete/forceDelete; sqlite: `pending_delete` staging, cancels an in-flight `pending_create` via queue instead of a pointless delete push (`:194-210`), `deleteRemoteDirectly()` fallback (`:88-98`) for a patient never pulled locally |
| DELETE (2nd path) | reachable at `DELETE /api/v1/mobile/patients/{uuid}` | `api.php:70`/`web.php:274` | `Api\Mobile\PatientController::destroy()` (`:324-342`) | **delegates to `patientRepo->delete()`** — same repo as workspace path, so DELETE (unlike CREATE/UPDATE) is actually consistent between the two controllers | n/a — delegates |

`EloquentPatientRepository::delete()`/`forceDelete()` (`:112-135`, `:180-199`) carry their own sqlite branch but a code comment (`:122-126`) states they are dead — `PatientRepository` reimplements both directly rather than delegating. Grep for other callers before removing in Phase 5; not confirmed dead by this inventory, only self-documented as such.

### 2.2 Notes

| Op | Frontend call | Route(s) | Controller |
|---|---|---|---|
| CREATE/READ | `AddRecordModal.vue:209,228`, `DoctorWorkspace.vue:857` — via `apiUrl()` helper, which resolves to `/api/v1/mobile/patients/{uuid}/notes` on native builds, `/api/v1/patients/{uuid}/notes` on browser | `api.php:80`/`web.php:288` (mobile) vs `web.php:241-242` (browser) | `Api\Mobile\NoteController::store/index` (`:14-108`) vs `Api\NoteController::store/index` (`:24-71`) |
| UPDATE/DELETE | `DoctorWorkspace.vue:836,850`, `CategoryBlock.vue:1349` | `api.php:81-82`/`web.php:290-291` (mobile) vs `web.php:243-244` (browser) | `Mobile\NoteController::update/destroy` (`:110-216`) vs `Api\NoteController::update/destroy` (`:73-112`) |

Sqlite branch: **both `Mobile\NoteController::store` and `Api\NoteController::store`** set `sync_status` (`pending_create` vs `synced`). `Mobile\NoteController::update/destroy` also branch (`pending_update`/`pending_delete`) and capture the Bearer token for the sync engine. **`Api\NoteController::update/destroy` do not branch on sqlite at all** — plain update/delete regardless of connection. This is an existing inconsistency, not something this rewrite introduces.

`Mobile\NoteController::resolvePatient()` (`:218-239`) creates a **stub Patient** (`name = 'Patient (' . substr($uuid,0,8) . ')'`) when the referenced patient isn't found locally on sqlite — a second, differently-worded instance of the same stub-patient pattern documented for `ChunkUploadController` in `ARCHITECTURE_ANALYSIS.md` §5. **Not previously documented.** Any Phase 2 fix for the "Patient XXXXX" behavior must address both call sites, not just `ChunkUploadController`.

An unused route alias exists: `POST/DELETE /_native/api/offline/notes[/…]` (`web.php:333-334`) → same `Mobile\NoteController` methods. No frontend caller found (grepped `resources/js/**`) — likely safe to remove in Phase 5, but re-grep at that time rather than trusting this inventory alone.

### 2.3 Visits

| Op | Frontend call | Route(s) | Controller |
|---|---|---|---|
| CREATE | `CategoryBlock.vue:1250`, `DoctorWorkspace.vue:921` | `api.php:73-74`/`web.php:294` (mobile) vs `web.php:236` (browser) | `Mobile\VisitController::store` (`:63-132`) vs `Api\VisitController::store` (`:23-44`) |
| UPDATE | `DoctorWorkspace.vue:918` | `api.php:75`/`web.php:296` vs `web.php:237` | `Mobile\VisitController::update` (`:134-187`) vs `Api\VisitController::update` (`:46-69`) |
| DELETE | route exists, **no frontend caller found** | `api.php:76`/`web.php:297` vs `web.php:238` | `Mobile\VisitController::destroy` / `Api\VisitController::destroy` — currently unreachable from the UI |

Same sqlite-branch pattern as Notes (`Mobile\VisitController` sets `pending_create`/`pending_update`/`pending_delete` + Bearer-token capture at every mutation; `Api\VisitController` has no sqlite awareness). No `_native/api/offline/visits` alias exists (unlike Notes).

### 2.4 Categories

`GET /api/v1/categories` → `Api\CategoryController::index` (`web.php:210`, single controller for both origins — no Mobile-namespace duplicate here). Sqlite: reads `CategoryRepositoryInterface` → `cached_categories` table (`EloquentCategoryRepository`). Non-sqlite: merges `config('categories')` with `user.preferences['custom_categories']` — **no table at all** on production; this is not sync machinery, it's a different storage backend per connection, and `cached_categories` is populated separately by `BootstrapController::refreshCache()` pulling from production. Treat as distinct from the sync_queue/pending-write system — see §3.

### 2.5 Files — canonical upload path (verified)

```
AddRecordModal.vue:257-258 → useUploads().uploadFile()      [confirmed: the online path, always]
  → POST /api/v1/chunk/init     → ChunkUploadController::init()
  → POST /api/v1/chunk/chunk  ×N → ChunkUploadController::chunk()
  → POST /api/v1/chunk/complete → ChunkUploadController::complete()
  → ChunkMergeService::merge()  → creates PatientFile row, writes bytes to
                                   storage/app/patients/{uuid}/{fileUuid}.{ext}
```

No size threshold branch exists — every file, however small, goes through init/chunk/complete. `useOfflineUploads.js` is **not a separate implementation**; it imports shared state from `useUploads.js` and calls the identical three chunk endpoints. `AddRecordModal.vue:254-271` is the only online/offline switch, based on `useSyncEngine()`'s `navigator.onLine`-derived flag (the same unreliable signal noted in §1) — after Phase 1, which controller/connection actually receives the request is decided by Kotlin's real connectivity check regardless of which of these two composables JS picked.

**Confirmed dead from the UI's perspective** (zero callers found by repo-wide grep of `resources/js/**`):
- `App\Http\Controllers\Api\UploadController` + `App\Domains\Media\Services\UploadService` (route: `POST /api/v1/patients/{uuid}/files`, `web.php:197`)
- `Api\Mobile\FileController::store` **for creation** specifically (route comment at `web.php:279`/`api.php:86` claiming a `useUploads.uploadDirectly` caller is stale — no such function exists in the JS codebase)

**Confirmed live, do not delete**: `Api\Mobile\FileController` as a class — its `destroy`/`update`/`show`/`stream`/`thumbnail`/`index`/`pendingIndex` methods back real offline-file flows (`useOfflineUploads.js:618`, `CategoryBlock.vue:1394,1349` for delete; local file hydration). Only its `store()` method (file creation) is unreached by current UI code.

File DELETE has two independent implementations: `DELETE /api/v1/files/{uuid}` → `FileAccessController::destroy` (web dashboard, immediate `forceDelete()`, no sha256/dedup gating) vs `DELETE /_native/api/offline/uploads/{uuid}` → `Mobile\FileController::destroy` (native/offline, sqlite stages `pending_delete` instead of hard delete).

`ChunkUploadController::resolvePatient()` (`:459-529`) still contains the literal `'name' => 'Patient ' . $uuid` stub (`:508`), gated to sqlite-only (`:493`) — this is the original, previously-documented "Patient XXXXX" source, confirmed still present and confirmed reachable via the canonical live upload path.

---

## 3. Full SQLite-branch inventory

Every `config('database.default') === 'sqlite'` (or equivalent) branch found by repo-wide grep of `app/`, `routes/`, `config/`, grouped by whether it's part of the sync/pending-write mechanism or something else entirely. **This grouping is the main deliverable of this section — do not remove anything in the second table under the assumption "it's a sqlite branch, sync is being deleted, so it goes too."**

### 3.1 Sync/pending-write related (Phase 2/5 territory)

- `app/Repositories/PatientRepository.php` — `isOfflineDevice()` (`:73-76`), `create/update/delete/restore/forceDelete` (`:137-333`)
- `app/Repositories/Eloquent/EloquentPatientRepository.php:127,191` (self-documented as dead, unconfirmed — verify callers before touching)
- `app/Http/Controllers/WorkspaceController.php:130` (Bearer-token capture for later sync push), `:262` (triggers `DownloadSyncService` pull on patient open)
- `app/Http/Controllers/Api/Mobile/PatientController.php:125-127,215-227,252-258,308-315`
- `app/Http/Controllers/Api/NoteController.php:58-61` (store only)
- `app/Http/Controllers/Api/Mobile/NoteController.php:94,102,115-124,156-162,174-183,207-213`
- `app/Http/Controllers/Api/Mobile/VisitController.php:78-89,110-124,144-153,177-184,199-208,222-229`
- `app/Domains/Media/Services/UploadService.php:77-79` (dead path, still has the branch)
- `app/Services/Upload/ChunkMergeService.php:206`
- `app/Http/Controllers/Api/Mobile/FileController.php:189,392,439`
- `app/Http/Controllers/Api/ChunkUploadController.php:493-529` (stub-patient creation)
- `app/Domains/Media/Models/PatientFile.php:55,75` (`getUrlAttribute`/`getThumbnailUrlAttribute` — decides local-cache vs production URL based on sync state)
- `routes/web.php:624,665` (Bearer-token capture before dispatching manual sync)
- Table/queue plumbing: `SyncQueueService`, `SyncEngineService`, `ManualSyncService`, `sync_queue` table — full blast radius in §3.3

### 3.2 NOT sync-related — do not remove when the sync system goes

- **`config/database.php:35-57`** — WAL/busy-timeout/foreign-key SQLite driver tuning. Needed by *any* on-device SQLite usage, including the new offline-package feature.
- **`app/Providers/AppServiceProvider.php:20,101,109`** — creates storage dirs, switches queue driver to `database` for the native queue worker, forces `app.url`/`app.asset_url` to `127.0.0.1`, auto-runs `migrate --force` on boot. Embedded-runtime bootstrapping; the offline-package feature still needs a working embedded Laravel+SQLite instance, so these stay.
- **`app/Domains/Media/Jobs/OptimizeVideoForStreaming.php:33`, `GenerateThumbnailJob.php:44`** — skip ffmpeg inline-on-device to avoid blocking the request thread (`QUEUE_CONNECTION=sync` on device). Performance guard, unrelated to sync_status.
- **`app/Http/Controllers/Api/FileAccessController.php:116`** — forces range-capped responses on-device because the WebView's native bridge can't buffer large `Content-Length` bodies. Pure local file-serving workaround.
- **Authorization/`Gate::authorize` skips** on sqlite throughout `WorkspaceController.php:385-389`, `CategoryFileController.php:33`, `FileCacheRepository.php:90`, `FileAccessController.php:540,685,706,741,758`, `Mobile/FileController.php:65,254,298`, `Mobile/NoteController.php:128` (conditioned, not unconditional), `Mobile/VisitController.php:68,139,194` — this is the single-user-device auth model (no meaningful per-user ACL against server-issued roles on an offline device). Removing these reintroduces 403s that block all local access; separate concern from sync data flow entirely.
- **Auto-login on sqlite**: `ParseMobileMultipartMiddleware.php:48`, `AuthController.php:22`, `routes/web.php:15-86` (`/api/session/restore`), `:95-108` (root redirect), `WorkspaceController.php:50`, `ChunkUploadController.php:98,512` (fallback-user resolution). Single-user-device authentication model.
- **`routes/api.php:36,49,117` and `routes/web.php:267`** — the `auth:sanctum` on/off gating and the entire conditional `api/v1/mobile/*` alias route group. This is the app's *authentication/routing architecture* for the embedded build. Deleting it breaks how mobile requests authenticate at all — do not conflate with removing the *sync_status logic* those same routes' controllers happen to contain.
- **`Api\CategoryController.php:15,44`, `BootstrapController` cached-categories logic** — offline reference-data caching (categories/visit-types), architecturally distinct from the sync_queue/pending-write system. An offline-package patient view will likely still need category names rendered — do not delete this without deciding how the offline package resolves category labels (open question, §5).
- **`CreatePatientDiagnosticController.php`** — read-only diagnostics, branches only affect what it reports.

### 3.3 Table blast radius

| Table | Status | Referenced by |
|---|---|---|
| `sync_queue` | Core sync — remove in Phase 5 | `SyncQueue` model, `SyncQueueService`, `SyncEngineService`, `ManualSyncService`, `Mobile\PatientController.php:333`, sync dashboard routes |
| `offline_files` | Core sync (offline upload queue) — remove in Phase 5, but confirm §4's file-upload rejection doesn't need any transitional read of it first | `OfflineFileRepository`, `FileAccessController`, `SyncEngineService`, `Sync\FileSyncService`, `Sync\PatientSyncService`, several `_native` routes |
| `file_cache` | LRU download cache — **repurpose for offline packages per `TARGET_ARCHITECTURE.md` §4.2, do not blindly drop** | `FileCacheRepository` (full CRUD/eviction), `FileAccessController`, `SyncEngineService` |
| `cached_categories` | Reference-data cache, not sync-queue — see §3.2 open question | `CachedCategory` model, `BootstrapController`, `EloquentCategoryRepository`, `CategoryRepositoryInterface` |
| `pending_operations` | **No app code references it at all** (migration-only) — likely dead | migration `2026_07_03_222612` only |
| `sync_meta` | **No app code references it at all** (migration-only) — likely dead | migration `2026_07_23_000001` only |
| `sync_states` | **Live**, not dead — do not lump with the two above | `RunManualSyncJob.php:99-136`, `DownloadSyncService.php:40-54` |
| `sync_jobs` | **No app code references it at all** (migration-only) — likely dead | migration `2026_06_29_144926` only |
| `offline_packages` | Does not exist yet — new table for Phase 3 | — |

Re-grep `pending_operations`/`sync_meta`/`sync_jobs` at Phase-5 time before dropping — this audit covered `app/`, `routes/`, `config/` only, not raw SQL, queued job payloads, or artisan commands.

---

## 4. What Phase 1 actually touches

**Exactly one file changes in Phase 1**: `nativephp/android/app/src/main/java/com/nativephp/mobile/network/RequestRouter.kt` — remove the `isApiMutation` branch (§1). No Laravel/PHP file changes are required for Phase 1 to be correct, because Phase 1 only changes *where* an online mutation is sent (production instead of embedded Laravel) — it does not yet need to change what embedded Laravel does when it *does* receive a mutation (that's Phase 2, for the OFFLINE case which still routes to `LOCAL_PHP` under Rule 5).

Phase 1 verification must specifically check: after the change, an online create/update/delete/upload produces **no new/changed row** in the on-device SQLite `patients`/`patient_notes`/`patient_visits`/`patient_files` tables, since none of those requests reach embedded Laravel anymore. (`WorkspaceController::storePatient`'s Bearer-token-capture branch at `:130` only fires today when `config('database.default')==='sqlite'`, which after Phase 1 only happens for genuinely offline requests — so this becomes moot rather than broken, but worth confirming empirically.)

## 5. Files that must NOT be touched before their phase

- `app/Services/SyncEngineService.php`, `ManualSyncService.php`, `app/Services/Sync/*` (all 7 files), `app/Domains/Sync/Services/SyncQueueService.php`, `app/Services/OfflineUploadService.php`, `app/Repositories/OfflineFileRepository.php` — Phase 5 only. Phase 1/2 leave the offline device still theoretically capable of queuing (dead code path once Phase 2 lands, since nothing will trigger `pending_*` anymore on the routes actually hit) but removing the services themselves before then risks breaking a call graph not yet fully mapped for edge cases (e.g. `RunManualSyncJob`, dashboard sync-stats routes).
- `Api\Mobile\FileController` (class as a whole) — its non-`store` methods are live.
- Anything listed in §3.2 (auth bypasses, AppServiceProvider bootstrapping, category caching, SQLite driver tuning, video/thumbnail job guards, WebView range-capping).
- `sync_states` table and its two live consumers.
- `file_cache` table/`FileCacheRepository` — slated for *adaptation*, not deletion (see `TARGET_ARCHITECTURE.md` §4.2).

## 6. Risks discovered (new, beyond what `TARGET_ARCHITECTURE.md` already flagged)

1. **Two inconsistent write implementations per entity** (Patient/Note/Visit) must both be fixed in Phase 2, or offline behavior will vary depending on which URL the frontend's own (already-unreliable) online guess happened to pick.
2. **`Api\Mobile\PatientController::update()` doesn't call `SyncQueueService::push()`** — an existing bug independent of this rewrite; irrelevant once Phase 2 removes the pending-write branch entirely, but worth knowing it's not a Phase-1-caused regression if noticed during testing.
3. **A second, previously-undocumented stub-patient creator** exists at `Mobile\NoteController::resolvePatient()`, alongside the known one in `ChunkUploadController`. Phase 2's "remove stub-patient behavior" step must cover both.
4. **`useWorkspace.js updatePatient()`'s `navigator.onLine`-based URL branching becomes redundant (not harmful, just pointless) after Phase 1**, since Kotlin's real connectivity check — not the URL chosen — now determines local vs. production. Worth simplifying in Phase 4, not urgent for Phase 1/2 correctness.
5. **Category/reference-data caching is architecturally separate from the sync_queue system** and isn't addressed by the current `TARGET_ARCHITECTURE.md` plan at all — an offline-viewed downloaded patient will need category labels resolved somehow. Needs an explicit decision before Phase 3 is built, not assumed.
6. **`file_cache`'s global LRU eviction** (`FileCacheRepository::ensureQuota()`) is not yet modified/exempted for downloaded packages — confirmed still a global cache today, exactly as `TARGET_ARCHITECTURE.md` §4.2 already flagged; re-confirmed here from source, no new information, but restated because Phase 3 cannot start without resolving it.
7. **Legacy already-offline-pending rows may exist in production devices' local SQLite at upgrade time** (from before this rewrite ships) — Phase 2's "reject instead of queue" change does not itself reconcile or drain any `pending_create`/`pending_update`/`pending_delete` rows already sitting in a real device's local DB. Needs an explicit decision: drain them once via a final legacy sync pass before Phase 2 ships, or accept they're orphaned.

---

## 7. Summary verdict for the questions this phase was asked to answer

- **Actual mutation entry points**: two per entity (workspace/browser-style controller + repository, and a parallel `Api\Mobile\*` controller) — see §2.
- **Actual online routing path**: Kotlin `RequestRouter.kt` Rule 3 (reads, already correct) and Rule 4 (writes, needs the Phase-1 change) — see §1.
- **Actual upload path**: `ChunkUploadController` via `useUploads.js`, always chunked, no threshold — see §2.5. `UploadController`/`UploadService` and `Mobile\FileController::store` are dead for creation; `Mobile\FileController`'s other methods are live and must not be deleted.
- **Actual SQLite usage**: far broader than "sync" — see §3, especially §3.2's do-not-touch list.
- **Files Phase 1 modifies**: one Kotlin file (§4).
- **Files that must not be touched yet**: §5.
- **Risks discovered**: §6.
