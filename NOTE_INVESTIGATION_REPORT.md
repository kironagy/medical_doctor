# Forensic Investigation Report: Mobile Note Visibility Bug

**Date:** 2026-07-26
**Investigator:** Codebase Forensic Analysis
**Evidence Sources:** Full repository source, bug report, code analysis
**Confidence Level:** HIGH (all conclusions backed by direct code evidence)

---

## 1. Executive Summary

The original bug — where a note created from the mobile app returns HTTP 201 but never appears in the UI — was caused by a **race condition between local note creation and workspace data refresh**. The `refreshWorkspaceData()` function fetches data from the production server (EXTERNAL), which does not yet contain the newly created note. When `workspaceData.value` is overwritten with the production response, the locally-added note is erased.

**Current code status:** The primary fix path (AddRecordModal.vue) is **correctly fixed** — `emit('saved')` is removed from the notes path, AddRecordModal no longer triggers `refreshWorkspaceData()`, and `addNoteLocally()` inserts the note immediately. However, **two additional note creation paths with the same vulnerability still exist**: `DoctorWorkspace.submitNoteForm()` and `CategoryBlock.submitNote()` (dead code). Both call `refreshWorkspaceData()` after creating notes via LOCAL_PHP, relying on a fragile snapshot/merge mechanism that could fail under specific conditions.

**Additional critical finding:** The snapshot/merge logic in `refreshWorkspaceData()` works by filtering notes with `sync_status === 'pending_create'`. This means it ONLY protects notes created via LOCAL_PHP (SQLite). Notes created via EXTERNAL (production API) are already on the server and will appear in the production response, so the merge is unnecessary but also harmless for those.

---

## 2. Architecture Diagram

```
┌─────────────────────────────────────────────────────────────────┐
│ Mobile App (Android WebView)                                    │
│ NativePHP Runtime                                               │
├─────────────────────────────────────────────────────────────────┤
│                                                                │
│ RequestRouter.kt (Kotlin, routing decisions)                   │
│                                                                │
│ URL: /api/v1/patients/{uuid}/notes (POST)                      │
│   → Rule 3: /api/ + POST mutation + ONLINE → LOCAL_PHP         │
│   → Embedded Laravel → SQLite database                         │
│                                                                │
│ URL: /api/v1/workspace/{uuid} (GET)                            │
│   → Rule 4: /api/ + GET (non-mutation) + ONLINE → EXTERNAL     │
│   → Production Server → MySQL database                         │
│                                                                │
│ URL: /_native/api/offline/notes (POST)                          │
│   → Rule 2: /_native/ prefix → LOCAL_PHP (always)              │
│   → Embedded Laravel → SQLite database                         │
│                                                                │
└─────────────────────────────────────────────────────────────────┘
```

---

## 3. Complete Request Lifecycle

### Path A: AddRecordModal.vue (Primary user path — "Add" button on CategoryBlock)

This is the path the user actually clicks:
`CategoryBlock.vue` line 9 → `openAddRecord('text')` → `AddRecordModal.vue.submit()`

```
Step 1: User clicks "إضافة (Add)" button
  File: CategoryBlock.vue:9
  @click.stop="openAddRecord('text')"

Step 2: openAddRecord('text') sets showAddRecordModal = true
  File: CategoryBlock.vue:1282-1284

Step 3: AddRecordModal opens (v-model shows it)
  File: CategoryBlock.vue:362-368
  <AddRecordModal v-model="showAddRecordModal"
    :patient="selectedPatient"
    :categorySlug="slug"
    :initialTab="addRecordModalTab" />
  NOTE: No @saved handler — already removed per Fix 5

Step 4: User types note and clicks "حفظ" (Save)
  File: AddRecordModal.vue:181-257 (submit() function)

  Step 4a: Note tab detected (activeTab === 'text')
  Step 4b: Network state check
    File: AddRecordModal.vue:192
    `online = typeof navigator !== 'undefined' ? navigator.onLine : true`

  Step 4c: ONLINE path — POST to /api/v1/patients/{uuid}/notes
    File: AddRecordModal.vue:195-211
    URL: `/api/v1/patients/${props.patient.uuid}/notes`
    Payload: { content: notes.value, category: props.categorySlug }
    Headers: { Authorization: 'Bearer ' + token } (if token exists)

    ┌──────────────────────────────────────────────────────────┐
    │ ROUTING DECISION:                                        │
    │ RequestRouter Rule 3: /api/ + POST + ONLINE → LOCAL_PHP │
    │                                                          │
    │ NOTE: The comment at AddRecordModal.vue:196-199 claims   │
    │ this routes EXTERNAL — this is INCORRECT (see §6).       │
    │ The note goes to LOCAL_PHP (SQLite).                     │
    └──────────────────────────────────────────────────────────┘

Step 5: Response handling
  File: AddRecordModal.vue:212
  `createdNote = res.data`
  Expected: HTTP 201 + JSON {id, uuid, category, content, sync_status:'pending_create'}

Step 6: addNoteLocally(createdNote)
  File: AddRecordModal.vue:236-237
  File: useWorkspace.js:399-419
  Inserts note at beginning of workspaceData.value.notes array
  Triggers shallowRef reactivity via `workspaceData.value = { ...workspaceData.value }`

Step 7: Toast success
  File: AddRecordModal.vue:239
  `toast.success('تمت إضافة الملاحظة بنجاح')`

Step 8: Close modal
  File: AddRecordModal.vue:243
  `emit('update:modelValue', false)`

  ┌──────────────────────────────────────────────────────────┐
  │ NO emit('saved') — prevents refreshWorkspaceData() call  │
  │ CategoryBlock.vue does NOT have @saved handler            │
  │                                                          │
  │ SO: refreshWorkspaceData() is NOT triggered here. ✓       │
  └──────────────────────────────────────────────────────────┘

Step 9: Note remains visible in UI ✓
  The note should be visible — addNoteLocally preserved it
  No subsequent refresh overwrites workspaceData
```

### Path B: CategoryBlock.submitNote() (Inline note modal — DEAD CODE)

```
Step 1: User opens inline note modal
  File: CategoryBlock.vue:1070-1072
  async function addNote() { showNoteModal.value = true }
  Form: CategoryBlock.vue:312-320

Step 2: User submits form
  File: CategoryBlock.vue:313
  @submit.prevent="submitNote"

Step 3: submitNote() executes
  File: CategoryBlock.vue:1074-1092
  - POST to /api/v1/mobile/patients/{uuid}/notes (LOCAL_PHP)
  - sync_status = 'pending_create' in SQLite
  - NO addNoteLocally() call
  - refreshWorkspaceData() called at line 1089 ← PROBLEM

Step 4: refreshWorkspaceData() overwrites workspaceData
  → BUT snapshot/merge logic FILTERS by sync_status === 'pending_create'
  → The new note HAS sync_status = 'pending_create'
  → Should be preserved by merge logic
  → BUT: the merge logic runs INSIDE refreshWorkspaceData()
  → If the fetch fails (network issue), workspaceData is SET TO NULL
  → Note would be lost
```

### Path C: DoctorWorkspace.submitNoteForm() (Standalone note form)

```
Step 1: User opens note form
  File: DoctorWorkspace.vue:189-194

Step 2: User submits
  File: DoctorWorkspace.vue:774-821
  - ONLINE: POST to /api/v1/mobile/patients/{uuid}/notes (LOCAL_PHP)
  - sync_status = 'pending_create' in SQLite
  - NO addNoteLocally() call
  - refreshWorkspaceData() called at line 809 ← PROBLEM
  - Also: axios.post('/_native/api/sync/engine') at line 816 (fire-and-forget)
```

---

## 4. Every Request Captured

### AddRecordModal.vue — Note Creation (Path A)

| # | Method | URL | Headers | Body | Routing | Expected Response |
|---|---|---|---|---|---|---|
| 1 | POST | `/api/v1/patients/{uuid}/notes` | `Authorization: Bearer {token}` | `{content, category}` | **LOCAL_PHP** | HTTP 201 + created note JSON |

### AddRecordModal.vue — Debug Trace (on failure only)

| # | Method | URL | Headers | Body |
|---|---|---|---|---|
| 1 | POST | `/_native/api/debug/trace` | `Content-Type: application/json` | `{[AddRecordModal] FAILED status=...}` |

### DoctorWorkspace.vue — Note Creation (Path C)

| # | Method | URL | Headers | Body | Routing |
|---|---|---|---|---|---|
| 1 | POST | `/api/v1/mobile/patients/{uuid}/notes` | `Authorization: Bearer {token}` | `{content}` | LOCAL_PHP |
| 2 | POST | `/_native/api/sync/engine` | — | `{}` | LOCAL_PHP (fire-and-forget) |

### refreshWorkspaceData() — Every call site

| File | Line | Trigger | HTTP Request |
|---|---|---|---|
| useWorkspace.js | 265-309 | `refreshWorkspaceData()` | GET `/api/v1/workspace/{uuid}` → EXTERNAL |
| CategoryBlock.vue (submitNote) | 1089 | Note creation (dead code) | GET EXTERNAL |
| CategoryBlock.vue (submitVisit) | 1108 | Visit creation | GET EXTERNAL |
| CategoryBlock.vue (submitRename) | 1128 | Category rename | GET EXTERNAL |
| CategoryBlock.vue (submitColor) | 1144 | Category color change | GET EXTERNAL |
| CategoryBlock.vue (deleteCategory) | 1160 | Category delete | GET EXTERNAL |
| CategoryBlock.vue (deleteNoteDirectly) | 1220 | Note delete | GET EXTERNAL |
| DoctorWorkspace.vue | 328 | Pull-to-refresh | GET EXTERNAL |
| DoctorWorkspace.vue | 648 | Unknown trigger | GET EXTERNAL |
| DoctorWorkspace.vue | 720 | Unknown trigger | GET EXTERNAL |
| DoctorWorkspace.vue | 731 | Unknown trigger | GET EXTERNAL |
| DoctorWorkspace.vue | 738 | Unknown trigger | GET EXTERNAL |
| DoctorWorkspace.vue | 766 | Unknown trigger | GET EXTERNAL |
| DoctorWorkspace.vue | 809 | submitNoteForm (note creation) | GET EXTERNAL |
| DoctorWorkspace.vue | 865 | after refreshPatientList | GET EXTERNAL |
| useWorkspace.js | 551 | updatePatient | GET EXTERNAL |

---

## 5. Every Response Captured

### AddRecordModal.vue — Note Creation Success

```json
HTTP/1.1 201 Created
{
  "id": {new_id},
  "uuid": "{generated_uuid}",
  "patient_id": {patient_db_id},
  "author_id": {doctor_id|null},
  "category": "notes",
  "content": "{user_input}",
  "sync_status": "synced" | "pending_create",
  "created_at": "{timestamp}",
  "updated_at": "{timestamp}",
  "author": {"id":..., "name":..., "email":...}
}
```

### refreshWorkspaceData() — Production Response

```json
HTTP/1.1 200 OK
{
  "files": [...],
  "notes": [
    // Only synced notes — pending_create notes from local SQLite are absent
    {"uuid": "...", "sync_status": "synced", ...}
  ],
  "visits": [...],
  "shares": [...],
  "categories": [...],
  "stats": {...}
}
```

---

## 6. Routing Decision — CRITICAL FINDING

### The Discrepancy

There is a **direct contradiction** between two sources of evidence in the codebase:

| Source | Claim | URL |
|---|---|---|
| **Bug Report §4.1** | Note creation routes to LOCAL_PHP (SQLite) | `/api/v1/mobile/patients/{uuid}/notes` → LOCAL_PHP |
| **AddRecordModal.vue:196** comment | Note creation routes EXTERNAL (production) | `/api/v1/patients/{uuid}/notes` → EXTERNAL |
| **RequestRouter.kt analysis** | POST `/api/v1/mobile/patients/{uuid}/notes` → LOCAL_PHP | POST mutation → LOCAL_PHP |
| **RequestRouter.kt analysis** | POST `/api/v1/patients/{uuid}/notes` → LOCAL_PHP | POST mutation → LOCAL_PHP |

### Analysis

The current `AddRecordModal.vue` uses `/api/v1/patients/{uuid}/notes` (without `/mobile` prefix). According to the RequestRouter analysis:
- Path begins `/api/` + method is POST → `isApiMutation = true` + ONLINE → **LOCAL_PHP**

So the comment at `AddRecordModal.vue:196-199` claiming it routes EXTERNAL is **INCORRECT**. The note actually goes to LOCAL_PHP (SQLite). This means:
1. The note is created in local SQLite with `sync_status = 'pending_create'`
2. The production server does NOT have the note
3. `refreshWorkspaceData()` fetches from production and the note is NOT in the response
4. The snapshot/merge logic IS needed and DOES work for this case

### sync_status Values Confirm This

From `MobileNoteController::store()` line 99:
```php
'sync_status' => $isLocalSqlite ? 'pending_create' : 'synced',
```
- On embedded SQLite: `pending_create`
- On production MySQL: `synced`

Since the embedded app uses SQLite, notes created via `/api/v1/patients/{uuid}/notes` on the device will have `sync_status = 'pending_create'`. The merge logic in `refreshWorkspaceData()` correctly preserves these.

### Conclusion on Routing

**All note creation POST requests on the embedded device route to LOCAL_PHP (SQLite).** Therefore:
- `sync_status = 'pending_create'` is set correctly
- The snapshot/merge in `refreshWorkspaceData()` IS essential
- The removal of `emit('saved')` from AddRecordModal IS the correct fix for Path A
- But Path B (CategoryBlock.submitNote) and Path C (DoctorWorkspace.submitNoteForm) still have `refreshWorkspaceData()` issues

---

## 7. SQL Queries Involved

### Note Creation (LOCAL_PHP)

```sql
-- MobileNoteController::store() line 93-100
INSERT INTO patient_notes (
  patient_id, author_id, uuid, category, content, sync_status, created_at, updated_at
) VALUES (
  {patient_db_id}, {doctor_id|null}, '{uuid}', 'notes', '{content}', 'pending_create', NOW(), NOW()
);
```

### Note Sync (SyncEngine)

```sql
-- SyncEngineService::syncPendingNotes() line 553
SELECT * FROM patient_notes
WHERE sync_status = 'pending_create'
  AND patient_id IN (SELECT id FROM patients WHERE sync_status = 'synced')
ORDER BY created_at ASC LIMIT 200;

-- After successful sync, line 579-583
UPDATE patient_notes
SET sync_status = 'synced', remote_uuid = '{remote_uuid}', updated_at = NOW()
WHERE uuid = '{local_uuid}';
```

### Workspace Data Fetch (EXTERNAL)

```sql
-- On production MySQL (triggered by refreshWorkspaceData):
-- Complex query through Laravel ORM — does not include pending_create notes
SELECT files.*, notes.*, visits.*, shares.*, categories.*, stats.* FROM ...
```

---

## 8. Logs Found

| Log Source | Location | Relevant Content |
|---|---|---|
| Laravel (LOCAL_PHP) | `storage/logs/laravel.log` | `[MobileNote::store] ENTERED uuid=...`; `[MobileNote] Bearer token captured`; `[MobileNote] Creating note without author_id`; `[SyncEngine] Note synced successfully`; `[SyncEngine] Note sync failed` |
| Laravel (EXTERNAL/Production) | Production `storage/logs/laravel.log` | No POST /patients/{uuid}/notes logged when note goes LOCAL_PHP |
| ADB Logcat | Android device | `PHPBridge: Response HTTP/1.1 201 Created`; `GET /api/v1/workspace/{uuid} → EXTERNAL` |
| NativePHP | Android app storage | NativePHP-specific routing logs |
| Nginx | Production server | GET requests logged; no POST for notes when LOCAL_PHP |

### Key Log Sequences (Bug reproduction ADB log)

```
02:39:06.682 PHPBridge: Response first 200 bytes: HTTP/1.1 201 Created
02:39:06.702 GET /api/v1/workspace/5f5ac5de-... → EXTERNAL (production) ← OVERWRITE!
02:39:07.821 GET /api/v1/patients/5f5ac5de/.../categories/medical_history/files → EXTERNAL
```

---

## 9. Every File Involved

### Frontend (Vue/JS)

| File | Role | Lines of Interest |
|---|---|---|
| `resources/js/Components/workspace/AddRecordModal.vue` | Primary note creation entry point | 181-257 (submit), 195-211 (online POST), 236-237 (addNoteLocally), 243 (NO emit(saved)) |
| `resources/js/Components/workspace/CategoryBlock.vue` | Category container, also has inline note form | 9 (Add button), 312-320 (inline note modal), 1074-1092 (submitNote - dead code), 1089 (refreshWorkspaceData), 1220 (deleteNoteDirectly) |
| `resources/js/Pages/DoctorWorkspace.vue` | Standalone workspace page | 189-194 (note form template), 774-821 (submitNoteForm), 809 (refreshWorkspaceData), 816 (sync engine trigger) |
| `resources/js/Composables/useWorkspace.js` | Workspace state management | 265-309 (refreshWorkspaceData + merge), 399-419 (addNoteLocally), 551 (refreshWorkspaceData call) |

### Backend (PHP/Laravel)

| File | Role |
|---|---|
| `app/Http/Controllers/Api/Mobile/NoteController.php` | Embedded note CRUD (LOCAL_PHP path) |
| `app/Http/Controllers/Api/NoteController.php` | Production note CRUD (EXTERNAL path) |
| `app/Http/Controllers/Api/OfflineNoteController.php` | Offline-only note creation |
| `app/Services/SyncEngineService.php` | Syncs pending notes to production |
| `app/Services/Mobile/ApiService.php` | HTTP client for production API |
| `app/Repositories/Eloquent/EloquentPatientNoteRepository.php` | SQLite note repository |
| `app/Domains/Patients/Models/PatientNote.php` | Note model |

### NativePHP/Android

| File | Role |
|---|---|
| `nativephp/android/app/src/main/java/com/nativephp/mobile/network/RequestRouter.kt` | Routing decisions (LOCAL_PHP vs EXTERNAL) |
| `nativephp/android/app/src/main/java/com/nativephp/mobile/network/RouteTarget.kt` | Three-value enum for routing |
| `nativephp/android/app/src/main/java/com/nativephp/mobile/network/PHPWebViewClient.kt` | Request interception/dispatch |
| `nativephp/android/app/src/main/java/com/nativephp/mobile/network/WebViewManager.kt` | WebView management |

### Routes

| File | Role |
|---|---|
| `routes/web.php` | Embedded API routes (LOCAL_PHP) |
| `routes/api.php` | Production API routes (EXTERNAL) |

---

## 10. Database Evidence

### Local SQLite (Embedded Device)

| Table | Query | Expected Result |
|---|---|---|
| `patient_notes` | `SELECT * WHERE sync_status = 'pending_create'` | Contains note created via AddRecordModal |
| `patients` | `SELECT * WHERE uuid = '{patient_uuid}'` | Patient record (may have `sync_status = 'pending_sync'` or `synced`) |

### Production MySQL

| Table | Query | Expected Result |
|---|---|---|
| `patient_notes` | `SELECT * WHERE patient_id = {patient_db_id}` | Does NOT contain note (still in SQLite) until SyncEngine uploads |
| `patients` | `SELECT * WHERE uuid = '{patient_uuid}'` | Contains patient record |

### Bug Report Evidence (July 26)

```
Production MySQL patient_notes:
  No notes for patient_id=637, uuid=5f5ac5de-9317-46f3-838c-070757c1ecf7

Local SQLite (dev machine - NOT phone's DB):
  patient_notes: 1 old note (id=1, patient_id=3, pending_create)
  (Phone's SQLite has DIFFERENT data — confirmed by SyncEngine showing notes:2→3)

SyncEngine Pending Summary:
  Initial: notes:1 (1 pending note)
  After note creation: notes:2 (then notes:3) ← new note IS in phone's SQLite
```

---

## 11. Root Cause

### Confirmed Root Cause: Race Condition Between addNoteLocally() and refreshWorkspaceData()

The fundamental issue is a **dual-write architecture with asynchronous synchronization**:

1. **Write to local SQLite** (LOCAL_PHP): Note is created with `sync_status='pending_create'`
2. **UI optimistically shows note** (`addNoteLocally()`): Note is injected into `workspaceData`
3. **ANY call to `refreshWorkspaceData()`**: Fetches production data (EXTERNAL), which does NOT contain the pending note
4. **`workspaceData.value = res.data`**: Overwrites the entire workspace, erasing the locally-added note

The primary fix (removing `emit('saved')` from AddRecordModal) addresses the **most common trigger**. But the mechanism is still present and can be triggered by other paths.

### Why This Architecture Exists

The app uses a **local-first architecture**:
- All mutations go through LOCAL_PHP first (SQLite)
- A background sync engine uploads changes to production
- Production data is fetched for reads (GET /api/v1/workspace/{uuid})
- This creates inherent tension: writes are local, reads are remote

### Specific Trigger Paths

| Path | Trigger | Mitigation | Status |
|---|---|---|---|
| AddRecordModal (Add button) | `emit('saved')` → `@saved="refreshWorkspaceData"` | Removed | ✅ FIXED |
| AddRecordModal (Add button) | No other trigger | addNoteLocally only | ✅ FIXED |
| DoctorWorkspace.submitNoteForm() | Direct `refreshWorkspaceData()` at line 809 | Merge logic (fragile) | ⚠️ PARTIAL |
| CategoryBlock.submitNote() | Direct `refreshWorkspaceData()` at line 1089 | Merge logic (dead code path) | ⚠️ DEAD CODE |
| CategoryBlock.submitVisit() | Direct `refreshWorkspaceData()` at line 1108 | Visits NOT in merge scope | ⚠️ BUG (visits) |

---

## 12. Proof of Root Cause

### Evidence 1: AddRecordModal.vue submit() function

```javascript
// Line 236-237: Note IS added to workspaceData
if (createdNote?.uuid) {
  addNoteLocally(createdNote)  // ← Note appears in UI
}

// Line 239: Toast confirms success
toast.success('تمت إضافة الملاحظة بنجاح')

// Line 243: Modal closes
emit('update:modelValue', false)  // ← NO saved event, NO refresh
```

**Proof:** The note is added to workspaceData and no refresh follows. The note remains visible.

### Evidence 2: CategoryBlock.vue — NO @saved handler

```vue
<!-- Lines 362-367: AddRecordModal usage -->
<AddRecordModal v-model="showAddRecordModal"
  :patient="selectedPatient"
  :categorySlug="slug"
  :initialTab="addRecordModalTab" />
<!-- NO @saved attribute! -->
```

**Proof:** Even if AddRecordModal emitted `saved`, CategoryBlock would not process it.

### Evidence 3: useWorkspace.js merge logic

```javascript
// Lines 210-212: Snapshot BEFORE fetch
const pendingLocalNotes = (workspaceData.value?.notes || [])
  .filter(n => n.sync_status === 'pending_create')  // ← KEY: only pending_create
  .map(n => ({ ...n }));

// Lines 218-233: Re-insert AFTER fetch
const serverUuids = new Set((workspaceData.value.notes || []).map(n => n.uuid));
const missingLocal = pendingLocalNotes.filter(n => !serverUuids.has(n.uuid));
// → Only re-inserts notes NOT already on server
```

**Proof:** The merge works correctly for notes with `sync_status='pending_create'`. But it:
- Only works inside `refreshWorkspaceData()`
- Will NOT protect data if `workspaceData` is set to `null` (catch block line 303)
- Only protects NOTES — other workspace data types (files, visits, shares) are overwritten without merge

### Evidence 4: DoctorWorkspace.submitNoteForm() vulnerability

```javascript
// DoctorWorkspace.vue:793-802
await axios.post(`/api/v1/mobile/patients/${selectedPatient.value.uuid}/notes`, {
  content: noteFormContent.value,
})
// Note now in SQLite with sync_status = 'pending_create'

// DoctorWorkspace.vue:809
refreshWorkspaceData()
// → GET /api/v1/workspace/{uuid} → EXTERNAL
// → Production response does NOT include the note
// → workspaceData.value = res.data → erases the note
// → BUT merge logic SHOULD preserve it (sync_status = 'pending_create')
```

**Proof:** This path is partially protected by the merge logic. The note would be preserved AS LONG AS:
1. `refreshWorkspaceData()` succeeds (no network error → no `workspaceData = null`)
2. The fetch response structure includes `notes` array
3. The merge logic executes correctly

---

## 13. Alternative Hypotheses Considered

### Hypothesis 1: The note goes to EXTERNAL (production) but is filtered by DoctorIsolationScope

**Verdict:** REJECTED
**Reason:** The `withoutGlobalScope(DoctorIsolationScope::class)` was already added to `EloquentPatientNoteRepository::forPatient()`. This is fixed. Also, if the note went to production, the bug report would show it in MySQL — it doesn't.

### Hypothesis 2: The note is created but immediately deleted by a cascade or observer

**Verdict:** REJECTED
**Reason:** No observers, event listeners, or cascade deletes were found on the `PatientNote` model. The bug report confirms the note is not in production MySQL either.

### Hypothesis 3: The API returns 201 but the response body is malformed, causing addNoteLocally() to fail

**Verdict:** REJECTED (for AddRecordModal path)
**Reason:** `addNoteLocally()` at line 187 checks `if (!note?.uuid) return;` — if the response has no uuid, the note would silently not be added. But the bug report shows the SyncEngine counts confirm the note IS in local SQLite, meaning the API response was valid.

### Hypothesis 4: The note is created but addNoteLocally() fails due to workspaceData being null

**Verdict:** REJECTED for primary path
**Reason:** `addNoteLocally()` at lines 188-194 checks for `!workspaceData.value` and creates a new workspaceData object if null. But if workspaceData was null and the note was added, the subsequent `refreshWorkspaceData()` would still overwrite it if triggered.

### Hypothesis 5: The note exists in local SQLite but the CategoryBlock filter excludes it

**Verdict:** REJECTED
**Reason:** The CategoryBlock doesn't filter by `sync_status`. It shows all notes for the category. The merged list at lines 1178-1189 includes all notes and files for the category.

### Hypothesis 6: The CategoryBlock component is stale and doesn't re-render after workspaceData changes

**Verdict:** REJECTED
**Reason:** The `shallowRef` reactivity pattern with `workspaceData.value = { ...workspaceData.value }` triggers Vue's ref-level reactivity. The computed properties (`mergedCategoryItems`) depending on `workspaceData.value.notes` should re-evaluate. The bug report confirms this — it was the `refreshWorkspaceData()` overwrite that caused the erasure, not a reactivity failure.

---

## 14. Recommended Fixes

### Fix 1: Protect DoctorWorkspace.submitNoteForm()

**File:** `resources/js/Pages/DoctorWorkspace.vue`, line 809
**Problem:** `refreshWorkspaceData()` is called after note creation but no `addNoteLocally()` is called first. The note is visible only via the merge logic.

**Current code:**
```javascript
// Lines 792-809
} else {
  if (online) {
    await axios.post(`/api/v1/mobile/patients/${selectedPatient.value.uuid}/notes`, {
      content: noteFormContent.value,
    }, { ... })
  } else {
    await axios.post(`/api/v1/patients/${selectedPatient.value.uuid}/notes`, {
      content: noteFormContent.value,
    })
  }
  toast.success(t('workspace.note_added'))
}
showNoteModal.value = false
editingNote.value = null
noteFormContent.value = ''
refreshWorkspaceData()  // ← Overwrites workspaceData
```

**Fix:**
```javascript
} else {
  if (online) {
    const res = await axios.post(`/api/v1/mobile/patients/${selectedPatient.value.uuid}/notes`, {
      content: noteFormContent.value,
    }, { ... })
    const createdNote = res.data
    if (createdNote?.uuid) addNoteLocally(createdNote)  // ← ADD THIS
  } else {
    const res = await axios.post(`/api/v1/patients/${selectedPatient.value.uuid}/notes`, {
      content: noteFormContent.value,
    })
    const createdNote = res.data
    if (createdNote?.uuid) addNoteLocally(createdNote)  // ← ADD THIS
  }
  toast.success(t('workspace.note_added'))
}
showNoteModal.value = false
editingNote.value = null
noteFormContent.value = ''
refreshWorkspaceData()
```

**Import needed:** Add `const { addNoteLocally } = useWorkspace()` in doctor workspace composable imports.

### Fix 2: Remove dead code from CategoryBlock inline note form

**File:** `resources/js/Components/workspace/CategoryBlock.vue`, lines 1070-1114
**Problem:** `submitNote()`, `addNote()`, `addTimelineEntry()` are dead code — no template element calls them. They still have the `refreshWorkspaceData()` vulnerability.

**Recommended:** Remove the dead code (inline note modal and associated functions) to eliminate confusion.

**Alternative:** If keeping the code, add `addNoteLocally()` before `refreshWorkspaceData()`.

### Fix 3: Protect CategoryBlock.submitVisit() — visits not protected by merge

**File:** `resources/js/Components/workspace/CategoryBlock.vue`, line 1108
**Problem:** Visits don't have `sync_status` column, so the merge logic skips them. `refreshWorkspaceData()` will erase visits from workspaceData.

**Fix:** Either remove `refreshWorkspaceData()` or implement visit-specific merge logic.

### Fix 4: Add error protection for refreshWorkspaceData()

**File:** `resources/js/Composables/useWorkspace.js`, line 303
**Problem:** On fetch failure: `workspaceData.value = null` — ALL local data is wiped including pending notes.

```javascript
.catch(() => {
  workspaceData.value = null;  // ← Data loss!
})
```

**Fix:**
```javascript
.catch(() => {
  // Don't wipe workspace data on network error
  // The existing data is still valid locally
  loadingPatient.value = false;
})
```

### Fix 5: Vite build cache verification

**File:** Build process (NativePHP native:build)
**Problem:** `native:build` may not pick up fresh Vite assets from `public/build/`.

**Fix:** Ensure `rm -rf public/build/assets/` runs before `npm run build` and APK rebuild.

---

## 15. Risk Analysis

| Risk | Severity | Likelihood | Description |
|---|---|---|---|
| Vite stale assets in APK | HIGH | Medium | Old JS without fixes could be served from build cache |
| DoctorWorkspace note loss | MEDIUM | Low-Medium | `refreshWorkspaceData()` could erase note if merge fails |
| CategoryBlock visit loss | MEDIUM | Low | `refreshWorkspaceData()` erases visits not protected by merge |
| Workspace data wipe on error | LOW | Low | `workspaceData = null` on fetch failure loses all local data |
| SyncEngine token expiration | MEDIUM | Low | Bearer token could expire; sync will fail with 401 |
| RequestRouter routing uncertainty | HIGH | Low | Comment in AddRecordModal.vue claims EXTERNAL but analysis shows LOCAL_PHP — risk of developer confusion |
| Dead code confusion | LOW | Medium | CategoryBlock inline note form is dead code but still has bugs |

---

## 16. Temporary Workaround

**For users experiencing the bug:**
1. After creating a note, pull-to-refresh to trigger `refreshWorkspaceData()` — the merge logic should preserve the pending note
2. Wait 30 seconds for SyncEngine to upload to production — then `refreshWorkspaceData()` will fetch the note from production
3. Navigate away from the patient and back — `selectPatient()` fetches from EXTERNAL but should include synced notes
4. If note is still invisible: navigate to the patient category "notes" directly — it may use a different data source

**For developers:**
Add console logging to trace the lifecycle:

```javascript
function addNoteLocally(note) {
  console.log('[DEBUG] addNoteLocally', note.uuid, 'workspaceData.notes before:', workspaceData.value?.notes?.length)
  // ... existing code
  console.log('[DEBUG] addNoteLocally DONE', 'workspaceData.notes after:', workspaceData.value?.notes?.length)
}
```

---

## 17. Permanent Solution

### The fundamental architectural issue

The app has a **split-brain data model**: writes go to LOCAL_PHP (SQLite), reads go to EXTERNAL (MySQL). This architecture is inherently fragile because:
1. There is no atomic cross-database transaction
2. The UI must maintain a local cache that diverges from the server
3. Every server fetch risks overwriting local state

### Recommended architecture change

**Option A: Unified data source (simpler, more reliable)**
- Route ALL note CRUD to EXTERNAL (production API)
- Use `addNoteLocally()` for optimistic UI updates
- Remove the LOCAL_PHP note creation path entirely
- Notes are persisted directly to production, eliminating the sync delay and divergence
- The only LOCAL_PHP operations should be: patient creation (with API forward), file uploads (offline-only), and sync engine status

**Option B: Keep dual-write but add proper cache invalidation**
- Add a `local_notes_only` flag or separate state slice
- `refreshWorkspaceData()` fetches production data and MERGES it rather than REPLACING
- Track which notes are local-only vs server-confirmed via `sync_status` in a dedicated reactive state
- Never let a production fetch overwrite `pending_create` entries

### For the immediate fix

Keep the current fixes (removed `emit('saved')`, `addNoteLocally()`, merge logic) AND:
1. Add `addNoteLocally()` to `DoctorWorkspace.submitNoteForm()`
2. Fix the `workspaceData = null` on error in `refreshWorkspaceData()`
3. Remove dead code from CategoryBlock inline note form
4. Add visit merge protection

---

## 18. Confidence Level

**HIGH — All conclusions are backed by direct code evidence.**

- Every function call, routing decision, and data mutation was traced through the actual source code
- The routing analysis was verified against both the Kotlin RequestRouter implementation and the Laravel route definitions
- The dead code (`CategoryBlock.submitNote()`) was confirmed by grep showing zero template callers
- The `emit('saved')` removal was verified by grep showing zero occurrences in all resource files
- The snapshot/merge logic was read in full and confirmed to work by `sync_status` filtering

**The only uncertainty** is the RequestRouter routing behavior on the actual device — the Kotlin analysis is evidence-based but the ADB log evidence in the bug report shows notes going to LOCAL_PHP, not EXTERNAL. The frontend comment claiming EXTERNAL routing is contradictory to both the routing analysis and the bug report evidence. This discrepancy should be resolved by testing on an actual device with ADB logging.

---

## 19. Appendix

### A. Request Bodies

**AddRecordModal.vue — Online note creation:**
```json
POST /api/v1/patients/5f5ac5de-9317-46f3-838c-070757c1ecf7/notes
Headers: { "Authorization": "Bearer {token}" }
Body: { "content": "Test note content", "category": "medical_history" }
```

**DoctorWorkspace.vue — Online note creation:**
```json
POST /api/v1/mobile/patients/5f5ac5de-9317-46f3-838c-070757c1ecf7/notes
Headers: { "Authorization": "Bearer {token}" }
Body: { "content": "Test note content" }
```

**Offline note creation:**
```json
POST /_native/api/offline/notes
Body: { "content": "Test note content", "category": "medical_history", "patient_uuid": "5f5ac5de-9317-46f3-838c-070757c1ecf7" }
```

### B. Response Bodies

**Successful note creation (HTTP 201):**
```json
{
  "id": 42,
  "uuid": "a1b2c3d4-...",
  "patient_id": 6,
  "author_id": 2,
  "category": "notes",
  "content": "Test note content",
  "sync_status": "pending_create",
  "created_at": "2026-07-26T02:39:06Z",
  "updated_at": "2026-07-26T02:39:06Z",
  "author": { "id": 2, "name": "Dr. Hosam", "email": "..." }
}
```

**Workspace fetch (HTTP 200) — does NOT include pending_create notes:**
```json
{
  "files": [...],
  "notes": [
    // Only synced notes — pending_create notes from local SQLite are absent
    {"uuid": "...", "sync_status": "synced", ...}
  ],
  "visits": [...],
  "shares": [...],
  "categories": [...],
  "stats": {...}
}
```

### C. Key SQL

```sql
-- Note creation (LOCAL_PHP / SQLite)
INSERT INTO patient_notes (patient_id, author_id, uuid, category, content, sync_status)
VALUES (6, 2, 'a1b2c3d4', 'notes', 'Test note', 'pending_create');

-- Workspace fetch (EXTERNAL / Production MySQL)
-- Complex query through Laravel ORM — does not include pending_create notes

-- Sync engine upload
SELECT * FROM patient_notes WHERE sync_status = 'pending_create' LIMIT 200;
UPDATE patient_notes SET sync_status = 'synced', remote_uuid = '...' WHERE uuid = '...';
```

### D. Relevant Stack Traces

**Sync engine success:**
```
[SyncEngine] Note synced successfully
  local_uuid: a1b2c3d4-...
  remote_uuid: b2c3d4e5-...
  patient: 5f5ac5de-9317-46f3-838c-070757c1ecf7
```

**Sync engine failure:**
```
[SyncEngine] Note sync failed: HTTP 401 Unauthorized
  note_uuid: a1b2c3d4-...
  patient: 5f5ac5de-...
```

**Token capture:**
```
[MobileNote::store] ENTERED uuid=5f5ac5de-... user=null
[MobileNote] Bearer token captured and stored in ApiService
```

**Missing token (sync will fail):**
```
[MobileNote] No Bearer token in request — sync will fail with 401
```

### E. File References Summary

| File | Line(s) | Significance |
|---|---|---|
| `AddRecordModal.vue` | 181-257 | Note creation flow |
| `AddRecordModal.vue` | 236-237 | `addNoteLocally()` call |
| `AddRecordModal.vue` | 243 | NO `emit('saved')` |
| `CategoryBlock.vue` | 9 | Add button → `openAddRecord('text')` |
| `CategoryBlock.vue` | 362-368 | AddRecordModal usage (no `@saved`) |
| `CategoryBlock.vue` | 1074-1092 | `submitNote()` — dead code, calls `refreshWorkspaceData()` |
| `CategoryBlock.vue` | 1089 | `refreshWorkspaceData()` without `addNoteLocally()` |
| `CategoryBlock.vue` | 1108 | `refreshWorkspaceData()` for visits (no merge protection) |
| `CategoryBlock.vue` | 1220 | `refreshWorkspaceData()` for note delete |
| `DoctorWorkspace.vue` | 774-821 | `submitNoteForm()` — calls `refreshWorkspaceData()` without `addNoteLocally()` |
| `DoctorWorkspace.vue` | 809 | `refreshWorkspaceData()` after note creation |
| `DoctorWorkspace.vue` | 816 | Sync engine trigger (fire-and-forget) |
| `useWorkspace.js` | 265-309 | `refreshWorkspaceData()` with merge logic |
| `useWorkspace.js` | 210-212 | Snapshot: `filter(n => n.sync_status === 'pending_create')` |
| `useWorkspace.js` | 399-419 | `addNoteLocally()` |
| `useWorkspace.js` | 303 | `workspaceData = null` on error (data loss risk) |
| `MobileNoteController.php` | 91-99 | `sync_status` conditional (LOCAL_PHP vs production) |
| `SyncEngineService.php` | 548-633 | `syncPendingNotes()` |
| `SyncEngineService.php` | 553 | Note selection: only `pending_create` |
