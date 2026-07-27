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

