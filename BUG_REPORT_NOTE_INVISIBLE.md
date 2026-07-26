# Bug Report: Mobile Notes Not Visible After Creation (Race Condition)

## 1. Symptom Summary
When creating a new note from the mobile app (Android WebView / NativePHP), the API returns **HTTP 201 Created** and the frontend shows a **success toast**, but the note does **not appear** in the patient's category grid in the UI.

## 2. Architecture Overview

```
Mobile App (Android WebView)
  ↓ axios POST
NativePHP RequestRouter (intercepts XHR/fetch)
  ├── LOCAL_PHP  → Embedded Laravel (SQLite database) 
  │                  Routes: /api/v1/mobile/patients/{uuid}/notes
  │                          /_native/api/offline/notes
  │                          /_native/api/sync/*
  └── EXTERNAL   → Production Server (MySQL database)
                     Routes: /api/v1/workspace/{uuid}
                             /api/v1/patients/{uuid}/categories/{slug}/files
                             /api/v1/patients/{uuid}/notes
```

### Key Routing Rules (from ADB log observations)
| URL Pattern | Route | Purpose |
|---|---|---|
| `/api/v1/mobile/patients/{uuid}/notes` | LOCAL_PHP | Note CRUD (mobile controllers) |
| `/api/v1/patients/{uuid}/notes` | EXTERNAL or LOCAL_PHP | Note CRUD (API controllers) |
| `/api/v1/workspace/{uuid}` | EXTERNAL | Patient workspace data fetch |
| `/api/v1/patients/{uuid}/categories/{slug}/files` | EXTERNAL | Category file/note listings |
| `/_native/api/sync/pending-summary` | LOCAL_PHP | Sync engine status |
| `/_native/api/offline/*` | LOCAL_PHP | Offline operations |

## 3. Evidence Collected

### 3.1 ADB Log (from phone, July 26 02:39:06)
```
02:39:06.682 PHPBridge: Response first 200 bytes: HTTP/1.1 201 Created
02:39:06.702 GET /api/v1/workspace/5f5ac5de-... → EXTERNAL (production)
02:39:07.821 GET /api/v1/patients/5f5ac5de/.../categories/medical_history/files → EXTERNAL
```
**Critical finding:** Immediately after the 201 response, `refreshWorkspaceData()` is called which fetches workspace data from the PRODUCTION server (EXTERNAL). This overwrites the locally added note.

### 3.2 Production Nginx Log (July 26 01:39:01 - 01:39:08)
```
GET /api/v1/workspace/5f5ac5de-... HTTP/1.1 200
GET /api/v1/patients/5f5ac5de/.../categories/medical_history/files?page=1&per_page=6&sort=newest HTTP/1.1 200
GET /api/v1/patients/5f5ac5de/.../categories/notes/files?page=1&per_page=6&sort=newest HTTP/1.1 200
```
No POST requests for notes were logged. The note creation went to LOCAL_PHP, not production.

### 3.3 Production MySQL patient_notes
```
No notes for patient_id=637 (test #132989, uuid=5f5ac5de-9317-46f3-838c-070757c1ecf7)
```
The note was NOT created on the production server.

### 3.4 Local SQLite (development machine - separate from phone's DB)
```
patients table: 3 fake test records only (test-note-uuid, etc.)
patient_notes table: 1 old note (id=1, patient_id=3, pending_create)
```
The phone's local SQLite has DIFFERENT data (confirmed by SyncEngine showing notes:2 then notes:3).

### 3.5 SyncEngine Pending Summary (from ADB log)
```
Initial: notes:1 (1 pending note in local SQLite)
After note creation: notes:2 (then notes:3) — new note IS in phone's SQLite
```

### 3.6 Production Patient Record (MySQL)
```
id: 637, uuid: 5f5ac5de-9317-46f3-838c-070757c1ecf7, name: test, code: 132989
sync_status: synced, primary_doctor_id: 2, created_by_id: 2
```
The patient IS on the production server but NOT in the phone's local SQLite.

## 4. Root Cause Analysis

### 4.1 The Two-Database Problem
- Patient **creation** (via `/api/v1/mobile/patients`): The RequestRouter sends this to LOCAL_PHP, which creates the patient in the device's SQLite database AND forwards to the production server.
- **BUT**: The phone's SQLite database was apparently wiped/reset during APK reinstallation (`adb install -r`), losing all previously-created patient records. The local SQLite now only has 3 fake factory-seeded records.
- When the patient "test #132989" (uuid `5f5ac5de-...`) was created, it went to LOCAL_PHP which forwarded to production. But the local SQLite might not have retained it (or the patient creation also went to EXTERNAL directly).

### 4.2 The Race Condition Chain
1. User clicks "Add" on category block in patient detail page
2. `AddRecordModal.vue` sends POST to create note
3. **Note created successfully** → HTTP 201 from LOCAL_PHP (or EXTERNAL)
4. `addNoteLocally(createdNote)` → adds note to `workspaceData.notes` (Vue reactive)
5. **BUT**: `emit('saved')` fires → triggers `refreshWorkspaceData()`
6. `refreshWorkspaceData()` → `GET /api/v1/workspace/{uuid}` → **EXTERNAL (production)**
7. Production response: `workspaceData.value = res.data` — **overwrites** the entire workspaceData
8. The locally-added note (from step 4) is **ERASED** from workspaceData
9. UI re-renders without the note
10. User sees success toast but note is invisible

### 4.3 The Snapshot Fix Wasn't Enough
A snapshot mechanism was added in `refreshWorkspaceData()` to preserve pending notes:
```javascript
const pendingLocalNotes = (workspaceData.value?.notes || [])
    .filter(n => n.sync_status === 'pending_create')
    .map(n => ({ ...n }));
```
This captures notes before the fetch and re-inserts them after the production response. However:
- The ADB log still shows workspace fetch happening after note creation
- The build cache issue (Vite not recompiling fresh assets into the APK) meant this fix was sometimes missing from built APKs
- Even with the fix, the `workspaceData.value = res.data` assignment triggers `shallowRef` reactivity, causing computed properties to re-evaluate with stale data before the merge runs

## 5. All Attempted Fixes (Chronological)

### Fix 1: Default category 'general' → 'notes'
**Files:** `NoteController.php`, `MobileNoteController.php`, `OfflineNoteController.php`
**Change:** Changed default note category from `'general'` to `'notes'`
**Why:** No CategoryBlock exists for slug `'general'`, so notes fell into a void
**Status:** ✅ Kept (correct fix)

### Fix 2: `withoutGlobalScope(DoctorIsolationScope)` in repository
**File:** `EloquentPatientNoteRepository.php`
**Change:** Added `->withoutGlobalScope(DoctorIsolationScope::class)` to `forPatient()`
**Why:** DoctorIsolationScope filtered out notes for patients with null doctor IDs
**Status:** ✅ Kept (correct fix)

### Fix 3: `addNoteLocally()` function
**File:** `useWorkspace.js`
**Change:** Added `addNoteLocally(note)` to insert note into `workspaceData.notes` immediately after creation
**Why:** Without this, the note is invisible until SyncEngine uploads and workspaceData refreshes
**Status:** ✅ Kept (essential fix)

### Fix 4: Production response snapshot/merge in `refreshWorkspaceData()`
**File:** `useWorkspace.js`
**Change:** Added pending notes snapshot before fetch and re-insertion after production response
**Why:** Prevent `refreshWorkspaceData()` from erasing locally-added notes
**Status:** ❌ Insufficient (Vite build cache issues prevented it from being in APK)

### Fix 5: Remove `@saved="refreshWorkspaceData"` from CategoryBlock
**File:** `CategoryBlock.vue`
**Change:** Removed the `@saved` event handler on `<AddRecordModal>`
**Why:** Prevent `emit('saved')` from triggering `refreshWorkspaceData()`
**Status:** ❌ Possibly insufficient (ADB log shows workspace fetch still happens)

### Fix 6: Remove `emit('saved')` from note creation path
**File:** `AddRecordModal.vue`
**Change:** Removed `emit('saved')` from the `activeTab === 'text'` branch
**Why:** Completely prevent the `saved` event from firing for notes (files still emit it)
**Status:** 🔄 **CURRENT FIX** — APK v1.0.32 built and installed

## 6. Remaining Concerns

1. **Vite Build Cache:** The NativePHP `native:build` process may not always pick up fresh Vite assets from `public/build/`. Running `rm -rf public/build/assets/` before `npm run build` is required.
2. **Phoen vs Dev SQLite:** The phone's SQLite database is separate from the development machine's. Queries on the dev machine don't reflect phone state.
3. **Route Routing Uncertainty:** The exact behavior of the RequestRouter for the URL `/api/v1/patients/{uuid}/notes` is unclear — it may route to LOCAL_PHP or EXTERNAL. If LOCAL_PHP, the `resolvePatient()` API fallback might fail.
4. **Multiple Triggers for refreshWorkspaceData():** Even with `@saved` and `emit('saved')` removed, there may be other triggers for workspace data refresh.

## 7. Current Code State

### AddRecordModal.vue (submit() function - notes path)
```javascript
if (online) {
    // POST to production API directly
    const res = await axios.post(`/api/v1/patients/${props.patient.uuid}/notes`, {
        content: notes.value,
        category: props.categorySlug,
    }, {
        headers: token ? { Authorization: 'Bearer ' + token } : {},
    })
    createdNote = res.data
} else {
    // POST to offline endpoint
    const res = await axios.post('/_native/api/offline/notes', {
        content: notes.value,
        category: props.categorySlug,
        patient_uuid: props.patient.uuid,
    })
    createdNote = res.data
}

// Add note to workspaceData IMMEDIATELY
if (createdNote?.uuid) {
    addNoteLocally(createdNote)
}
toast.success('تمت إضافة الملاحظة بنجاح')
// ⚠️ NO emit('saved') — prevents refreshWorkspaceData() race condition
emit('update:modelValue', false)
```

### useWorkspace.js (addNoteLocally function)
```javascript
function addNoteLocally(note) {
    if (!note?.uuid) return;
    if (!workspaceData.value) {
        workspaceData.value = {
            files: [], notes: [note], visits: [],
            shares: [], categories: [], stats: {},
        };
        return;
    }
    if (!workspaceData.value.notes) workspaceData.value.notes = [];
    const existingIndex = workspaceData.value.notes.findIndex(n => n.uuid === note.uuid);
    if (existingIndex === -1) {
        workspaceData.value.notes = [note, ...workspaceData.value.notes];
    }
    workspaceData.value = { ...workspaceData.value }; // trigger shallowRef reactivity
}
```

### useWorkspace.js (refreshWorkspaceData function - has snapshot fix)
```javascript
function refreshWorkspaceData() {
    if (selectedPatientId.value) {
        loadingPatient.value = true;
        // Snapshot pending notes BEFORE fetch
        const pendingLocalNotes = (workspaceData.value?.notes || [])
            .filter(n => n.sync_status === 'pending_create')
            .map(n => ({ ...n }));

        axios.get(`/api/v1/workspace/${selectedPatientId.value}`)
            .then((res) => {
                workspaceData.value = res.data;
                // Re-insert missing pending notes
                if (pendingLocalNotes.length > 0 && workspaceData.value) {
                    const serverUuids = new Set(
                        (workspaceData.value.notes || []).map(n => n.uuid)
                    );
                    const missingLocal = pendingLocalNotes.filter(
                        n => !serverUuids.has(n.uuid)
                    );
                    if (missingLocal.length > 0) {
                        if (!workspaceData.value.notes)
                            workspaceData.value.notes = [];
                        workspaceData.value.notes = [
                            ...missingLocal,
                            ...workspaceData.value.notes
                        ];
                        workspaceData.value = { ...workspaceData.value };
                    }
                }
            })
            .catch(() => { workspaceData.value = null; })
            .finally(() => { loadingPatient.value = false; });
    }
}
```

## 8. Key Files Modified

| File | Path | Changes |
|---|---|---|
| AddRecordModal.vue | `resources/js/Components/workspace/` | URL changed to production API path; `emit('saved')` removed from notes |
| useWorkspace.js | `resources/js/Composables/` | Added `addNoteLocally()`; snapshot/merge in `refreshWorkspaceData()` |
| CategoryBlock.vue | `resources/js/Components/workspace/` | Removed `@saved="refreshWorkspaceData"` from AddRecordModal |
| MobileNoteController.php | `app/Http/Controllers/Api/Mobile/` | Bearer token capture; category default 'notes'; sync_status='pending_create' |
| NoteController.php | `app/Http/Controllers/Api/` | Default category 'notes'; sync_status on embedded Laravel |
| OfflineNoteController.php | `app/Http/Controllers/Api/` | Default category 'notes' |
| EloquentPatientNoteRepository.php | `app/Repositories/Eloquent/` | Added `withoutGlobalScope(DoctorIsolationScope)` |

## 9. Test Steps for Verification

1. Clear ADB log: `adb logcat -c`
2. Open app on phone
3. Navigate to patient **test #132989** (or any patient)
4. Click **"إضافة (Add)"** on any category block (e.g., "Medical History")
5. Type note content and click **"حفظ" (Save)**
6. Observe ADB log for:
   - POST request and 201 response
   - Absence of GET /api/v1/workspace/{uuid} immediately after
   - `DEBUG_NOTE_SHOW` console logs (if debug build)
7. Verify note appears instantly in the category grid
8. Wait 30 seconds for SyncEngine to upload to production
9. Check production MySQL for the note

---

*Report generated July 26, 2026. For further analysis by another AI model.*
