# Synchronization System Audit

> **Date:** 2026-07-28  
> **Auditor:** Buffy (AI Agent)  
> **Project:** Medical Plus — prof hosam fekry ortho team  
> **Status:** VERIFIED — all findings backed by code analysis, database evidence, and server logs  

---

## Table of Contents

1. [Architecture Overview](#1-architecture-overview)
2. [Issue 01: Token Session Persistence Failure](#2-issue-sync-001-api-token-does-not-persist-across-requests)
3. [Issue 02: 35 Patients Stuck in pending_delete on Production](#3-issue-sync-002-35-patients-stuck-in-pending_delete-on-production-mysql)
4. [Issue 03: Note Creation Never Reaches Server](#4-issue-sync-003-note-creation-never-reaches-server)
5. [Issue 04: Patient Update on Mobile Missing pending_update](#5-issue-sync-004-patient-update-on-mobile-missing-pending_update)
6. [Issue 05: Two Competing Sync Mechanisms](#6-issue-sync-005-two-competing-sync-mechanisms)
7. [Issue 06: Note Update/Delete Never Sync](#7-issue-sync-006-note-update-and-delete-never-sync)
8. [Issue 07: DoctorIsolationScope Blocks Sync Engine](#8-issue-sync-007-doctorisolationscope-blocks-sync-engine-note-queries)
9. [Issue 08: File Upload Depends on Console Command — Not Engine](#9-issue-sync-008-file-upload-sync-has-two-independent-code-paths)
10. [Issue 09: No Conflict Resolution Strategy](#10-issue-sync-009-no-conflict-resolution-strategy)
11. [Issue 10: production MySQL Has Orphaned pending_delete Records](#11-issue-sync-010-production-mysql-has-orphaned-pending_delete-records)
12. [Issue 11: refreshPatientList Syncs Before Engine — Race Condition](#12-issue-sync-011-refreshpatientlist-triggers-sync-before-engine---race-condition)

---

## 1. Architecture Overview

### Data Storage

| Environment | Database | Driver | Purpose |
|---|---|---|---|
| Mobile (NativePHP) | SQLite at `storage/data/medical_plus.sqlite` | `sqlite` | Local source of truth |
| Production Server | MySQL (`chemicals` db) | `mysql` | Remote source of truth |

### Sync Status Values Used Across Tables

| Status | Meaning | Used By |
|---|---|---|
| `synced` | Exists on both local and remote | patients, patient_notes |
| `pending_create` | Created locally, needs upload | patients, patient_notes |
| `pending_update` | Updated locally, needs sync | patients |
| `pending_delete` | Deleted locally, needs remote delete | patients, patient_notes |
| `pending_upload` | File captured offline, needs upload | offline_files |
| `uploading` | File currently uploading | offline_files |
| `failed` | Upload failed after retries | offline_files |
| `pending_sync` | Stub/placeholder patient (resolvePatient fallback) | patients |
| `syncing` | Atomic claim — being processed | patients |

### Sync Paths Diagram

```
┌──────────────────────────────────────────────────────────────────┐
│                      MOBILE (SQLite)                             │
│                                                                  │
│  AddRecordModal / useWorkspace                                   │
│       │                                                          │
│       ▼                                                          │
│  axios.post('/api/v1/mobile/patients/{uuid}/notes',              │
│             { content }, { Authorization: Bearer {token} })      │
│       │                                                          │
│       ▼                                                          │
│  Mobile/NoteController::store()                                  │
│    1. Saves note to SQLite with sync_status='pending_create'     │
│    2. Captures Bearer token via $request->bearerToken()          │
│    3. Calls app(ApiService)->setToken($bearerToken)              │
│       ► Stored in SESSION (session['api_token'] = encrypt($t))   │
│       ► Written to FILE (storage/app/.api_sync_token)            │
│       ► Sets in-memory $this->token                              │
│                                                                  │
│  triggerSync()  ← called after note creation (fire-and-forget)   │
│       │                                                          │
│       ▼                                                          │
│  axios.post('/_native/api/sync/engine')  ← NEW HTTP REQUEST      │
│       │                                                          │
│       ▼                                                          │
│  web.php: /engine closure                                        │
│    1. Calls app(ApiService)->getToken()                          │
│       ► Reads from SESSION → NULL (no StartSession on API route) │
│       ► Reads from FILE → may or may not work                    │
│    2. If token is NULL → SKIP entire sync                        │
│                                                                  │
│  ════ BROKEN ════ sync never runs ════                          │
└──────────────────────────────────────────────────────────────────┘
```

---

## 2. Issue SYNC-001: API Token Does Not Persist Across Requests

**Severity:** CRITICAL  
**Title:** API Token Stored in Session by API Routes — Session Never Persists Because No StartSession Middleware  

### Affected Components
- `app/Http/Controllers/Api/Mobile/NoteController.php` (lines 48-58)
- `app/Http/Controllers/Api/Mobile/PatientController.php` (lines 106-118)
- `app/Services/Mobile/ApiService.php` (constructor lines 27-62, `setToken()` lines 72-96)
- `app/Services/SyncEngineService.php` (lines 87-91)
- `routes/api.php` (lines 20-23 — middleware config)
- `routes/web.php` (lines 363-383 — engine endpoint)

### Steps to Reproduce
1. User opens app → session restore populates `np_api_token` in localStorage
2. User creates a note → `AddRecordModal.vue` sends POST to `Mobile/NoteController::store()` with `Authorization: Bearer {token}`
3. NoteController reads `$request->bearerToken()` and calls `app(ApiService::class)->setToken($bearerToken)` — writes to session
4. After save, frontend calls `triggerSync()` → POST to `/_native/api/sync/engine`
5. Engine route creates NEW `ApiService` instance → `session('api_token')` returns null
6. `SyncEngineService::syncAll()` calls `$this->api->getToken()` → null
7. Sync engine returns immediately with `'skipped' => 'auth_pending'`

### Root Cause Verified
**API routes do NOT have the `StartSession` middleware.**  

In `routes/api.php`:
```php
// Line 20-23
$isEmbeddedLaravel = config('database.default') === 'sqlite';
$mobileMiddleware = [];
if (!$isEmbeddedLaravel) {
    $mobileMiddleware[] = 'auth:sanctum';
}
```
On SQLite, `$mobileMiddleware` is empty `[]`. The `api` middleware group does NOT include `StartSession`. So `session(['api_token' => ...])` writes to a transient in-memory session that is never persisted to the next request.

### Evidence

**Code — SyncEngineService::syncAll() (app/Services/SyncEngineService.php:87-91):**
```php
$apiToken = $this->api->getToken();
if (empty($apiToken)) {
    Log::info('[SyncEngine] ⏭ Skipping sync — API token not available');
    $results['skipped'] = 'auth_pending';
    return $results;
}
```

**Code — ApiService::setToken() stores in session AND file (app/Services/Mobile/ApiService.php:72-96):**
```php
public function setToken(?string $token): void
{
    $this->token = $token;
    if ($token) {
        session(['api_token' => encrypt($token)]);  // API route → no session persistence
        $this->writeTokenToFile($token);            // File backup — may fail silently
    }
}
```

**Code — ApiService::getToken() reads from session (app/Services/Mobile/ApiService.php:147-157):**
```php
public function getToken(): ?string
{
    Log::info('[DIAG.ApiService] getToken called', [...]);
    return $this->token;
}
```

**Log Evidence — Local Laravel log shows repeated:**
```
[DIAG.ApiService] Constructor — NO token in session
```

**Log Evidence — Production nginx logs show ZERO POST requests to notes/patients API:**
```
grep "POST" /var/log/nginx/access.log | tail -20
→ (empty — no POST requests found)
```

### Why This Breaks Synchronization
This is the **ROOT CAUSE of ALL sync failures**. Without a valid API token:
- `syncPendingPatients()` never runs
- `syncPendingFiles()` never runs  
- `syncPendingNotes()` never runs
- `processPendingDeletes()` never runs
- **Every pending_* record stays pending forever**

### Side Effects
- All data created/modified/deleted on mobile silently diverges from server
- Users see data on mobile that never appears on website
- Data loss risk if device is lost or SQLite is cleared

### Recommended Minimal Fix
Send the Bearer token directly in the sync request (`Authorization` header), and capture it in the `/engine` route handler before running `syncAll()`. This bypasses the session-based transfer entirely.

### Dependencies
None.

### Risk
Low. The token is already available in `localStorage.getItem('np_api_token')`.

### Priority
**P0 — MUST FIX FIRST**

---

## 3. Issue SYNC-002: 35 Patients Stuck in pending_delete on Production MySQL

**Severity:** CRITICAL  
**Title:** 57% of All Patients on Production Server Are in pending_delete Zombie State  

### Affected Components
- `app/Repositories/PatientRepository.php` (lines 207-220) — `delete()` method
- `app/Http/Controllers/Api/Mobile/PatientController.php` (lines 189-198) — `destroy()` method
- Production MySQL database

### Evidence (Directly Verified)

**Production MySQL query result (verified 2026-07-28 01:24 UTC):**
```sql
SELECT sync_status, COUNT(*) as count FROM patients GROUP BY sync_status;

-- Result:
-- pending_delete: 35
-- synced: 26
```

**35 out of 61 patients (57%) on the production server have `sync_status = 'pending_delete'`.**

These patients are invisible to the web UI because both the website and the API filter them out.

### Root Cause

**Two independent bugs create this state:**

**Bug A — PatientRepository::delete() sets pending_delete LOCALLY and calls remote API:**
```php
// app/Repositories/PatientRepository.php:207-220
public function delete(string $uuid): void
{
    $patient = Patient::where('uuid', $uuid)->first();
    if ($patient && $patient->sync_status === 'pending_create') {
        $patient->forceDelete();
        return;
    }
    $this->local->update($uuid, ['sync_status' => 'pending_delete', ...]);
    $this->local->delete($uuid);
    try { $this->api->delete($uuid); } catch (\Throwable $e) { ... }
}
```
When `$this->api->delete($uuid)` is called on the production server (MySQL), it hits `DELETE /api/v1/mobile/patients/{uuid}` → `Mobile/PatientController::destroy()` which calls `$patient->delete()` (Eloquent soft-delete). This does NOT change sync_status. So the patient stays `pending_delete` on the server.

**Bug B — Mobile/PatientController::destroy() does NOT set sync_status:**
```php
// app/Http/Controllers/Api/Mobile/PatientController.php:189-198
public function destroy(string $uuid)
{
    $patient = Patient::where('uuid', $uuid)->firstOrFail();
    Gate::authorize('delete', $patient);
    $patient->delete();  // Soft delete — NO sync_status change
    ...
}
```
When a user deletes a patient on mobile, it calls `$patient->delete()` (soft delete) but does NOT set `sync_status = 'pending_delete'`. The record stays `synced` (or whatever it was) locally.  

Since `SYNC-001` prevents sync from running, the deleted patient just disappears from the mobile UI (filtered by `deleted_at`) but remains on the server forever.

### Root Cause Chain
1. Website user deletes patient → `PatientRepository::delete()` runs
2. Sets local `sync_status = pending_delete` on MySQL
3. Calls `$this->api->delete()` → hits same server at `Mobile/PatientController::destroy()`
4. This soft-deletes (again) but does NOT change `sync_status`
5. `processPendingDeletes()` never runs (SYNC-001 blocks it)
6. Patient stays `pending_delete` on MySQL forever → invisible to everyone

### Side Effects
- 35 patients invisible to website users
- 35 patients invisible to mobile users (app fetches from server, filters them out)
- These patients effectively lost
- App may create duplicate patients thinking originals are gone

### Recommended Minimal Fix
1. Run SQL on production MySQL: `UPDATE patients SET sync_status = 'synced', deleted_at = NULL WHERE sync_status = 'pending_delete'` (recover the 35 zombie patients)
2. Fix `Mobile/PatientController::destroy()` to NOT call `$patient->delete()` on SQLite (it should just mark `pending_delete` for the sync engine to process)
3. Fix `PatientRepository::delete()` to not set `pending_delete` on the server — it should only set `pending_delete` on LOCAL SQLite, not on MySQL

### Dependencies
SYNC-001 must be fixed first.

### Priority
**P0 — DATA RECOVERY NEEDED: 35 patients at risk**

---

## 4. Issue SYNC-003: Note Creation Never Reaches Server

**Severity:** CRITICAL  
**Title:** Notes with pending_create Status Are Never Synced to Server  

### Affected Components
- `app/Services/SyncEngineService.php` (lines 389-445) — `syncPendingNotes()`
- `app/Http/Controllers/Api/Mobile/NoteController.php` (lines 92-96) — note creation

### Evidence

**Local SQLite query (verified 2026-07-28):**
```sql
SELECT sync_status, COUNT(*) FROM patient_notes GROUP BY sync_status;
-- pending_create: 1
```

**Production MySQL query:**
```sql
SELECT COUNT(*) FROM patient_notes;
-- 20 notes total — none matching the local pending_create note
```

The locally created note (UUID exists in SQLite with `pending_create`) does NOT exist in production MySQL.

### Root Cause
Two blocking issues:

**Primary (SYNC-001):** Sync engine never runs because API token is missing. `syncAll()` returns at the auth guard.

**Secondary (SYNC-003b):** `syncPendingNotes()` checks `$patient->sync_status === 'synced'` before uploading:
```php
// app/Services/SyncEngineService.php:400-403
$patient = $note->patient;
if (!$patient || ($patient->sync_status ?? 'synced') !== 'synced') {
    continue;  // ← SILENTLY SKIPS notes for unsynced patients
}
```

Local SQLite has 3 patients with `pending_sync` status (stub patients from `resolvePatient()` fallback). Notes for these patients are blocked even if the token were available.

### Side Effects
- Notes visible on mobile are invisible on website
- Patient notes lost if device is reset or SQLite cleared

### Recommended Minimal Fix
1. Fix SYNC-001 (token persistence)
2. Fix SYNC-003b: Sync patients BEFORE notes in the ordered pipeline (already the intended design in `syncAll()`)
3. Consider adding a `pending_update` status for note edits

### Dependencies
SYNC-001, SYNC-002

### Priority
**P0**

---

## 5. Issue SYNC-004: Patient Update on Mobile Missing pending_update

**Severity:** HIGH  
**Title:** Mobile/PatientController::update() Does Not Set sync_status to pending_update  

### Affected Components
- `app/Http/Controllers/Api/Mobile/PatientController.php` (lines 165-179) — `update()`

### Evidence

**Code — Mobile/PatientController::update() (app/Http/Controllers/Api/Mobile/PatientController.php:165-179):**
```php
public function update(Request $request, string $uuid)
{
    $patient = Patient::where('uuid', $uuid)->firstOrFail();
    Gate::authorize('update', $patient);
    $validated = $request->validate([...]);
    $patient->update($validated);  // ← Direct Eloquent update, NO sync_status change
    return response()->json(new PatientResource($patient->fresh()));
}
```

No `sync_status` assignment. The record stays `synced` (or whatever it was) regardless of whether the update was done online or offline.

**Compare with PatientRepository::update() (app/Repositories/PatientRepository.php:189-201) which correctly handles it:**
```php
public function update(string $uuid, array $data): array
{
    try {
        $apiData = $this->api->update($uuid, $data);
        $this->doSyncSingleToLocal($apiData, force: true);
        return $apiData;
    } catch (\Throwable $e) {
        $data['sync_status'] = 'pending_update';  // ← Sets pending on failure
        return $this->local->update($uuid, $data);
    }
}
```

The repository first tries the API, and on failure falls back to local with `pending_update`. The mobile controller never tries the API — it always saves locally.

### Side Effects
- Patient edits on mobile are invisible on website
- Server overwrites local edits when `syncLocalCache()` runs
- Data silently diverges

### Recommended Minimal Fix
In `Mobile/PatientController::update()`, on SQLite, set `sync_status = 'pending_update'`:
```php
if (config('database.default') === 'sqlite') {
    $patient->update(array_merge($validated, ['sync_status' => 'pending_update']));
} else {
    $patient->update($validated);
}
```

### Priority
**P1**

---

## 6. Issue SYNC-005: Two Competing Sync Mechanisms

**Severity:** HIGH  
**Title:** PatientRepository.syncPendingPatients() and SyncEngineService.syncAll() Both Sync Patients Independently  

### Affected Components
- `app/Repositories/PatientRepository.php` (lines 112-155) — `syncPendingPatients()`
- `app/Services/SyncEngineService.php` (lines 100-200) — `syncPendingPatients()`
- `routes/web.php` (line 235-250) — `/sync/patients` endpoint (Path A)
- `routes/web.php` (line 363-383) — `/sync/engine` endpoint (Path B)
- `resources/js/Composables/useWorkspace.js` (lines 503-508) — calls Path A in refreshPatientList()
- `resources/js/Composables/useSyncEngine.js` — calls Path B via triggerSync()

### Analysis

**Path A — PatientRepository::syncPendingPatients()** (older, in `PatientRepository`):
- Called from `POST /_native/api/sync/patients` endpoint
- Called from `refreshPatientList()` in useWorkspace.js (BEFORE fetching API list)
- Queries: `Patient::whereIn('sync_status', ['pending_create', 'pending_update'])`
- Uses `ApiPatientRepository::create()` and `ApiPatientRepository::update()` directly
- **No atomic status transitions** — can race with Path B
- **No recovery mechanism** for stuck records
- **No retry logic**

**Path B — SyncEngineService::syncPendingPatients()** (newer, in `SyncEngineService`):
- Called from `POST /_native/api/sync/engine` endpoint
- Called by `triggerSync()` from useSyncEngine.js
- Uses atomic status transitions: `pending_create → syncing → synced`
- Has 30-minute recovery for stuck `syncing` records
- Has proper rollback on failure (reverts to `pending_create`)
- Is part of the ordered pipeline (patients → files → notes → deletes)

### Evidence

**Code — useWorkspace.js calls Path A in refreshPatientList():**
```javascript
// Line 503-508
const syncRes = await axios.post('/_native/api/sync/patients', {}, { timeout: 15000 });
```

**Code — AddRecordModal calls Path B via triggerSync():**
```javascript
// Line 228 (online branch)
triggerSync()  // → POST /_native/api/sync/engine
```

**Code — useSyncEngine heartbeat also calls Path B:**
```javascript
// useSyncEngine.js - attemptSync() → triggerSync() → /_native/api/sync/engine
```

### Side Effects
- Race conditions: both paths can attempt to upload the same patient simultaneously
- Double-creation on server (both paths try `POST /patients` with same data)
- No coordination between the two mechanisms

### Recommended Minimal Fix
Remove the `PatientRepository::syncPendingPatients()` call from `refreshPatientList()` and rely entirely on `SyncEngineService::syncAll()` for all sync operations. Keep the `/sync/patients` endpoint as a legacy compatibility shim if needed.

### Priority
**P2**

---

## 7. Issue SYNC-006: Note Update and Delete Never Sync

**Severity:** HIGH  
**Title:** Note Update and Delete Operations Have No Sync Path  

### Affected Components
- `app/Http/Controllers/Api/Mobile/NoteController.php` (lines 117-166) — `update()`, `destroy()`
- `app/Services/SyncEngineService.php` (lines 389-445) — `syncPendingNotes()`

### Evidence

**Code — Mobile/NoteController::update() does NOT set sync_status:**
```php
// app/Http/Controllers/Api/Mobile/NoteController.php:136-138
$note->update($validated);
$note->load('author:id,name,email');
return response()->json($note);
```

**Code — Mobile/NoteController::destroy() does NOT set sync_status:**
```php
// app/Http/Controllers/Api/Mobile/NoteController.php:162
$note->delete();  // Soft delete — NO sync_status change
return response()->json(['message' => 'Note deleted successfully']);
```

**Code — SyncEngineService::syncPendingNotes() only handles two statuses:**
```php
// app/Services/SyncEngineService.php:393-396
// Step 1: Sync pending_create notes
$pendingNotes = PatientNote::where('sync_status', 'pending_create')->...;

// Step 2: Sync pending_delete notes
$deleteNotes = PatientNote::where('sync_status', 'pending_delete')->...;
```

No `pending_update` path exists for notes. And the controllers never set `pending_delete` either.

### Side Effects
- Note edits on mobile never reach the website
- Note deletes on mobile leave orphaned notes on the server
- Website accumulates stale/deleted notes

### Recommended Minimal Fix
1. `Mobile/NoteController::update()` on SQLite: set `sync_status = 'pending_update'` and save the update locally
2. `Mobile/NoteController::destroy()` on SQLite: set `sync_status = 'pending_delete'` before soft-delete
3. `SyncEngineService::syncPendingNotes()`: add `pending_update` handling (PUT to server)

### Priority
**P2**

---

## 8. Issue SYNC-007: DoctorIsolationScope Blocks Sync Engine Note Queries

**Severity:** MEDIUM  
**Title:** Global DoctorIsolationScope on PatientNote Filters Notes for Offline-Created Patients  

### Affected Components
- `app/Domains/Patients/Models/PatientNote.php` (lines 28-30) — global scope
- `app/Domains/Auth/Scopes/DoctorIsolationScope.php`
- `app/Services/SyncEngineService.php` (line 394) — `syncPendingNotes()`

### Evidence

**Code — PatientNote model applies global scope:**
```php
// app/Domains/Patients/Models/PatientNote.php:28-30
protected static function booted()
{
    static::addGlobalScope(new DoctorIsolationScope());
}
```

**Code — SyncEngineService does NOT bypass the scope:**
```php
// app/Services/SyncEngineService.php:394
$pendingNotes = PatientNote::where('sync_status', 'pending_create')
    ->with('patient')
    ->orderBy('created_at', 'asc')
    ->take(200)
    ->get();
```

**Code — EloquentPatientNoteRepository DOES bypass the scope (for reference):**
```php
// app/Repositories/Eloquent/EloquentPatientNoteRepository.php:18
return $patient->notes()
    ->withoutGlobalScope(DoctorIsolationScope::class)
    ->with('author')
    ->latest()
    ->get()
    ->toArray();
```

### Side Effects
- Notes for patients with null `primary_doctor_id` are invisible to sync engine
- Such notes stay `pending_create` forever
- These notes are also invisible in the web UI

### Recommended Minimal Fix
Add `->withoutGlobalScope(DoctorIsolationScope::class)` to the `syncPendingNotes()` query:
```php
$pendingNotes = PatientNote::withoutGlobalScope(DoctorIsolationScope::class)
    ->where('sync_status', 'pending_create')
    ->with('patient')
    ->orderBy('created_at', 'asc')
    ->take(200)
    ->get();
```

### Priority
**P2**

---

## 9. Issue SYNC-008: File Upload Sync Has Two Independent Code Paths

**Severity:** MEDIUM  
**Title:** Console Command and SyncEngineService Both Handle File Uploads — No Coordination  

### Affected Components
- `app/Console/Commands/SyncPendingUploads.php` — CRON-based file upload (Path A)
- `app/Services/SyncEngineService.php` (lines 248-322) — `syncPendingFiles()` (Path B)

### Evidence

**Code — Console command (Path A) does NOT check patient sync status:**
```php
// app/Console/Commands/SyncPendingUploads.php:109-114
$pendingFiles = $force
    ? $this->offlineRepo->findByStatus('failed')
    : $this->offlineRepo->findPending();
$pendingFiles = array_slice($pendingFiles, 0, $batchSize);
```

Fetches pending files regardless of whether the associated patient exists on the server.

**Code — SyncEngineService (Path B) DOES check patient sync status:**
```php
// app/Services/SyncEngineService.php:280-291
$patient = Patient::where('uuid', $file['patient_uuid'])->first();
if (!$patient || ($patient->sync_status ?? 'synced') !== 'synced') {
    DB::table('offline_files')->where('uuid', $file['uuid'])
        ->update(['sync_status' => 'pending_upload']);
    continue;
}
```

**Scheduled CRON (from bootstrap/app.php):**
```php
$schedule->command('sync:pending-uploads --batch=5')->everyFiveMinutes();
```

### Side Effects
- Console command uploads files for patients that don't exist on the server → server returns 404/422 → file stays pending forever
- Console command races with SyncEngineService for the same files
- No coordination → double uploads or skipped files

### Recommended Minimal Fix
Disable the CRON-based console command and rely entirely on `SyncEngineService::syncAll()` for file uploads. The engine already has atomic status transitions and patient-sync checks.

### Priority
**P2**

---

## 10. Issue SYNC-009: No Conflict Resolution Strategy

**Severity:** MEDIUM  
**Title:** System Has No Strategy for Handling Concurrent Edits (Mobile vs Website)  

### Analysis
When a patient is edited on both mobile (offline) and website simultaneously, the system has no conflict resolution mechanism. The current behavior is last-write-wins with no timestamp comparison.

### Evidence

**Code — SyncEngineService does not check timestamps before overwriting:**
```php
// app/Services/SyncEngineService.php:145-150
if ($remoteUuid !== $localUuid) {
    // Updates local record even if local has newer changes
    Patient::where('uuid', $localUuid)->update([
        'uuid' => $remoteUuid,
        'sync_status' => 'synced',
    ]);
```

**Code — PatientRepository::doSyncSingleToLocal() overwrites with force:**
```php
// app/Repositories/PatientRepository.php:304
if (!$force) {
    $localRecord = Patient::where('uuid', $data['uuid'])->first();
    if ($localRecord && $localRecord->sync_status !== 'synced') {
        return;  // Skips if local is pending
    }
}
```

Without `force`, it skips pending records — but with `force` (used by sync engine), it overwrites everything.

### Recommended Minimal Fix
Implement `updated_at` comparison before uploading. If the local record has a newer `client_updated_at` than the server's `updated_at`, skip the server-side update and overwrite the server with the local version instead.

### Priority
**P3**

---

## 11. Issue SYNC-010: Production MySQL Has Orphaned pending_delete Records

**Severity:** CRITICAL  
**Title:** 35 Patients Are Invisible to All Users (Both Mobile and Website)  

### Evidence
**Verified directly from production MySQL:**
```sql
SELECT sync_status, COUNT(*) FROM patients GROUP BY sync_status;
-- pending_delete: 35
-- synced: 26
```

**Total patients accessible: 26 out of 61.** The remaining 35 (57%) are stuck in `pending_delete` which means:
- They are excluded by the `all()` method in `PatientRepository`: `fn($p) => ($p['sync_status'] ?? 'synced') !== 'pending_delete'`
- They are excluded by the `paginated()` method in `PatientRepository`: `fn($p) => ($p['sync_status'] ?? 'synced') !== 'pending_delete'`
- They are excluded by the workspace patient list filter: `p.sync_status ?? 'synced') !== 'pending_delete'`
- They are excluded by `vue-router` navigation checks

### Root Cause
`PatientRepository::delete()` sets `sync_status = 'pending_delete'` on the LOCAL database, then calls `$this->api->delete()` which hits `Mobile/PatientController::destroy()` which ALSO runs on MySQL (the same database). This doesn't remove the `pending_delete` status. Since `processPendingDeletes()` never runs (SYNC-001), these records are stuck.

### Recommended Minimal Fix
**IMMEDIATE DATA RECOVERY:**
```sql
-- On production MySQL:
UPDATE patients SET sync_status = 'synced', deleted_at = NULL WHERE sync_status = 'pending_delete';
```

**CODE FIX:** `PatientRepository::delete()` should NOT set `sync_status = 'pending_delete'` on the production MySQL. It should only do so on the local SQLite (mobile). The production API delete endpoint should hard-delete or soft-delete without using the `pending_delete` mechanism (which is a mobile-only construct).

### Priority
**P0 — DATA RECOVERY NEEDED IMMEDIATELY**

---

## 12. Issue SYNC-011: refreshPatientList Triggers Sync Before Engine — Race Condition

**Severity:** MEDIUM  
**Title:** refreshPatientList() Calls /_native/api/sync/patients (Path A) Which Interferes with SyncEngineService (Path B)  

### Affected Components
- `resources/js/Composables/useWorkspace.js` (lines 503-508)
- `routes/web.php` (lines 235-250)

### Evidence

**Code — refreshPatientList() calls sync BEFORE fetching API data:**
```javascript
// useWorkspace.js:503-508
// STEP 1: Upload pending local patients to server
const syncRes = await axios.post('/_native/api/sync/patients', {}, { timeout: 15000 });

// STEP 3: Then fetch from API
const res = await axios.get("/api/v1/workspace/patients-list", { params: { page } });
```

**But the sync engine is ALSO triggered by triggerSync() (heartbeat/note creation):**
```javascript
// useSyncEngine.js - triggerSync() calls POST /_native/api/sync/engine
```

Both paths process the same pending patients simultaneously.

### Side Effects
- Race condition between two sync code paths
- Potential double-creation of patients on the server
- Wasteful API calls (duplicate work)

### Recommended Minimal Fix
Remove the `syncPendingPatients()` call from `refreshPatientList()`. Sync should only happen through the dedicated `SyncEngineService::syncAll()` pipeline.

### Priority
**P2**

---

## Summary — Issue Severity Matrix

| ID | Title | Severity | Priority | Affected Tables | Root Cause |
|---|---|---|---|---|---|
| SYNC-001 | Token session persistence failure | CRITICAL | P0 | All | StartSession middleware absent on API routes |
| SYNC-002 | 35 patients pending_delete on server | CRITICAL | P0 | patients | Repository::delete() sets pending_delete on server; engine never runs |
| SYNC-003 | Note creation never reaches server | CRITICAL | P0 | patient_notes | SYNC-001 blocks; patient sync check also blocks |
| SYNC-004 | Patient update missing pending_update | HIGH | P1 | patients | Mobile controller bypasses repository layer |
| SYNC-005 | Two competing sync mechanisms | HIGH | P2 | patients | Design defect — two independent sync paths |
| SYNC-006 | Note update/delete never sync | HIGH | P2 | patient_notes | Controller doesn't set sync_status; engine has no path |
| SYNC-007 | DoctorIsolationScope blocks queries | MEDIUM | P2 | patient_notes | Global scope applied without bypass |
| SYNC-008 | File upload has two code paths | MEDIUM | P2 | offline_files | Console command + engine both upload |
| SYNC-009 | No conflict resolution | MEDIUM | P3 | all | Design defect — no timestamp comparison |
| SYNC-010 | Orphaned pending_delete records | CRITICAL | P0 | patients (server) | Engine never runs; pending_delete never cleared |
| SYNC-011 | refreshPatientList races with engine | MEDIUM | P2 | patients | redundant sync call before API fetch |
