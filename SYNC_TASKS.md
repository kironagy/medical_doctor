# Synchronization System — Fix Tasks

> **Date:** 2026-07-28  
> **Source:** SYNC_SYSTEM_AUDIT.md verified findings  
> **Strategy:** Fix root cause first, then cascading issues, then optimization

---

## Phase 0: Immediate Data Recovery

**Goal:** Recover lost data on production MySQL before any code changes.

### Task P0-1: Recover 35 Zombie pending_delete Patients

```sql
-- Run on production MySQL immediately
UPDATE patients 
SET sync_status = 'synced', deleted_at = NULL 
WHERE sync_status = 'pending_delete';
```

**Risk:** LOW — these patients are invisible to everyone right now  
**Verification:** After running, verify `SELECT COUNT(*) FROM patients WHERE sync_status = 'pending_delete'` returns 0  

### Task P0-2: Audit Server for Other Orphaned Records

```sql
-- Check for orphaned notes
SELECT COUNT(*) FROM patient_notes n 
LEFT JOIN patients p ON n.patient_id = p.id 
WHERE p.id IS NULL;

-- Check for orphaned files
SELECT COUNT(*) FROM patient_files f 
LEFT JOIN patients p ON f.patient_id = p.id 
WHERE p.id IS NULL;
```

**Risk:** LOW — read-only  
**Verification:** Confirm counts match expected values

---

## Phase 1: Fix Root Cause — API Token Persistence

**Goal:** Ensure the API token is available to the sync engine on every request.

### Task P1-1: Send Bearer Token in Sync Request (Frontend)

**File:** `resources/js/Composables/useSyncEngine.js`

**Change:** In `triggerSync()`, read the token from `localStorage.getItem('np_api_token')` and include it as `Authorization: Bearer {token}` header in the POST to `/_native/api/sync/engine`.

**Risk:** LOW — the token is already available in localStorage  
**Verification:** After fix, check local Laravel log for `[SyncEngine] Bearer token captured from sync request`

### Task P1-2: Capture Bearer Token in /engine Route (Backend)

**File:** `routes/web.php`

**Change:** In the `/engine` route closure, read `$request->bearerToken()` and call `app(ApiService::class)->setToken($bearerToken)` before `$engine->syncAll()`.

**Risk:** LOW — pattern already used in `Mobile/NoteController::store()`  
**Verification:** Confirm `[SyncEngine] Pre-sync auth state` log shows token present

### Task P1-3: Verify Token Flow End-to-End

**Test:**
1. Open app, verify logged in
2. Create a note
3. Wait 30 seconds (heartbeat interval)
4. Check local Laravel log for `[SyncEngine] Note synced successfully`
5. Check production MySQL for the new note
6. Check production nginx logs for the POST request

**Verification:** New note appears on both local SQLite and production MySQL

---

## Phase 2: Fix Patient Lifecycle Sync

**Goal:** Ensure patient create, update, and delete operations sync correctly.

### Task P2-1: Fix Mobile/PatientController::update() — Add pending_update

**File:** `app/Http/Controllers/Api/Mobile/PatientController.php`

**Change:** On SQLite, set `sync_status = 'pending_update'` after update.

**Dependencies:** P1-1, P1-2  
**Risk:** LOW  

### Task P2-2: Fix Mobile/PatientController::destroy() — Don't Soft-Delete on SQLite

**File:** `app/Http/Controllers/Api/Mobile/PatientController.php`

**Change:** On SQLite, set `sync_status = 'pending_delete'` but do NOT call `$patient->delete()` (soft delete). The sync engine's `processPendingDeletes()` will call `forceDelete()` after successful remote delete.

**Dependencies:** P1-1, P1-2  
**Risk:** MEDIUM — must ensure `processPendingDeletes()` is working  

### Task P2-3: Fix PatientRepository::delete() — Don't Set pending_delete on Production

**File:** `app/Repositories/PatientRepository.php`

**Change:** The `delete()` method should NOT set `sync_status = 'pending_delete'` on the production MySQL. The `pending_delete` status is a LOCAL SQLite construct for the sync engine.

**Risk:** MEDIUM — this is the root cause of P0-1  
**Verification:** After fix, deleting a patient on the website should not create `pending_delete` on production MySQL

### Task P2-4: Verify Patient Sync End-to-End

**Test:**
1. Create patient on mobile (online) → appears on website within 30 seconds
2. Update patient on mobile (online) → changes appear on website
3. Delete patient on mobile (online) → disappears from website
4. Create patient on mobile (offline) → appears on website after reconnecting
5. Delete patient on website → disappears from mobile within 30 seconds

---

## Phase 3: Fix Note Sync

**Goal:** Ensure note create, update, and delete sync correctly.

### Task P3-1: Fix Mobile/NoteController::update() — Add pending_update

**File:** `app/Http/Controllers/Api/Mobile/NoteController.php`

**Change:** On SQLite, set `sync_status = 'pending_update'` after update.

**Dependencies:** P1-1, P1-2  
**Risk:** LOW  

### Task P3-2: Fix Mobile/NoteController::destroy() — Add pending_delete

**File:** `app/Http/Controllers/Api/Mobile/NoteController.php`

**Change:** On SQLite, set `sync_status = 'pending_delete'` before soft delete.

**Dependencies:** P1-1, P1-2, P3-1  
**Risk:** LOW  

### Task P3-3: Fix SyncEngineService::syncPendingNotes() — Add pending_update Handling

**File:** `app/Services/SyncEngineService.php`

**Change:** Add `pending_update` status handling to sync updated notes via PUT to the production API.

**Dependencies:** P3-1  
**Risk:** LOW  

### Task P3-4: Fix SyncEngineService::syncPendingNotes() — Bypass DoctorIsolationScope

**File:** `app/Services/SyncEngineService.php`

**Change:** Add `->withoutGlobalScope(DoctorIsolationScope::class)` to the pending notes query.

**Risk:** LOW — same pattern used in `EloquentPatientNoteRepository::forPatient()`

### Task P3-5: Verify Note Sync End-to-End

**Test:**
1. Create note on mobile (online) → appears on website within 30 seconds
2. Update note on mobile (online) → changes appear on website
3. Delete note on mobile (online) → disappears from website
4. Create note on mobile (offline) → appears on website after reconnecting

---

## Phase 4: Fix File Upload Sync

**Goal:** Ensure file uploads sync correctly from mobile to server.

### Task P4-1: Disable Console Command — Rely on SyncEngineService

**File:** `bootstrap/app.php`

**Change:** Remove the CRON schedule for `sync:pending-uploads`. File uploads should be handled exclusively by `SyncEngineService::syncAll()`.

**Risk:** LOW — SyncEngineService already implements the same logic with better atomicity

### Task P4-2: Verify File Sync End-to-End

**Test:**
1. Upload file on mobile (online) → appears on website
2. Upload file on mobile (offline) → appears on website after reconnecting
3. Delete file on mobile → disappears from website

---

## Phase 5: Remove Competing Sync Mechanisms

**Goal:** Single sync path through SyncEngineService only.

### Task P5-1: Remove syncPendingPatients() from refreshPatientList()

**File:** `resources/js/Composables/useWorkspace.js`

**Change:** Remove lines 503-508 that call `POST /_native/api/sync/patients`.

**Risk:** MEDIUM — the `refreshPatientList()` currently depends on this sync BEFORE fetching. After removal, we must ensure:
1. The sync engine's heartbeat has already synced patients before the list fetch
2. Or the engine endpoint is called explicitly before the API fetch

### Task P5-2: Remove Duplicate sync/patients Endpoint

**File:** `routes/web.php`

**Change:** Remove the `POST /_native/api/sync/patients` route that calls `PatientRepository::syncPending()`.

**Risk:** LOW after P5-1

### Task P5-3: Add SyncBypass to refreshPatientList()

**Option A:** Call `POST /_native/api/sync/engine` instead of `/sync/patients`  
**Option B:** Fetch API list first, then use the universal safety net to merge pending patients

**Recommendation:** Option B — the existing universal safety net already handles this case.

---

## Phase 6: Add Conflict Resolution

**Goal:** Last-write-wins with timestamp comparison.

### Task P6-1: Add client_updated_at Tracking

**File:** All controllers that modify patients/notes/files

**Change:** Set `client_updated_at` to `now()` on every local modification.

### Task P6-2: Add Timestamp Comparison in SyncEngineService

**File:** `app/Services/SyncEngineService.php`

**Change:** Before uploading a `pending_update`, compare local `client_updated_at` with server's `updated_at`. If local is older, skip the upload (server wins). If local is newer, force the upload (local wins).

**Risk:** MEDIUM — requires careful implementation to avoid data loss

---

## Phase 7: Performance & Monitoring

**Goal:** Ensure the sync system is observable and performant.

### Task P7-1: Add Sync Dashboard

**File:** New Vue component `SyncDashboard.vue` or extend existing

**Change:** Add a sync status indicator showing:
- Connection status (online/offline)
- Pending operations count (patients, notes, files)
- Last sync timestamp
- Last sync result

### Task P7-2: Add Sync Retry Notifications

**File:** `useSyncEngine.js`

**Change:** When sync fails repeatedly, show a toast notification to the user.

### Task P7-3: Optimize Heartbeat Interval

**File:** `useSyncEngine.js`

**Change:** Reduce heartbeat from 30s to 15s for better responsiveness. Increase to 60s when battery is low.

---

## Phase 8: Testing & Validation

### Task P8-1: Write Unit Tests for Sync Engine

**Test Coverage:**
- `SyncEngineService::syncAll()` with valid token
- `SyncEngineService::syncAll()` with null token (returns skipped)
- `SyncEngineService::syncPendingNotes()` with DoctorIsolationScope bypass
- `SyncEngineService::syncPendingPatients()` with atomic transitions

### Task P8-2: Write Integration Tests for Full Sync Flow

**Flow to test:**
1. Create patient on mobile → sync → verify on server
2. Create note for patient → sync → verify on server
3. Upload file → sync → verify on server
4. Delete patient on server → sync → verify deleted on mobile
5. Delete note on mobile → sync → verify deleted on server

### Task P8-3: Create Sync Monitoring Script

**Script:** Monitor `/_native/api/sync/pending-summary` endpoint every 30 seconds. Alert if pending count exceeds threshold.

---

## Execution Order

```
Phase 0 ── IMMEDIATE DATA RECOVERY
  │
  ▼
Phase 1 ── ROOT CAUSE (Token Persistence) ← unlocks ALL sync
  │
  ▼
Phase 2 ── Patient Lifecycle (Create, Update, Delete)
  │
  ▼
Phase 3 ── Notes (Create, Update, Delete)
  │
  ▼
Phase 4 ── File Uploads
  │
  ▼
Phase 5 ── Remove Competing Mechanisms
  │
  ▼
Phase 6 ── Conflict Resolution
  │
  ▼
Phase 7 ── Performance & Monitoring
  │
  ▼
Phase 8 ── Testing & Validation
```

**Everything below Phase 1 is BLOCKED until Phase 1 is complete**, because without the API token, none of the sync operations can run.
