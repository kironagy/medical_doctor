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
