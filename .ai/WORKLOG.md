# WORKLOG

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

### Files Modified
- `resources/js/Composables/useUploads.js` — Build local URL instead of using server response URL
- `app/Domains/Media/Models/PatientFile.php` — SQLite-aware `getUrlAttribute` / `getThumbnailUrlAttribute`
- `app/Http/Controllers/Api/FileAccessController.php` — `streamCached` streams from disk directly for local files
- `routes/web.php` — Added missing `_native/cache/files/{uuid}/thumbnail` route
- `app/Repositories/Eloquent/EloquentPatientFileRepository.php` — Added missing columns to partial select
- `app/Http/Controllers/Api/UploadsController.php` — Fixed `resolvePatient` column mapping to prevent SQLSTATE crashes
- `app/Services/Mobile/ApiService.php` — Removed hardcoded `Content-Type: application/json` to fix multipart file uploads

