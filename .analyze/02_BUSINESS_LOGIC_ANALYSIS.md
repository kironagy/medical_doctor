# Business Logic Analysis

## 1. CREATE PATIENT WORKFLOW

### Flow Diagram
```
User submits form → addPatient(formData) [Vue] → POST /api/v1/workspace/patients [HTTP]
    → WorkspaceController.storePatient() → $this->patientRepo->create()
        → HybridPatientRepository::create()
            → if online: ApiPatientRepository::create() → API → MySQL
                → syncLocalCache(apiData) → SQLite updateOrCreate
            → if offline/API fails: EloquentPatientRepository::create() → SQLite insert
                → SyncQueueService::enqueueOperation('Patient', 'create', ...)
                → Observer: PatientNoteObserver fires (none for Patient!)
            → return data
    → response: { patient: {...}, message: '...' }
→ addPatient() in Vue:
    → upsertPatient(patient) → patients.value prepend
    → selectedPatientId = patient.uuid
    → workspaceData = { patient, files:[], notes:[], ... }
```

### Problems

**P1. Duplicate Sync Enqueue (Offline)**
When offline, `HybridPatientRepository::create()` enqueues a sync operation. But there's NO PatientObserver to also enqueue. So it works — only the HybridRepo enqueues.
**BUT**: When the API is online but fails (auth error, timeout), the same path runs. Then the observer fires on the Eloquent `create()`. No PatientObserver exists, so no double-queue.
**Result**: Works correctly by accident (missing PatientObserver).

**P2. Code Generation Race**
```php
do {
    $validated['code'] = (string) random_int(100000, 999999);
} while (Patient::where('code', $validated['code'])->exists());
```
This checks both local SQLite AND MySQL (depending on DoctorIsolationScope). But if two doctors create patients simultaneously, they get the same code. No DB-level unique constraint on the SQLite side until migration `2026_07_21_200301_add_unique_index_to_patients_code.php`. Server-side doesn't enforce uniqueness either (the API might accept duplicate codes).

**P3. UI Creates Patient Without API Confirmation**
`addPatient()` in useWorkspace immediately:
1. Calls POST /api/v1/workspace/patients
2. If 200: adds patient to local list, sets as selected
3. **No rollback** if patient is saved to SQLite but API call fails

If the patient is created in SQLite but the API call returns an error, the user sees a success response but the data never syncs. The sync_queue item will retry up to 5 times, then becomes `permanently_failed` with NO user notification.

**P4. Missing Proper Error Feedback**
`addPatient()` returns `{ success: false, errors }` only for validation errors. Network errors, server errors, etc. are caught silently and returned as generic failure. User gets no specific guidance.

---

## 2. UPDATE PATIENT WORKFLOW

### Problems

**P1. Optimistic Update Without Revert**
```javascript
// updatePatient() in useWorkspace
patients.value[localIdx] = { ...patients.value[localIdx], ...formData };
refreshWorkspaceData();
```
The local list is updated immediately. If the API call fails, the stale data is already shown. `refreshWorkspaceData()` on error preserves the old workspaceData, but the patient list already has the new (unconfirmed) data. Inconsistent state.

**P2. Race: refreshWorkspaceData During Edit**
`updatePatient()` calls `refreshWorkspaceData()` after update. But `refreshWorkspaceData()` has a dedup guard — if another refresh is in progress, it returns the in-progress promise. If two updates happen quickly, the second update's data might be overwritten by the first refresh.

**P3. No Sync Queue for Full Updates**
When `HybridPatientRepository::update()` runs:
1. Always saves to local SQLite first
2. If online: tries API → saves API response to SQLite
3. If API fails: enqueues sync for the update

**But**: Step 2 overwrites local data with API response. If the API response is stale (another doctor updated between steps 1 and 2), the local update is lost.

---

## 3. DELETE / ARCHIVE PATIENT WORKFLOW

### Problems

**P1. No Cascade to Child Records**
When a patient is archived/deleted:
```php
// HybridPatientRepository::delete()
$this->localRepo->delete($uuid);  // Soft deletes patient
// If online: API delete
// If offline: enqueue sync
```
**No cascade**: Files, notes, and visits are NOT deleted or enqueued for sync. On the next metadata sync, `FullSyncService::syncMetadataOnly()` will try to fetch files/notes/visits for this patient from the remote API. Since the patient is deleted locally, files/notes/visits have no parent and fail to sync. But the soft-deleted patient records remain orphaned.

**P2. Archive Shows in Patient List**
After archiving, `refreshPatientList()` is called. But the patient list endpoint returns `Patient::latest()` (not `onlyTrashed()`). If the API still has the patient (not yet synced the delete), it re-appears in the list on next sync.

---

## 4. FILE UPLOAD WORKFLOW

### Problems

**P1. File Upload Bypasses HybridRepo**
The mobile `FileController::store()` directly calls `PatientFile::create()` on the local model. It does NOT use the HybridPatientFileRepository. This means:
- No API-first upload (file is saved locally only)
- No sync queue enqueue in the controller
- **BUT**: PatientFileObserver::created() fires and enqueues sync

**Result**: File writes go directly to SQLite + sync queue. The uploaded file is only synced to the remote API when the background sync processes the queue. This works but means files are always "offline-first" for upload — they never get the API's authoritative response back.

**P2. No Server Response Merged**
Because the upload goes local-first and queue-syncs later, the API response (which may include server-generated URLs, processed thumbnails, etc.) is never merged back. The frontend shows local-only data until the next metadata sync pulls fresh data from the API.

**P3. Chunked Upload Complexity**
The application has chunked upload support (UploadSession, chunk receipts) but the mobile FileController uses a simple `storeAs()`:
```php
$path = $uploadedFile->storeAs("patients/{$uuid}", "{$fileUuid}.{$extension}", 'local');
```
The chunked upload infrastructure exists but isn't used by the primary file upload path.

---

## 5. NOTE CRUD WORKFLOW

### Problems

**P1. Note Controller Bypasses Repository**
The `NoteController` (mobile) directly:
```php
$note = PatientNote::create([...]);
```
It does NOT use `PatientNoteRepositoryInterface`. This means:
- No HybridRepo logic
- No API-first create when online
- Observer fires for sync queue

**P2. Vue UI Self-Sabotage for Offline Notes**
In `DoctorWorkspace.vue`, the `submitNoteForm()` function has a fallback for offline:
```javascript
if (!navigator.onLine || e?.code === 'ERR_NETWORK'...) {
    // Retry the same axios call again???
    await axios.post(...)  // Same call that just failed!
}
```
**This is an infinite retry**: When the first axios call fails with network error, the catch block retries the EXACT same call. If still offline, it fails again, and the catch block doesn't re-trigger the offline fallback — it goes to the `else` branch and shows error toast. The offline fallback LITERALLY NEVER WORKS for actual offline scenarios because the first axios call will throw before the retry.

---

## 6. SELECT / VIEW PATIENT WORKFLOW

### Problems

**P1. 50-File Limit with No Pagination**
```php
// WorkspaceController::patientData()
$allFiles = $this->fileRepo->forPatient($uuid);
$files = array_slice($allFiles, 0, 50);
```
50 files returned regardless of actual count. No "load more" on frontend. Files beyond 50 are invisible.

**P2. Four Sequential DB Queries on Every Patient Select**
```php
$patient = $this->patientRepo->findByUuid($uuid);  // Query 1
$allFiles = $this->fileRepo->forPatient($uuid);      // Query 2
$notes = $this->noteRepo->forPatient($uuid);          // Query 3
$visits = $this->visitRepo->forPatient($uuid);        // Query 4
```
Each query is sequential. Timing logs show `repo_files_ms`, `repo_notes_ms`, `repo_visits_ms` separately. Could be parallelized.

**P3. Merge Logic on Every Patient Select**
`selectPatient()` in useWorkspace checks for locally-added items and merges them:
```javascript
if (locallyAddedFileUuids.size > 0 && serverData?.files) { ... merge ... }
if (locallyAddedNoteUuids.size > 0 && serverData?.notes) { ... merge ... }
```
This merge happens EVERY time a patient is selected. If a patient has many locally-added files, this array manipulation happens on every open.

---

## 7. SEARCH PATIENT WORKFLOW

### Problems

**P1. Search Does Not Paginate**
`Patient::search()` returns ALL matching patients without pagination:
```php
public function search(string $term): array
{
    return Patient::where('name', 'like', "%{$term}%")
        ->orWhere(...)
        ->latest()->get()->toArray();
}
```
For a database with thousands of patients, this returns unbounded results.

**P2. Search Only in Patient List**
The frontend `filteredPatients` computed property filters `patients.value` client-side:
```javascript
const filteredPatients = computed(() => {
    if (!searchQuery.value) return patients.value;
    return patients.value.filter(p => ...);
});
```
This only searches the LOADED patients (max 10 per page). It does NOT search the API. If a patient has a matching name but is on page 3, it won't appear in search results.

---

## 8. AUTH & SESSION WORKFLOW

### Problems

**P1. Token Persistence with Multiple Fallback Sources**
```javascript
// ApiService constructor:
// 1. Try session('api_token_raw')
// 2. Try loadTokenFromDb() (sync_states table)
// 3. If found in DB, restore to session
```
Tokens can be in session OR database. When a token expires:
1. ApiService clears it from both session and DB
2. But the full sync might have a different token (also stored in API service singleton)
3. Multiple tokens can coexist in different cache layers

**P2. Token Refresh with Exponential Backoff**
The `refreshToken()` method uses cache-based backoff (1s, 2s, 4s, 8s... up to 30s). If the login endpoint is throttled, the backoff helps. But:
- The backoff counter is stored in Cache (Laravel file/redis cache), not session
- If the app is restarted during backoff, the counter is lost
- The lock is a Cache::lock with 10-second TTL — if lock holder crashes, lock releases in 10s
- Multiple concurrent 401s trigger the backoff independently

---

## 9. PULL-TO-REFRESH WORKFLOW

### Problems

**P1. Sidebar PTR vs Workspace PTR**
The sidebar uses its own PTR implementation (PatientListSidebar.vue):
```javascript
await syncAndRefresh()
```
The main workspace uses DoctorWorkspace's PTR:
```javascript
await syncAndRefresh(patientsMeta.value?.current_page || 1)
```
Both call the same syncAndRefresh with dedup guard. But sidebar PTR doesn't pass page number — it defaults to page 1. If the user pulls to refresh from the sidebar while viewing page 3, they're reset to page 1.

**P2. PTR Visual Feedback While Sync Running**
The PTR shows a spinner during refresh. But if `syncAndRefresh` has a long sync (e.g., uploading a large file), the spinner stays for 30+ seconds with no progress indication.
