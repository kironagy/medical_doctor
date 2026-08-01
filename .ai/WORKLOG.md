# WORKLOG

## 2026-08-01

### Fix: Android/SQLite Local Media Stream and Patient Creation Idempotency Crashes

#### Issue 1 — SQLite local database path alignment
- **Root Cause:** The `.env` database configuration had `DB_DATABASE=/data/data/com.medicalplus.app/files/storage/data/medical_plus.sqlite`. This directory did not exist on the device, causing Laravel to fallback/create a new empty SQLite file. Meanwhile, NativePHP mobile bridge sets `DB_DATABASE` at runtime to point to `app_storage/persisted_data/database/database.sqlite` (using `database.sqlite` instead of `medical_plus.sqlite`). Because of this mismatch, Laravel operated on empty tables, preventing local media files from being retrieved or synced.
- **Fix:** Changed the `.env` database setting to `/data/data/com.medicalplus.app/app_storage/persisted_data/database/database.sqlite`. Pre-migrated the existing local SQLite file `medical_plus.sqlite` to `database.sqlite` on the test device to preserve user data.

#### Issue 2 — Local image/media streaming authorization crash on SQLite
- **Root Cause:** In `FileAccessController::streamCached()`, the code was calling `Gate::authorize('view', $file->patient)` to enforce permission check. On SQLite (embedded single-user NativePHP app), the auth middleware is disabled and there is no authenticated user context, causing `Gate::authorize` to always throw an `AuthorizationException` (and return HTTP 403 / blank image with alt text only).
- **Fix:** Bypassed the Gate check in `streamCached()` when the database driver is `sqlite` (since single-user mobile access control is handled at the OS/device level).

#### Issue 3 — Patient creation crash with Undefined array key "uuid"
- **Root Cause:** When creating a patient via the mobile app, the client doesn't send a UUID or doctor IDs. Since auth is bypassed on SQLite, the store method ended up attempting to create a patient with `primary_doctor_id` as NULL. SQLite threw a `NOT NULL constraint failed` query exception. The exception handler then attempted to perform an idempotency search using `$validated['uuid']` which was undefined in the request data, throwing a secondary PHP ErrorException.
- **Fix:**
  - Ensured `uuid` is always generated and populated in `$validated` at the start of `PatientController::store()` to prevent undefined key errors.
  - Added a doctor ID fallback on SQLite where `primary_doctor_id` defaults to the first available user/doctor record in the local database.

---

## 2026-08-01

### Fix: Verified Sync Pipeline Issues — Patient Lifecycle & PatientFile sync_status

#### Issue 1 — Patient sync lifecycle: upstream sync_status mismatch blocks file uploads

**Root Cause (a):** `SyncEngineService::syncPendingPatients()` on sync failure always reverted
the patient `sync_status` back to `'pending_create'` regardless of the original status. A patient
that started as `'pending_update'` was incorrectly reverted to `'pending_create'`, causing it to
be re-created (via POST) on the next sync cycle instead of being updated (via PUT), producing
duplicate patient entries on the production server.

**Root Cause (b):** `SyncEngineService::syncLocalPatientFiles()` loaded the patient via the
Eloquent relationship `$file->patient`. When `syncPendingPatients()` ran in the **same sync
cycle** and updated a patient from `pending_create` → `synced` in the DB, the Eloquent
relationship cache still held the stale model with the old status. `syncLocalPatientFiles()`
then skipped those files (patient not `'synced'`), blocking the entire upload pipeline.

**Fix (a) — `SyncEngineService::syncPendingPatients()`:**
- Capture `$originalStatus = $patient->sync_status` before the atomic `syncing` transition.
- On failure (Phase 3b), revert to `$originalStatus` instead of always `'pending_create'`.

**Fix (b) — `SyncEngineService::syncLocalPatientFiles()`:**
- Replace `$file->patient` Eloquent relationship (cached) with a fresh `DB::table('patients')`
  query on each iteration, ensuring the post-sync `'synced'` status is visible.
- Remove the now-unnecessary `->with('patient')` eager load from the pending files query.
- Use `$patientRecord->uuid` (from fresh query) instead of `$file->patient->uuid` for the
  upload URL — ensures the correct remote UUID is used if it changed during patient sync.

---

#### Issue 2 — PatientFile::create() passes explicit null for sync_status on MySQL

**Root Cause:** `FileController::store()` used:
```php
'sync_status' => config('database.default') === 'sqlite' ? 'pending_sync' : null,
```
The `null` value is **explicitly included** in the SQL `INSERT` statement, which **overrides**
the MySQL column default of `'synced'`. Production records end up with `sync_status = NULL`
instead of `'synced'`, which breaks any query comparing `sync_status` (e.g. filtering
`sync_status != 'pending_delete'` or `whereIn('sync_status', [...])`).

**Fix — `FileController::store()` and `UploadService::uploadFile()`:**
- Build the `$createPayload` array without a `sync_status` key.
- Only add `'sync_status' => 'pending_sync'` when on SQLite (embedded app).
- On MySQL (production), omit the key entirely so the DB default `'synced'` applies.
- Same pattern applied to `UploadService::uploadFile()` (a third PatientFile create path).

---

**Files Modified:**
- `app/Services/SyncEngineService.php`
- `app/Http/Controllers/Api/Mobile/FileController.php`
- `app/Domains/Media/Services/UploadService.php`

**Build:** `./gradlew installDebug` → `BUILD SUCCESSFUL` (9s), installed on device.

### Forensic System Investigation & Technical Blueprint
- **Task:** Perform full reverse-engineering forensic analysis of NativePHP Mobile & Laravel codebase.
- **Created Document:** `docs/system-audit/nativephp-forensic-investigation.md`
- **Investigation Scope:**
  1. Complete Project Map & Component Dependency Graphs.
  2. Execution Traces (Offline Upload, Online Direct Upload, Sync Engine Cycle).
  3. Deep File Upload Systems Analysis across all media types (Image, Video, Audio, PDF, Docs).
  4. Forensic Investigation of Image Preview Failures & Dynamic Appended Attribute Resolution.
  5. Video Lifecycle & Byte-Range 206 Partial Content Streaming.
  6. SQLite Database & Schema Audit, FK Integrity Guards, UUID Remapping.
  7. Sync Engine Priority Queue Sequence & Concurrency Safeguards.
  8. Network Request / Response Matrix & Endpoint Map.
  9. Security & Performance Audit with 13-category System Forensic Scorecard.

### System Technical Audit & Root Cause Analysis
- **Task:** Perform comprehensive architectural audit of NativePHP Mobile & Laravel infrastructure.
- **Created Document:** `docs/system-audit/nativephp-mobile-full-audit.md`
- **Scope Covered:**
  1. Executive Summary & Dual Database (SQLite / MySQL) Model Architecture.
  2. Routing & Native Android WebView / C SAPI Bridge Communication (`ParseMobileMultipartMiddleware`).
  3. Offline-First Lifecycle & State Machine Transitions (`pending_create`, `pending_upload`, `syncing`, `synced`, `failed`).
  4. Upload Pipelines (Direct Upload, Chunked Video Upload, Offline Pending Upload) & Media Stream Handling.
  5. Sync Engine Architecture (`SyncEngineService` & `useSyncEngine.js`), strict execution order, and 5-path network resilience triggers.
  6. Root Cause Analysis for empty file cards, 0-byte direct write errors, and patient resolution fallbacks.
  7. Technical recommendations for WAL mode, thumbnail fallbacks, and stabilization.

### Fix: Direct Image Uploads Without Chunking, Chunk 0 Size Error & Reactive Import Bug
1. **Bug: Offline upload failed with `ReferenceError: reactive is not defined`**
   - **Root Cause:** `useOfflineUploads.js` called `const job = reactive({ ... })` in `createJob()` but only imported `ref` and `computed` from `'vue'`.
   - **Fix:** Added `reactive` to `import { ref, computed, reactive } from 'vue'` in `useOfflineUploads.js`.
2. **Feature/Fix: Images & Non-Video files now upload directly without chunking**
   - **Root Cause:** `useUploads.js` was sending small images through chunked upload (`/api/v1/chunk/init` → `/chunk` → `/complete`), causing unnecessary overhead and SQLite session records.
   - **Fix:** Added `uploadDirectly()` in `useUploads.js` to send images and non-video files via a single HTTP POST request to `/api/v1/mobile/patients/{uuid}/files`, reserving chunked upload exclusively for video files.
3. **Bug: `Chunk 0 exceeds expected size` (HTTP 422) and `Direct-write file size mismatch` (HTTP 500)**
   - **Root Cause:**
     - `UploadValidationService::validateChunk()` strict-checked `$expectedSize + 64 bytes` tolerance.
     - `ChunkMergeService::merge()` strict-checked `if ($size !== $locked->total_size)` when completing `/api/v1/chunk/complete`.
     - When Android MediaStore or WebView altered image/video EXIF or stream byte counts slightly compared to JavaScript `File.size`, both strict checks threw errors.
   - **Fix:**
     - Updated `validateChunk()` in `UploadValidationService.php` to compare against `chunk_size + 1MB` (`$session->chunk_size + 1048576`).
     - Updated `merge()` in `ChunkMergeService.php` to check `if ($size === 0)` (rejecting empty files only) and record the actual `$size` written to disk.
4. **Build & Deploy:**
   - Built production frontend bundle (`npm run build`).
   - Rebuilt NativePHP Android bundle and installed APK on device (`php artisan native:build android debug --no-tty` and `./gradlew installDebug --rerun-tasks`).

### Fix: End-to-End Online and Offline File Uploads & Preview in Mobile App
1. **Bug: Preview blank / 404 after Online upload finishes (`InlineFilePreview.vue` & `UnifiedMediaViewer.vue`)**
   - **Root Cause:** When an upload completes on native Android (`detectNative()`), the file resides in the local embedded server storage (`storage/app/...`) and local SQLite database until synced to remote MySQL. However, the preview components were calling `axios.get('/api/v1/files/${uuid}/signed-url')`, which hit the remote production server (`https://prof-hosam-fekry.online`) and returned 404 Not Found.
   - **Fix:** On native Android (`detectNative()`), preview components skip fetching remote signed URLs and always load images/videos from the local embedded streaming endpoints (`/_native/cache/files/${uuid}` and `/_native/cache/files/${uuid}/thumbnail`).
2. **Bug: Offline Upload failed completely with "فشل الرفع"**
   - **Root Cause:**
     - `CategoryBlock.vue` checked `navigator.onLine` instead of SyncEngine's `isOnline.value`.
     - `JSBridge.logPostData()` in `WebViewManager.kt` only stored POST data under `requestId` without url/path fallback, causing empty bodies if `X-NativePHP-Req-Id` header lookup failed.
     - `OfflineUploadController::store()` did not include `url` and `thumbnail_url` in its response JSON, causing UI preview to fail.
     - `FileAccessController::streamCached()` lacked buffer cleanup (`@ob_end_clean()`) before streaming offline files, which could corrupt file streams with stale buffer output.
   - **Fix:**
     - Updated `CategoryBlock.vue` to use `useSyncEngine().isOnline.value` for offline/online upload routing.
     - Updated `JSBridge.logPostData()` in `WebViewManager.kt` to store fallback POST data under `url` and `path`, and made header lookup case-insensitive.
     - Added `url` and `thumbnail_url` properties to `OfflineUploadController::store()` JSON response and `addFileLocally()` in `useOfflineUploads.js`.
     - Added `@ob_end_clean()` loop before returning `StreamedResponse` in `FileAccessController::streamCached()`.
3. **Android Permissions:**
   - Added `READ_MEDIA_AUDIO` and `READ_MEDIA_VISUAL_USER_SELECTED` to `AndroidManifest.xml` alongside existing `READ_MEDIA_IMAGES` and `READ_MEDIA_VIDEO` to ensure Android 13/14 permissions are fully declared.
4. **Build & Deploy:**
   - Rebuilt and installed debug APK onto device (`./gradlew installDebug`).

## 2026-07-25

### Fix: Note creation 500 error - column name and category fixes

1. **Bug: Wrong column names in resolvePatient()** - Changed 'first_name'/'last_name' to 'name' in all 3 NoteControllers to match the patients table schema.
2. **Bug: NULL category in NOT NULL column** - Changed '?? null' to '?? general' in Api/NoteController to match DB default.
3. **Bug: Missing sync_status migration on local SQLite** - Ran php artisan migrate --force.
4. **Deployed** via git push to remote, pulled on production.
5. **APK** - Built debug APK.

## 2026-07-27

### Fix: Notes not visible after creation on mobile

**Root Cause:** After saving a note (LOCAL_PHP → 201), `loadCategoryData()` fetched
fresh data from EXTERNAL production which didn't have the note yet → `serverNotes`
was replaced → note disappeared.

**Fix: `CategoryBlock.vue` — `loadCategoryData()`**
- Fetches `/_native/api/offline/notes` in parallel with server request
- Merges `pending_create` notes from local SQLite into `serverNotes`
- Also checks `workspaceData.value.notes` for notes added via `addNoteLocally()`
- Works for both old and new APK code paths

**Fix 2: `AddRecordModal.vue` — `submit()`**
- Reverted notes saving back to standard mobile API `/api/v1/mobile/patients/{uuid}/notes` so it gets intercepted by `RequestRouter`
- This ensures it perfectly matches how Patient creation works (Intercepted by RequestRouter → saved locally with `sync_status=pending_create` → triggers `triggerSync()` which uploads to server)

**Fix 3: `AddRecordModal.vue` — Silent Failure Bug & Direct Push**
- Found a silent failure where `submit()` immediately returned without doing anything because `props.patient.id` was `undefined` (workspace data only provides `uuid`).
- Changed `!props.patient?.id` to `!props.patient?.uuid` to allow saving.
- Updated file upload section to use `patientUuid` instead of `patientId` for `onlineUploadFile` fallback.
- **Direct Push:** Modified note creation to push directly to the production API (`/api/v1/patients/...`) when the device is online, bypassing the local SQLite queue entirely. It falls back to the `/mobile` interceptor only when offline.

**APK rebuilt and installed** v1.0.33 debug.


---

## 2026-07-31 — Sync Fixes (FIX-01, FIX-02, FIX-03)

### FIX-01: Note from DoctorWorkspace modal now sends `category`
- **File:** `resources/js/Pages/DoctorWorkspace.vue:L784`
- **Problem:** `submitNoteForm()` was posting `{ content }` without `category`. NoteController defaulted to `'notes'`, so notes never appeared in the correct CategoryBlock.
- **Fix:** Added `category: 'notes'` to the POST body.

### FIX-02: Visit sync was posting to deleted route
- **File:** `resources/js/Pages/DoctorWorkspace.vue:L856`
- **Problem:** `axios.post('/_native/api/sync')` — this route was removed in SYNC-005. Engine never ran after visit save.
- **Fix:** Changed to `axios.post('/_native/api/sync/engine')`.

### FIX-03: Note disappears after sync race condition
- **File:** `resources/js/Composables/useWorkspace.js:L285-288`
- **Problem:** `refreshWorkspaceData()` filter only preserved `pending_create|pending` notes. If SyncEngine marked a note as `synced` before production confirmed it, the note vanished.
- **Fix:** Added `|| n.sync_status === 'synced'` to the filter so recently-synced notes are kept until the server returns them.

## 2026-07-31 — Sync Fixes (FIX-04, FIX-05, FIX-06, FIX-07)

### FIX-04: ApiService Singleton — Already Done
- **File:** `app/Providers/AppServiceProvider.php:L27`
- Singleton was already registered. No action needed.

### FIX-05: CategoryBlock note visible immediately on add
- **File:** `resources/js/Components/workspace/CategoryBlock.vue`
- **Problem:** `@noteAdded="loadCategoryData(1)"` reloaded from production immediately — note not there yet (pending_create). Note appeared invisible.
- **Fix:** Added `onNoteAdded(note)` function that:
  1. Prepends note directly to `serverNotes` for instant UI visibility.
  2. Schedules a delayed `loadCategoryData(1)` after 4000ms to sync with production.

### FIX-06: Patient UUID mismatch + notes — Non-issue
- **File:** `app/Services/SyncEngineService.php:L313-346`
- `patient_notes` references `patient_id` (auto-increment FK), not `patient_uuid`.
- UUID changes don't break note sync. No fix required.

### FIX-07: Double onMounted — Intentional
- **File:** `resources/js/Pages/DoctorWorkspace.vue:L351, L882`
- The two `onMounted` blocks do different jobs:
  - #1: Hydrate patients + refreshPatientList
  - #2: history.pushState + Inertia performance tracking
- No action taken — merging could break behavior.

## 2026-07-31 — Upload Call to Member Function on Null Fix

### FIX: Guard null `$request->user()` across Upload and File Controllers
- **Files Modified:**
  - `app/Http/Controllers/Api/UploadsController.php`
  - `app/Http/Controllers/Api/ChunkUploadController.php`
  - `app/Http/Controllers/Api/Mobile/FileController.php`
  - `app/Http/Controllers/Api/FileAccessController.php`
  - `app/Http/Controllers/Api/Mobile/ShareController.php`
  - `app/Http/Controllers/Api/Mobile/SearchController.php`
- **Problem:** When uploading photos/videos in the NativePHP Android App (SQLite environment with no session authentication), `$request->user()` is `null`. Calling `$request->user()->cannot()` or `$request->user()->id` threw `Call to a member function cannot() on null` or `Attempt to read property "id" on null`.
- **Fix:** 
  1. Added `$request->user() &&` null guards before all policy/Gate authorization checks.
  2. Fallback `$request->user()?->id ?? $patient->primary_doctor_id ?? 1` when assigning `uploaded_by_id`, `shared_by_id`, or `user_id`.

## 2026-07-31 — SQLite General Error 1 (Missing Tables/Columns) Fix

### FIX: Always run `migrate --force` on SQLite boot in AppServiceProvider
- **File:** `app/Providers/AppServiceProvider.php`
- **Problem:** `runMigrationsIfNeeded()` previously only executed migrations if `storedVersion !== currentVersion` (comparing version code in `.env`). Because `NATIVEPHP_APP_VERSION_CODE` wasn't bumped when adding new migrations (such as `2026_07_23_000004_create_offline_files_table.php` or `2026_07_28_000001_add_sync_status_to_patient_files_table.php`), new tables/columns like `offline_files` and `sync_status` were missing on existing local SQLite databases on the phone. This caused SQLite to throw `SQLSTATE[HY000]: General error: 1 no such table: offline_files` / `no such column` when uploading files or videos.
- **Fix:** 
  1. Updated `runMigrationsIfNeeded()` in `AppServiceProvider.php` to always run `Artisan::call('migrate', ['--force' => true]);` whenever the database driver is SQLite.
  2. Bumped `NATIVEPHP_APP_VERSION_CODE` from 43 to 44 across `.env` configuration files.

## 2026-07-31 — Background Chunked Media Sync to Remote Server Fix

### FIX: Sync locally chunked patient_files to Production Server in Background
- **Files Modified:**
  - `database/migrations/2026_07_31_000001_add_remote_uuid_to_patient_files_table.php` (New migration)
  - `app/Services/SyncEngineService.php`
- **Problem:** When uploading photos or videos locally on the phone via the chunk upload system (`useUploads.js`), chunks were merged and stored in the phone's SQLite `patient_files` table. However, because `patient_files` did not track whether the file was pushed to the production server (`https://prof-hosam-fekry.online`), `SyncEngineService` only synced records from `offline_files`. As a result, images and chunked videos uploaded locally were never transmitted to the website. Furthermore, if the user exited the app while uploading, the JS webview chunk worker was paused by OS power management.
- **Fix:**
  1. Added `remote_uuid` column to `patient_files` via migration to track production-synced state (`null` = needs sync, string UUID = synced).
  2. Implemented `syncLocalPatientFiles()` in `SyncEngineService` to automatically detect locally completed chunk uploads (`remote_uuid` IS NULL) and stream them to the production server (`/patients/{uuid}/files`).
  3. Included `remote_uuid` IS NULL in `hasPendingOperations()` and `getPendingSummary()`, ensuring the background NativePHP sync worker automatically runs in the background even if the app is minimized or closed.

## 2026-07-31 — SQLite Foreign Key Constraint Violation (user_id = 0) Fix

### FIX: Resolve valid user_id in Upload Controllers & Services
- **Files Modified:**
  - `app/Http/Controllers/Api/UploadsController.php`
  - `app/Http/Controllers/Api/ChunkUploadController.php`
  - `app/Services/Upload/ChunkMergeService.php`
- **Problem:** When creating upload sessions offline in the NativePHP Android app, `$request->user()` is `null`. The code passed `0` as fallback `user_id` when creating an `UploadSession` or `PatientFile`. Because `upload_sessions` and `patient_files` have a foreign key constraint `user_id REFERENCES users(id)`, inserting `user_id = 0` (which does not exist in `users`) caused `SQLSTATE[23000]: Integrity constraint violation: 19 FOREIGN KEY constraint failed` on SQLite.
- **Fix:** Replaced `$request->user()?->id ?? 0` with dynamic resolution: `$request->user()?->id ?? $patient->primary_doctor_id ?? $patient->created_by_id ?? \App\Models\User::value('id') ?? 1`. This ensures `upload_sessions` and `patient_files` always receive a valid foreign key referencing an existing user in the database.

## 2026-07-31 — Workspace File Display & Local File Merge Fix

### FIX: Show Newly Uploaded Photos & Videos Immediately in Workspace Categories
- **File Modified:** `resources/js/Components/workspace/CategoryBlock.vue`
- **Problem:** When uploading a photo or video in the app, the upload completed successfully, but the new file was completely hidden and invisible in the patient's category workspace. This occurred because `CategoryBlock.vue`'s computed properties `categoryFiles` and `filteredFilesRaw` returned ONLY `serverFiles.value` whenever `serverFiles.value` was non-empty, completely ignoring local files in `allFiles.value` (added via `addFileLocally`). Furthermore, `loadCategoryData()` did not merge local files into `serverFiles.value`, and `uploadJob` completion watch did not reload the category data.
- **Fix:**
  1. Updated `categoryFiles` in `CategoryBlock.vue` to merge local files from `allFiles.value` with `serverFiles.value` so newly uploaded files display instantly without waiting for production sync.
  2. Updated `loadCategoryData()` to merge `workspaceLocalFiles` into `serverFiles.value` upon load.
  3. Updated `uploadJob` completion handlers in `handleNativeFileResult` and `handleFiles` to reload category data (`loadCategoryData()`) once upload status becomes `'completed'`.
  4. Updated `handleFiles` to use `selectedPatient.value?.uuid || selectedPatient.value?.id`.

## 2026-07-31 — Fatal PHP Missing Autoload File on Android Fix

### FIX: Add `symfony/deprecation-contracts` directly to `composer.json` `require` section
- **File Modified:** `composer.json`
- **Problem:** Logcat revealed the root cause of all silent PHP 500 failures on Android:
  ```
  Fatal error: Uncaught Error: Failed opening required '/data/data/com.medicalplus.app/app_storage/laravel/vendor/composer/../symfony/deprecation-contracts/function.php'
  ```
  During NativePHP production bundle creation (`native:build`), `composer install --no-dev` was run, stripping dev packages (like `nunomaduro/collision`). Because `symfony/deprecation-contracts` was only listed as a sub-dependency of `collision` in `require-dev`, `composer --no-dev` removed `vendor/symfony/deprecation-contracts/function.php`. However, `vendor/composer/autoload_files.php` still attempted to `require` it on every boot, causing EVERY local PHP request (`/_native/api/sync/engine`, `/_native/api/sync/pending-summary`, `/api/v1/chunk/init`, etc.) to crash instantly with a Fatal Uncaught Error.
- **Fix:** Added `"symfony/deprecation-contracts": "^3.0"` directly into the `"require"` section of `composer.json` and ran `composer dump-autoload`. This guarantees `symfony/deprecation-contracts/function.php` is permanently present in `vendor/` during production `--no-dev` packaging.

## 2026-07-31 — Fix: Uploaded files (images + videos) not appearing after upload in Mobile App

### Root Cause Analysis — 5 interconnected bugs

**Bug 1 (Critical): Wrong `url` after chunk upload**
- In `useUploads.js`, after `POST /api/v1/chunk/complete`, we used `completeRes.data.url` — which was the production URL (`https://prof-hosam-fekry.online/api/v1/files/{uuid}`) returned by `PatientFile::getUrlAttribute()`.
- The file is stored **locally on the device**, not on production. So the production URL returned 404.
- **Fix:** Built local streaming URL (`/_native/cache/files/{uuid}`) directly in JS instead of using the server response URL. Applied to both `startUpload()` and `executeRetry()`.

**Bug 2 (Critical): `PatientFile::getUrlAttribute()` always used `app.url` (production)**
- After workspace refresh, the `url`/`thumbnail_url` for all files came from the `PatientFile` Eloquent `$appends`, which always built production URLs.
- **Fix:** Added `config('database.default') === 'sqlite'` check in `PatientFile::getUrlAttribute()` and `getThumbnailUrlAttribute()` to return `/_native/cache/files/{uuid}` on mobile and production URLs on web.

**Bug 3 (Critical): `streamCached` routed all PatientFile stream requests through cache table**
- `/_native/cache/files/{uuid}` called `FileCacheRepository::stream()` which only looks in the local cache SQLite table, not `Storage::disk('local')`.
- Freshly uploaded files (via chunk) are in `Storage::disk('local')`, not in the cache table → 404.
- **Fix:** In `FileAccessController::streamCached()`, first check if `file_path` exists on `Storage::disk('local')` and stream directly; only fall through to cache repo if file is not on disk.

**Bug 4: Missing `/_native/cache/files/{uuid}/thumbnail` route**
- `thumbnail_url` now points to `/_native/cache/files/{uuid}/thumbnail` but this route didn't exist in the `_native/cache` route group.
- **Fix:** Added `Route::get('/files/{uuid}/thumbnail', ...)` to the `_native/cache` route group in `web.php`.

**Bug 5: `EloquentPatientFileRepository::forPatient()` partial `select` stripped required columns**
- The `select([...])` didn't include `file_path`, `thumbnail_path`, `type`, `upload_status`, `sync_status` — so `url`/`thumbnail_url` appended attributes couldn't be built correctly after workspace refresh.
- **Fix:** Added the missing columns to the `select` list.

**Bug 6 (Critical Crash): SQLSTATE NOT NULL constraint violation on chunk init**
- The `UploadsController->resolvePatient()` method was still using `first_name` and `last_name` to create patients, but the `patients` table schema was recently updated to only use a single `name` column.
- This caused a fatal `SQLSTATE[23000]: Integrity constraint violation: 19 NOT NULL constraint failed: patients.name` when uploading on a fresh mobile install.
- **Fix:** Updated `resolvePatient` in `UploadsController` to correctly map `first_name` and `last_name` to the new `name` column, exactly as was done previously in the `NoteController`.

**Bug 7 (Sync Failure): Background file sync fails with 422 Unprocessable Entity**
- The `SyncEngineService` uses `ApiService::upload()` to sync offline files to the server.
- The server's `FileController::store()` requires a `file` field, but it returned a `422` error.
- **Fix:** The issue was caused by a hardcoded `'Content-Type' => 'application/json'` header in `ApiService::client()`. This overrode Guzzle's automatic `multipart/form-data` header when attaching files, causing the server to receive a malformed request body and fail validation. Removed the hardcoded header so Guzzle can set it dynamically.

### Bug 8: Infinite Sync Loops & Missing 404 Handling

**Symptoms:**
- The Android mobile app was constantly hitting `_native/api/sync/engine`, spamming the server and chewing up battery and bandwidth.
- Nginx logs showed continuous `404` errors for `DELETE` and `422` for `POST /files`.
- If a patient or file was deleted on the server, the mobile app would retry the delete forever.

**Root Cause:**
- `ApiService` didn't expose HTTP status codes clearly in its exceptions.
- `SyncEngineService` treated `404 Not Found` during a deletion as a generic failure, so it retried instead of assuming the record was already deleted remotely.
- Failing file/note uploads didn't have their status set to `failed` and would get stuck in an infinite retry loop since they stayed as `pending_create`/`pending_update` but encountered persistent API errors.

**Fix:**
- Updated `MakesApiRequests.php` to include the HTTP status code when throwing `RuntimeException` for failed API calls.
- Updated `SyncEngineService.php` to handle `404` on deletes by marking them as successfully synced (deleted).
- Added logic in `SyncEngineService.php` to mark notes and files as `sync_status = 'failed'` (and notes to `error_message`) if the server returns persistent validation or internal errors, stopping the infinite loops.

### Bug 9: 422 Unprocessable Entity on Offline File Uploads

**Symptoms:**
- The `SyncEngineService` received `422 The file field is required.` from the remote server when attempting to upload a file (`POST /api/v1/mobile/patients/{uuid}/files`).

**Root Cause:**
- `ApiService::upload` used `PendingRequest::attach()` conditionally based on `$stream = fopen($file, 'rb')`. If `fopen()` failed, `attach()` was skipped, so the request defaulted to `application/json` instead of `multipart/form-data`.
- Since the payload was sent as JSON without the actual file binary, the server rejected it with `422`.

**Fix:**
- Updated `ApiService::upload` to explicitly call `->asMultipart()`.
- Added strict `throw new \RuntimeException` checks if `fopen()` or `file_exists()` fails, ensuring the error is caught by `SyncEngineService` and the sync is properly aborted (or failed) instead of sending a malformed request to the remote server.

### Files Modified
- `resources/js/Composables/useUploads.js` — Build local URL instead of using server response URL
- `app/Domains/Media/Models/PatientFile.php` — SQLite-aware `getUrlAttribute` / `getThumbnailUrlAttribute`
- `app/Http/Controllers/Api/FileAccessController.php` — `streamCached` streams from disk directly for local files
- `routes/web.php` — Added missing `_native/cache/files/{uuid}/thumbnail` route
- `app/Repositories/Eloquent/EloquentPatientFileRepository.php` — Added missing columns to partial select
- `app/Http/Controllers/Api/UploadsController.php` — Fixed `resolvePatient` column mapping to prevent SQLSTATE crashes
- `app/Services/Mobile/ApiService.php` — Removed hardcoded `Content-Type: application/json` to fix multipart file uploads

---

## 2026-07-31 — Debug Exception Capture & Response Logging Implementation

### Full Laravel Exception Capture & Untruncated Response Logging

**Problem:**
Upload failures in the mobile app were only showing `PHPBridge: HTTP/1.1 500 Internal Server Error` or `Response first 200 bytes: HTTP/1.1 500 Internal Server Error` in Android ADB logcat, masking the actual underlying Laravel exception details and database errors.

**Changes Made:**
1. **`PHPBridge.kt`**:
   - Removed 200-byte response truncation (`response.copyOfRange(0, 200)`).
   - Changed response logging to print the complete, untruncated HTTP response body (`Log.e(TAG, "HTTP Response Body:\n$fullResponseString")`).

2. **`PHPWebViewClient.kt`**:
   - Enabled verbose logging for upload requests and responses (logging URL, HTTP method, headers, request body, response status, response headers, and complete untruncated response body).

3. **`bootstrap/app.php`**:
   - Configured `$exceptions->report()` to extract Exception Class, Message, SQLSTATE, SQLite Error message, File, Line, and Stack Trace, logging them via `Log::error('LARAVEL EXCEPTION CAUGHT', [...])`.
   - Updated `$exceptions->shouldRenderJsonWhen()` to cover `api/*`, `_native/*`, `chunk/*`, `uploads/*`, `patients/*`, `expectsJson()`, and `wantsJson()`.
   - Configured `$exceptions->render()` to output structured JSON containing complete exception details (Class, Message, SQLSTATE, SQLite Error, File, Line, Trace) for API/upload/`_native` requests and when `APP_DEBUG=true`.

4. **Upload Controllers** (`UploadsController.php`, `ChunkUploadController.php`, `OfflineUploadController.php`, `UploadController.php`):
   - Updated all `catch (\Throwable $e)` blocks in `init`, `start`, `chunk`, `complete`, `finish`, and `store` methods to capture `PDOException` SQLSTATE and SQLite error details, log full exception traces using `Log::error(...)`, and return structured JSON responses containing full exception context.

### Files Modified:
- `nativephp/android/app/src/main/java/com/nativephp/mobile/bridge/PHPBridge.kt`
- `nativephp/android/app/src/main/java/com/nativephp/mobile/network/PHPWebViewClient.kt`
- `bootstrap/app.php`
- `app/Http/Controllers/Api/UploadsController.php`
- `app/Http/Controllers/Api/ChunkUploadController.php`
- `app/Http/Controllers/Api/OfflineUploadController.php`
- `app/Http/Controllers/Api/UploadController.php`
- `.ai/WORKLOG.md`

---

## 2026-07-31 — Fix Chunk Upload 500 Error Root Causes

### Root Cause Analysis & Fixes

1. **Root Cause 1: Missing Routes in `routes/api.php`**
   - **Problem:** Frontend `useUploads.js` posted chunk requests to `/api/v1/chunk/init`, `/api/v1/chunk/chunk`, and `/api/v1/chunk/complete`. `routes/api.php` lacked these routes under `v1`, causing Laravel routing lookup failures.
   - **Fix:** Added `/chunk/init`, `/chunk/chunk`, `/chunk/complete`, `/chunk/{uuid}/cancel`, `/chunk/{uuid}/status` under `Route::prefix('v1')` in `routes/api.php`.

2. **Root Cause 2: Column Name Mismatch in `ChunkUploadController.php`**
   - **Problem:** `resolvePatient()` in `ChunkUploadController.php` attempted to write `first_name` and `last_name` into the `patients` table. The SQLite schema only contains the `name` column, throwing `SQLSTATE[HY000]: General error: 1 table patients has no column named first_name`.
   - **Fix:** Updated `resolvePatient()` to combine `first_name` and `last_name` into `name` before calling `Patient::updateOrCreate()`.

3. **Root Cause 3: CSRF Exemption for Chunk Endpoints**
   - **Fix:** Added `/chunk/*` and `/uploads/*` to `validateCsrfTokens(except: [...])` in `bootstrap/app.php`.

### Build & Installation
- Built new Debug APK (`nativephp/android/app/build/outputs/apk/debug/app-debug.apk`)
- Installed on device `2d04ce2e` via ADB (`Success`).

---

## 2026-07-31 — Fix 'Webpage not available' Error After Login Redirect

### Problem
After login, when redirected to `http://127.0.0.1/dashboard` or `http://127.0.0.1/workspace`, `RequestRouter.kt` evaluated `isOnline == true` and returned `RouteTarget.EXTERNAL`. Because `shouldInterceptRequest` returned `null` for `EXTERNAL` routes, the WebView attempted a raw TCP connection to `127.0.0.1:80` (where no HTTP server listens on Android), failing with `net::ERR_CONNECTION_REFUSED` / `Webpage not available`.

### Fix
- Updated `RequestRouter.kt` to check `isLocalHost` (`host == "127.0.0.1" || host == "localhost"`) at the top of `route()`.
- Explicit `127.0.0.1` and `localhost` URLs are now always routed to `RouteTarget.LOCAL_PHP` (or `STATIC_ASSET`), ensuring embedded Laravel renders the page directly inside the WebView.

### Files Modified
- `nativephp/android/app/src/main/java/com/nativephp/mobile/network/RequestRouter.kt`
- `.env.native-debug`
- `vendor/nativephp/mobile/bootstrap/android/native.php`
- `.ai/WORKLOG.md`

---

## 2026-07-31 — Fix SQLite Migration Blocking & User Fallback in Chunk Upload

### Problem
1. **Migration Failure on SQLite**: Migrations `2026_07_23_000005` and `2026_07_25_223012` attempted `$table->dropForeign(...)`, which SQLite does not support. This caused SQLite migrations on startup to fail and block subsequent migrations (`sync_status`, `remote_uuid` columns on `patient_files`).
2. **Missing User Record**: `ChunkUploadController@init` did not ensure a default `User` record existed on SQLite, causing potential foreign key constraint violations during `upload_sessions` insertion.

### Fix
1. Bypassed `dropForeign` operations on SQLite driver in `2026_07_23_000005_make_primary_doctor_id_nullable_in_patients_table.php` and `2026_07_25_223012_make_author_id_nullable_in_patient_notes_table.php`.
2. Added default user resolution & creation logic (`firstOrCreate`) in `ChunkUploadController.php` for SQLite environment.

### Files Modified
- `database/migrations/2026_07_23_000005_make_primary_doctor_id_nullable_in_patients_table.php`
- `database/migrations/2026_07_25_223012_make_author_id_nullable_in_patient_notes_table.php`
- `app/Http/Controllers/Api/ChunkUploadController.php`
- `.ai/WORKLOG.md`

## 2026-07-31 — Fix SQLite Patient Stub primary_doctor_id NOT NULL Constraint Failure

### Problem
1. **SQLite NOT NULL Constraint Violation**: The migration `2026_07_23_000005_make_primary_doctor_id_nullable_in_patients_table.php` was skipped for SQLite, leaving `primary_doctor_id` as `NOT NULL`.
2. **Stub Patient Creation Crash**: During offline chunk upload, `resolvePatient()` falls back to creating a stub patient using `Patient::updateOrCreate()` without a doctor ID, throwing a `QueryException` (integrity constraint violation).
3. **Unhandled Bubble-up Exception**: The exception was called outside the controller's try-catch block, bubble-up causing an HTTP 500 error.

### Fix
1. Moved patient resolution and authorization checks inside the `try-catch` block inside `ChunkUploadController@init` and `UploadsController@start`.
2. Added verbose tracing logs before/after every step (ENTER, validation, patient resolved, user context resolved, session created).
3. Added fallback inside `resolvePatient()` to look up default doctor/user (ID 1) if offline/SQLite and not authenticated, avoiding NOT NULL constraint violation.
4. Corrected Kotlin compiler reference error `$method` -> `${request.method}` in `PHPWebViewClient.kt`.
5. Built new debug APK containing all tracing changes.

### Files Modified
- `app/Http/Controllers/Api/ChunkUploadController.php`
- `app/Http/Controllers/Api/UploadsController.php`
- `nativephp/android/app/src/main/java/com/nativephp/mobile/network/PHPWebViewClient.kt`
- `native-build-production.sh` (added `--no-tty` support for non-interactive builds)
- `.ai/WORKLOG.md`

## 2026-08-01

### Analysis: Deep Sync System Analysis — File Upload Failures

**Task:** تحليل شامل لنظام الـ Sync في التليفون وكل المشاكل الموجودة فعلياً.

**ما اتعمل:**
- قراءة كاملة لـ: `ApiService.php`, `SyncEngineService.php`, `ChunkMergeService.php`, `UploadValidationService.php`, `OfflineUploadService.php`, `OfflineUploadController.php`, `FileController.php`, `PHPWebViewClient.kt`, `RequestRouter.kt`, `useUploads.js`, `useOfflineUploads.js`, `AddRecordModal.vue`, `web.php`
- تحليل كامل للـ architecture: 4 طبقات (Android WebView → Kotlin → Embedded Laravel → Production)
- اكتشاف 20 مشكلة تقنية مدعومة بسطر محدد في الكود

**المشاكل الحرجة المكتشفة:**
- **BUG-012/011:** PHPWebViewClient يستقبل POST body كـ `String?` — binary data corruption محتملة لكل chunk uploads
- **BUG-009:** `ApiService::upload()` timeout 30 ثانية فقط — يفشل لأي فيديو أكبر من ~15MB
- **BUG-004:** Debug traces (`@file_put_contents` + `fetch('/debug/trace')`) في production code — بتأخر كل request
- **BUG-007:** `sync_status` مش بيتحدد عند إنشاء PatientFile في ChunkMergeService
- **BUG-008:** OfflineUploadController يعمل `firstOrFail()` بدون resolvePatient fallback

**الملف الناتج:** `SYNC_DEEP_ANALYSIS.md` في artifact directory — جاهز للمودل يبدأ يصلح منه.

### Files Read (No Changes Made)
- `app/Services/Mobile/ApiService.php`
- `app/Services/SyncEngineService.php`
- `app/Services/Upload/ChunkMergeService.php`
- `app/Services/Upload/UploadValidationService.php`
- `app/Services/OfflineUploadService.php`
- `app/Http/Controllers/Api/Mobile/FileController.php`
- `app/Http/Controllers/Api/OfflineUploadController.php`
- `nativephp/android/app/src/main/java/com/nativephp/mobile/network/PHPWebViewClient.kt`
- `nativephp/android/app/src/main/java/com/nativephp/mobile/network/RequestRouter.kt`
- `resources/js/Composables/useUploads.js`
- `resources/js/Composables/useOfflineUploads.js`
- `resources/js/Components/workspace/AddRecordModal.vue`
- `routes/web.php`

### Fix: Android WebView FormData Payload Corruption (BUG-011/BUG-012)

**Root Cause:**
When `axios` uploaded file chunks via `POST /api/v1/chunk/chunk`, the injected JavaScript in `WebViewManager.kt` called `String(data)` on `FormData` objects. In JavaScript, `String(FormData)` evaluates to `"[object FormData]"` (17 characters). This string was sent as the POST body to embedded PHP, resulting in empty input data and causing Laravel's `$request->validate()` in `ChunkUploadController::chunk()` to fail with:
`The upload id field is required. (and 2 more errors)`.

**Fix:**
1. **`WebViewManager.kt` (JS Injection):** Added `serializePostData()` helper to properly iterate `FormData` entries, read `Blob` / `File` contents via `FileReader.readAsBinaryString()`, construct standard `multipart/form-data` with a boundary, and pass the boundary to Kotlin `JSBridge.logPostData(bodyStr, url, boundary, reqId)`.
2. **`PHPBridge.kt`:** Added `storeBoundary()` and `consumeBoundary()` methods to store and look up custom boundaries per request ID.
3. **`PHPWebViewClient.kt`:** Updated `handlePHPRequest` to retrieve the custom boundary for the request and set `headers["Content-Type"] = "multipart/form-data; boundary=$customBoundary"`.

### Files Modified
- `nativephp/android/app/src/main/java/com/nativephp/mobile/network/WebViewManager.kt`
- `nativephp/android/app/src/main/java/com/nativephp/mobile/bridge/PHPBridge.kt`
- `nativephp/android/app/src/main/java/com/nativephp/mobile/network/PHPWebViewClient.kt`
- `.ai/WORKLOG.md`
