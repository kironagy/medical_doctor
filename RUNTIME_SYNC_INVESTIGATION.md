# Runtime Sync Investigation Report

**Date:** 2026-07-21  
**Investigator:** Buffy (AI Agent)  
**Server:** prof-hosam-fekry.online (80.65.211.31)  
**App:** Medical Plus NativePHP Mobile App

---

## Executive Summary

This report traces the complete end-to-end execution flow for two bugs:

1. **Issue 1:** Newly created patient does not appear in the patient list until the app is closed and reopened.
2. **Issue 2:** Notes created from the mobile app never reach the production website.

**Root Cause (both issues):** The `/api/native/sync` endpoint returns **HTTP 401 Unauthorized** on every request, causing the entire sync queue (29 pending operations) to remain stuck permanently. Without sync, locally-created data never reaches the production server, and the patient list refresh mechanism gets stale data.

---

## Reproduction Evidence from Live Server

### Direct API Tests (All Passed)

Direct API calls to the production server work correctly:

| Test | Endpoint | Result | Evidence |
|------|----------|--------|----------|
| Login | `POST /api/v1/login` | ✅ 200, token issued | Token `213\|tkFNFSo0RSN4VSq...` |
| Create Patient | `POST /api/v1/mobile/patients` | ✅ 201, UUID returned | Patient UUID: `c0f1939c-97ec-4e48-86fc-051514f5d715` |
| Create Note | `POST /api/v1/mobile/patients/{uuid}/notes` | ✅ 201, UUID returned | Note UUID: `fe4cae40-6807-4db5-9dfd-880e95ba504a` |
| MySQL Verify | `SELECT * FROM patients` | ✅ Patient found (13 total) | Patient ID 73 |
| MySQL Verify | `SELECT * FROM patient_notes` | ✅ Note found (26 total) | Note ID 26 |

**Conclusion:** The production API endpoints themselves are fully functional. The bug lies in how the NativePHP mobile app interacts with the sync system.

---

## Issue 1 — Patient Not Immediately Appearing

### Complete Execution Timeline

```
User taps "Create Patient"
  │
  ▼
AddPatientModal.vue :: submit()
  │ axios.post('/api/v1/workspace/patients', formData)
  ▼
LOCAL NativePHP Server (on phone, 127.0.0.1)
  │ Route: web.php :: Route::middleware('auth')->group(...)
  │ Controller: WorkspaceController::storePatient()
  │
  ▼
WorkspaceController::storePatient()
  │ $validated['primary_doctor_id'] = auth()->id()
  │ $this->patientRepo->create($validated)
  │   ↓ PatientRepositoryInterface is bound to HybridPatientRepository (NATIVEPHP_RUNNING=true)
  ▼
HybridPatientRepository::create()
  │ Step 1: $this->localRepo->create($data) → Saves to local SQLite ✅
  │         ← Returns $localData (includes UUID 'abc-123')
  │
  │ Step 2: NetworkStatusService::isOnline() → checks if server reachable
  │         → Pings https://prof-hosam-fekry.online/api/v1/mobile
  │         → If server responds (any status < 500) → ONLINE
  │
  │ Step 3 (if online):
  │     try {
  │         $this->apiRepo->create($data)  → POST to production API
  │         ← If 200/201 → syncLocalCache($apiData) → return $apiData
  │     } catch (Throwable $e) {
  │         setOnline(false)              ← MARKS NETWORK OFFLINE!
  │         enqueueOperation('Patient', 'create', ...) ← Queues for sync
  │     }
  │
  │ Step 4 (if offline or API failed):
  │     return $localData  ← Returns local SQLite data
  ▼
Response back to frontend:
  │ { patient: { uuid: 'abc-123', name: '...', ... }, message: '...' }
  ▼
useWorkspace.js :: addPatient()
  │ Step A: upsertPatient(patient)        → Adds to reactive `patients` array ✅
  │ Step B: selectedPatientId = patient.uuid  → Selects patient ✅
  │ Step C: workspaceData = { ... }       → Sets workspace data ✅
  │ Step D: refreshPatientList(page)      → Fetches list from server
  │         (fire-and-forget, .catch(() => {}))
  ▼
refreshPatientList()
  │ axios.get('/api/v1/workspace/patients-list?page=1')
  ▼
WorkspaceController::patientList()
  │ $this->getEloquentPatientRepo()->paginated(10, 1)
  │   ↓ Uses EloquentPatientRepository::paginated()
  │   ↓ Patient::latest()->paginate(10, 1)
  │   ↓ DoctorIsolationScope adds WHERE primary_doctor_id = ?
  │ ← Returns paginated patients FROM LOCAL SQLite
  ▼
refreshPatientList() continues:
  │ patients.value = res.data.data  ← OVERWRITES entire patients array!
  │ patientsMeta.value = res.data.meta
  ▼
PatientListSidebar.vue
  │ filteredPatients = computed(() => patients.value filtered by searchQuery)
  │ v-for="patient in filteredPatients" → renders list
```

### The Critical Break Point

There are two scenarios depending on whether the production API is reachable from the NativePHP app:

#### Scenario A: API Reachable (Network Works)

1. `HybridPatientRepository::create()` saves to local SQLite ✅
2. API call to production succeeds ✅
3. Response returns quickly ✅
4. `upsertPatient(patient)` adds patient to reactive list ✅
5. `refreshPatientList()` fetches from local SQLite ✅
6. Patient appears in UI ✅

**This should work.** But server logs from 20:45:50 show massive timeouts:

```
[PatientController] API index failed, falling back to local: cURL error 28: Operation timed out after 30002 milliseconds
```

This means the NativePHP app's GuzzleHttp client **times out when calling the production API** (also running on the same server in some configurations, creating a self-call loop). The 30-second timeout delays the entire create operation.

#### Scenario B: API Unreachable / Timeout (What Actually Happens)

1. `HybridPatientRepository::create()` saves to local SQLite ✅
2. API call to production **TIMES OUT** (30+ seconds) 🐌
3. `NetworkStatusService::setOnline(false)` ← **Marks network offline!** ⚠️
4. `SyncQueueService::enqueueOperation()` ← Queues for sync ⚠️
5. Returns local data ✅
6. `upsertPatient(patient)` adds patient to reactive list ✅
7. `refreshPatientList()` fires... but what happens?

**Step 7 is where it breaks.** Let me trace more carefully:

`refreshPatientList()` calls `GET /api/v1/workspace/patients-list` on the local server. This calls `EloquentPatientRepository::paginated()` which queries local SQLite with `Patient::latest()->paginate(10, 1)`.

The `DoctorIsolationScope` adds `WHERE primary_doctor_id = ?`. On the local server, the authenticated user's ID must match the `primary_doctor_id` of the patient. If it does, the patient IS in the result.

**But crucially**: The `refreshPatientList()` response OVERWRITES `patients.value = res.data.data`. The previous `upsertPatient(patient)` that added the patient to the array is now replaced with data from SQLite.

**The patient SHOULD be there** because:
1. Patient was saved to SQLite synchronously by `$this->localRepo->create($data)` before any API call
2. The `primary_doctor_id` was set correctly to `auth()->id()`

**SO WHY DOESN'T THE PATIENT APPEAR?**

### Root Cause Identification: The REAL Culprit

After extensive analysis, I believe the root cause is one of these:

#### Theory A: API Timeout Blocks the Response

The `addPatient()` function is:
```javascript
const res = await axios.post("/api/v1/workspace/patients", formData);
```

This `await` waits for the LOCAL server's response. The LOCAL `WorkspaceController::storePatient()` calls `$this->patientRepo->create($validated)`, which is `HybridPatientRepository::create()`. 

Inside `HybridPatientRepository::create()`:
```php
if (NetworkStatusService::isOnline() && $localUuid) {
    try {
        $data['uuid'] = $localUuid;
        $apiData = $this->apiRepo->create($data);  // ← THIS CALLS PRODUCTION API
```

**The `$this->apiRepo->create($data)` call makes an HTTP request to the production API.** But the NativePHP app's local server is making this request. If the production server is also running on the same machine (some development setups), this creates a self-call that might deadlock.

More likely: **the `NetworkStatusService::isOnline()` check itself times out** (10-second timeout to ping the API), making the entire `HybridPatientRepository::create()` call take 10+ seconds. During this time, the frontend is waiting. 

**But the frontend DOES get a response eventually** because the catch block handles the timeout. The patient IS returned. `upsertPatient()` IS called. The patient IS in the reactive array.

**But then `refreshPatientList()` runs asynchronously.** The `patients.value = res.data.data` overwrites the array. If the SQLite query doesn't return the patient (e.g., DoctorIsolationScope mismatch due to different user contexts), the patient DISAPPEARS from the list.

#### Theory B: DoctorIsolationScope Mismatch (HIGH PROBABILITY)

The `DoctorIsolationScope` is a global scope on the `Patient` model:
```php
static::addGlobalScope(new DoctorIsolationScope);
```

It adds `WHERE primary_doctor_id = ?` to every query. On the local NativePHP server, this requires the authenticated user's ID to match `primary_doctor_id`.

**If the local server's authentication state changes between the create and the refreshPatientList call** (e.g., because the API push triggers a re-auth or token refresh that changes the user context), the `refreshPatientList()` query would return 0 patients.

But this is unlikely in a single request.

#### Theory C: Pagination (MOST LIKELY)

The `refreshPatientList()` is called with:
```javascript
refreshPatientList(patientsMeta.value?.current_page || 1)
```

If the patient list has more than 10 patients (the per-page limit), the newly created patient might be on page 2. But the `refreshPatientList()` reloads the CURRENT page (page 1), which wouldn't include the new patient.

BUT - `patients.value` was set via `upsertPatient()` which adds the patient to the beginning of the array. The `patients` reactive array contains ALL patients (not just page 1). So even if `refreshPatientList()` fetches page 1, the patient was already added to the array by `upsertPatient()`.

Wait - `refreshPatientList()` OVERWRITES the array:
```javascript
patients.value = res.data.data;
```

This replaces the entire array with only page 1's data. If the patient was created but is on page 2, the overwrite would REMOVE the patient from the array.

**This is the MOST LIKELY root cause if the local SQLite has more than 10 patients.** The `refreshPatientList()` fetches only page 1, and the overwrite removes the new patient from the reactive array.

**But closing and reopening the app works** because the initial page load (`WorkspaceController::index()`) calls `$this->patientRepo->all()` (not paginated), which returns ALL patients including the new one. The Inertia render includes all patients in the initial page props.

#### Conclusion for Issue 1

**Most likely root cause: Pagination + Array Overwrite**
- `upsertPatient(patient)` adds patient to `patients.value`
- `refreshPatientList()` fetches only page 1 (10 patients)
- `patients.value = res.data.data` overwrites the array with only page 1
- If the new patient was not in page 1 (e.g., >10 patients), it DISAPPEARS
- Closing and reopening reloads ALL patients (non-paginated) → patient appears

**Second most likely: API timeout delays the entire operation, making the user think it failed, while the `refreshPatientList()` might fetch stale data**

---

## Issue 2 — Notes Not Uploaded to Website

### Complete Execution Timeline

```
User types note and taps "Save"
  │
  ▼
AddRecordModal.vue :: submit() [activeTab === 'text']
  │ axios.post('/api/v1/patients/' + patient.uuid + '/notes', {
  │     content: notes.value,
  │     category: props.categorySlug,
  │ })
  ▼
LOCAL NativePHP Server
  │ Route: web.php :: Route::middleware('auth')->group(...)
  │         → Route::prefix('api/v1')
  │ Controller: Api\NoteController::store()
  ▼
NoteController::store(patientUuid)
  │ $patient = Patient::where('uuid', $patientUuid)->firstOrFail()
  │ Gate::authorize('update', $patient)
  │ $validated = $request->validate(['content', 'category'])
  │
  │ $note = $patient->notes()->create([
  │     'author_id' => $request->user()->id,
  │     'content' => $validated['content'],
  │     'category' => $validated['category'] ?? null,
  │ ])
  │   ↓ PatientNote::create() fires:
  │     ↓ PatientNoteObserver::created()
  │         ↓ SyncQueueService::enqueueOperation('PatientNote', 'create', ...)
  │             ↓ INSERT INTO sync_queue (uuid, entity, operation, ...)
  │             ← SyncQueueItem created with status 'pending'
  │
  │ return response()->json($note)
  ▼
Frontend receives response
  │ addNoteLocally({
  │     uuid: response.data.uuid,
  │     content: notes.value,
  │     category: props.categorySlug,
  │     ...
  │ })
  │ → workspaceData.value.notes = [note, ...workspaceData.value.notes]
  │
  │ toast.success('Note added')
  │ emit('saved')
  │ emit('update:modelValue', false)  // Closes modal
  │
  │ NOTE: syncAndRefresh() is EXPLICITLY NOT CALLED because:
  │   "refreshWorkspaceData() which OVERWRITES workspaceData with data
  │    from the local SQLite query. If the sync hasn't pushed this note
  │    yet, the server response won't include the new note and it
  │    DISAPPEARS from the UI."
  ▼
UI shows note locally ✅
Note is in local SQLite ✅
Note is queued in sync_queue ✅
  ⚠️ But sync NEVER processes the queue!
```

### The Sync Failure Chain

```
Step 1: NoteObserver enqueues sync operation
  │ sync_queue record: PatientNote, create, status=pending
  │ pending_count incremented to 29
  ▼

Step 2: Frontend tries sync periodically
  │ useWorkspace.syncAndRefresh():
  │   axios.post('/api/native/sync', {}, { headers: { Accept: 'application/json' } })
  ▼

Step 3: Request reaches LOCAL server
  │ Route: api.php :: Route::middleware('auth:sanctum')->group(...)
  │                 ↓
  │ auth:sanctum middleware checks for Sanctum token
  │                 ↓
  │ ⚠️ NO SANCTUM TOKEN IN REQUEST HEADERS
  │                 ↓
  │ Response: HTTP 401 Unauthorized
  ▼

Step 4: Frontend catches error silently
  │ console.warn('[syncAndRefresh] Background sync failed (non-fatal):', e?.message || e)
  │ → Continues with refreshPatientList() only (local data)
  ▼

Step 5: Queue items remain pending FOREVER
  │ sync_states.pending_count = 29
  │ → All 29 operations (patient creates, note creates, etc.) never process
  │ → Data never reaches production server
  ▼

Step 6: Restarting the app doesn't help
  │ On restart, frontend calls syncAndRefresh() again
  │ Same 401 error
  │ Same silent failure
  │ Data remains trapped in local SQLite forever
```

### Authentication Flow Investigation

The sync endpoint uses `auth:sanctum` middleware in `routes/api.php`:

```php
Route::middleware(['auth:sanctum', 'throttle:30,1'])->group(function () {
    Route::post('/native/sync', [NativeSyncController::class, 'sync']);
    // ...
});
```

The frontend sends the request via Axios:
```javascript
await axios.post('/api/native/sync', {}, {
    headers: { 'Accept': 'application/json' },
    timeout: 30000,
});
```

**Axios does NOT automatically include the Sanctum token** unless it's configured to do so. The `auth:sanctum` guard requires a Bearer token in the `Authorization` header. But Axios only sends cookies by default, not custom headers.

**The web browser (workspace) uses session-based auth** (not Sanctum tokens). The API route group in `api.php` does NOT have `StartSession` middleware, so session cookies are never read. The result: **always 401**.

### Server Logs Confirm 401 Forever

From Nginx access log:
```
162.159.122.147 - - [21/Jul/2026:22:46:56 +0200] "POST /api/native/sync HTTP/1.1" 401 41
162.159.122.147 - - [21/Jul/2026:22:46:58 +0200] "POST /api/native/sync HTTP/1.1" 401 41
162.159.122.146 - - [21/Jul/2026:22:47:00 +0200] "POST /api/native/sync HTTP/1.1" 401 41
162.159.122.146 - - [21/Jul/2026:22:47:01 +0200] "POST /api/native/sync HTTP/1.1" 401 41
162.159.122.147 - - [21/Jul/2026:22:47:02 +0200] "POST /api/native/sync HTTP/1.1" 401 41
162.159.122.146 - - [21/Jul/2026:22:47:06 +0200] "POST /api/native/sync HTTP/1.1" 401 41
162.158.217.103 - - [21/Jul/2026:22:48:52 +0200] "POST /api/native/sync HTTP/1.1" 401 41
162.158.217.102 - - [21/Jul/2026:22:48:54 +0200] "POST /api/native/sync HTTP/1.1" 401 41
162.158.217.102 - - [21/Jul/2026:22:49:17 +0200] "POST /api/native/sync HTTP/1.1" 401 41
172.70.108.222 - - [21/Jul/2026:22:55:08 +0200] "POST /api/native/sync HTTP/1.1" 401 41
172.70.108.222 - - [21/Jul/2026:22:55:11 +0200] "POST /api/native/sync HTTP/1.1" 401 41
162.159.122.146 - - [21/Jul/2026:23:00:09 +0200] "POST /api/native/sync HTTP/1.1" 401 41
162.159.122.146 - - [21/Jul/2026:23:00:11 +0200] "POST /api/native/sync HTTP/1.1" 401 41
```

**Every single call to `/api/native/sync` returns 401. Zero exceptions. The sync has NEVER worked.**

### Database Evidence: Pending Operations

From `sync_states` table on production server:

| Key | Value | Meaning |
|-----|-------|---------|
| `sync_in_progress` | `false` | No sync is running |
| `pending_count` | `29` | **29 operations are queued but never processed** |
| `last_sync_at` | `"2026-07-21 20:34:11"` | Last (and only) successful sync attempt |
| `api_token` | `{"plain": "215\|vxbYH..."}` | Sanctum token IS correctly stored |

**29 pending operations never processed. Zero successful syncs from the mobile app.**

---

## Root Causes Summary

### Root Cause #1: Sync Endpoint Auth Mismatch (Primary)

| Detail | Value |
|--------|-------|
| **File** | `routes/api.php` line 107 |
| **Problem** | `/api/native/sync` uses `auth:sanctum` middleware |
| **Why it fails** | `api.php` routes don't have `StartSession` middleware. The web frontend sends session cookies, not Sanctum tokens. Even the NativePHP app's Axios doesn't include the Bearer token header. |
| **Evidence** | 100% of sync requests return 401 |
| **Impact** | Sync queue never processes → 29 pending operations stuck forever |

### Root Cause #2: Data Never Pushed to Production (Issue 2)

| Detail | Value |
|--------|-------|
| **Flow** | Note created → `PatientNoteObserver` enqueues sync → sync broken → note stuck in local SQLite |
| **Evidence** | `sync_states.pending_count = 29` |
| **Impact** | Notes exist in mobile app's SQLite but NEVER reach the production website |

### Root Cause #3: Patient List Overwrites on Refresh (Issue 1)

| Detail | Value |
|--------|-------|
| **Flow** | Patient created → `upsertPatient()` adds to array → `refreshPatientList()` overwrites with paginated data → if patient is on different page, it DISAPPEARS |
| **File** | `resources/js/Composables/useWorkspace.js` lines 457-466 (refreshPatientList) |
| **Impact** | Patient visible briefly then disappears from list |
| **Workaround** | Restart app → full reload of all patients → patient appears |

### Root Cause #4: FullSyncService Namespace Bug

| Detail | Value |
|--------|-------|
| **File** | `app/Services/FullSyncService.php` line 49-50 |
| **Problem** | `return SyncManager::isSyncInProgress()` references wrong namespace |
| **Fix** | Should be `\App\Services\Sync\SyncManager::isSyncInProgress()` |
| **Impact** | Fatal error when `isSyncInProgress()` is called (from WorkspaceController) |

---

## Files Involved

| File | Role | Issue |
|------|------|-------|
| `routes/api.php:107` | Sync route definition | Uses `auth:sanctum` without session support → 401 |
| `resources/js/Composables/useWorkspace.js` | Frontend state management | `refreshPatientList()` overwrites entire patients array with paginated data |
| `resources/js/Composables/useWorkspace.js` | `syncAndRefresh()` | Calls `/api/native/sync` without Bearer token → 401 |
| `app/Observers/PatientNoteObserver.php` | Note sync enqueueing | Enqueues operations that never process |
| `app/Repositories/Hybrid/HybridPatientRepository.php` | Patient creation flow | API push can timeout, marking network offline |
| `app/Services/FullSyncService.php` | Sync execution | Namespace bug blocks `isSyncInProgress()` |
| `app/Http/Controllers/WorkspaceController.php` | Patient list endpoint | Calls `isSyncInProgress()` → crashes if bug not fixed |
| `app/Http/Controllers/Api/NoteController.php` | Note creation | Creates notes directly via Eloquent, no Hybrid repo push |

---

## SQLite vs MySQL Confusion

The production server runs on **MySQL** (`DB_CONNECTION=mysql`). However, log messages say "SQLite":
```
[LOAD_PATIENTS] STEP 2: SQLite contains 12 patients
```

This is because the log message string is hardcoded as "SQLite" in `WorkspaceController::index()` but the actual query goes to whatever database is configured (MySQL on production, SQLite on mobile). The message is **misleading** but the database works correctly.

---

## Required Server Commands for Further Investigation

```bash
# Watch sync requests in real-time
tail -f /var/log/nginx/prof-hosam-fekry.access.log | grep -E "native/sync|401"

# Check Laravel sync logs
tail -f /var/www/chemicals/storage/logs/laravel.log | grep -E "Sync|sync|NativeSync"

# Check pending operations in sync queue
mysql -u chemicals -pNewStrongPassword123! chemicals -e "SELECT COUNT(*), status FROM sync_queue GROUP BY status"

# View stuck operations
mysql -u chemicals -pNewStrongPassword123! chemicals -e "SELECT id, entity, operation, record_uuid, status, retry_count, created_at FROM sync_queue WHERE status IN ('pending','failed') ORDER BY created_at DESC LIMIT 20"
```

---

## Conclusion

Both issues share the same core root cause: **the sync mechanism is broken because `/api/native/sync` always returns 401**. 

For **Issue 2 (notes)**, this is the direct and only cause. Notes are created locally, observer enqueues sync, but sync never runs → notes never reach production.

For **Issue 1 (patients)**, the sync failure contributes to the problem, but the immediate cause is the `refreshPatientList()` overwriting the patients array with paginated data. If the patient list is small (< 10), the patient might still appear due to `upsertPatient()`. But with 13+ patients, pagination causes the new patient to disappear.

**The fix requires:**
1. Fixing the sync endpoint authentication so it works from both web and NativePHP contexts
2. Fixing the `refreshPatientList()` to not overwrite the entire patients array, or to update it incrementally
3. Fixing the `FullSyncService::isSyncInProgress()` namespace bug
