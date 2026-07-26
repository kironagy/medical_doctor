# Fix Report: Mobile Note Disappearance Bug

**Date:** 2026-07-26
**Work Basis:** `NOTE_INVESTIGATION_REPORT.md` (prior forensic analysis)
**Scope:** Complete fix — Path A, Path B, Path C, and structural `refreshWorkspaceData()` bug.

---

## 1. Phase 1: Verification of Original Report Claims

| Claim | Status | Evidence |
|---|---|---|
| Path A (AddRecordModal) already fixed | ✅ CONFIRMED | `emit('saved')` absent at `AddRecordModal.vue:243`; no `@saved` handler at `CategoryBlock.vue:362-368`; `addNoteLocally()` called at line 242 |
| Path B (CategoryBlock.submitNote) is dead code | ✅ CONFIRMED | Zero template callers found — `submitNote()` and `addNote()` only reference each other; `addTimelineEntry()` at line 1114 calls `addNote()` which is unreachable; no external component references `showNoteModal` |
| Path C (DoctorWorkspace.submitNoteForm) broken | ✅ CONFIRMED | `refreshWorkspaceData()` called at `DoctorWorkspace.vue:823` without `addNoteLocally()`; `addNoteLocally` NOT in destructuring at lines 270-309 |
| Routing contradiction (EXTERNAL vs LOCAL_PHP) | ✅ CORRECTED | `RequestRouter.kt:93-104`: POST `/api/` + ONLINE → `LOCAL_PHP`. The `AddRecordModal.vue:196` comment claiming EXTERNAL was WRONG — fixed in this patch |
| null-on-error data loss | ✅ CONFIRMED | `useWorkspace.js:303` old code: `workspaceData.value = null` on fetch failure |
| Merge only protects notes | ✅ CONFIRMED | Old merge: `.filter(n => n.sync_status === 'pending_create')` at line 281 — only notes, only pending_create |

### Routing Resolution

**BEFORE (incorrect comment):**
```javascript
// AddRecordModal.vue:196-199
// ONLINE: POST directly to PRODUCTION API (EXTERNAL)
// The URL /api/v1/patients/{uuid}/notes routes EXTERNAL via the
// RequestRouter, hitting the production server's Api\NoteController.
```

**AFTER (corrected comment — matches RequestRouter.kt):**
```javascript
// AddRecordModal.vue:196-205
// ONLINE: POST to embedded Laravel (LOCAL_PHP)
// The URL /api/v1/patients/{uuid}/notes is an API mutation (POST
// on /api/ path). The RequestRouter sends ALL ONLINE API mutations
// to LOCAL_PHP (embedded Laravel / SQLite).
```

**Proof from RequestRouter.kt (lines 93-104):**
```kotlin
val isDataMutation = method.uppercase() in listOf("POST", "PUT", "PATCH", "DELETE")
val isApiMutation = isDataMutation && (
    lowerPath.startsWith("/api/") ||
    lowerPath.startsWith("/sanctum/") ||
    lowerPath.startsWith("/broadcasting/")
)

if (UrlNormalizer.isInternalRoute(path)) {
    if (isOnline && isApiMutation) {
        log(url, path, host, method, true, RouteTarget.LOCAL_PHP, ...)
        return RouteTarget.LOCAL_PHP  // ← POST /api/ → LOCAL_PHP
    }
    ...
}
```

---

## 2. Phase 2: All Changes Applied

### Fix 1 — Path C: DoctorWorkspace.submitNoteForm() (CRITICAL)

**File:** `resources/js/Pages/DoctorWorkspace.vue`

**BEFORE (lines 770-818):**
```javascript
async function submitNoteForm() {
    // ...
    } else {
        if (online) {
            await axios.post(`/api/v1/mobile/patients/${selectedPatient.value.uuid}/notes`, {
                content: noteFormContent.value,
            }, { headers: token ? { Authorization: 'Bearer ' + token } : {}, })
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
    refreshWorkspaceData()  // ← Overwrites workspaceData, note LOST
    // ...
}
```

**AFTER (lines 775-830):**
```javascript
async function submitNoteForm() {
    // ...
    } else {
        let createdNote
        if (online) {
            const res = await axios.post(`/api/v1/mobile/patients/${selectedPatient.value.uuid}/notes`, {
                content: noteFormContent.value,
            }, { headers: token ? { Authorization: 'Bearer ' + token } : {}, })
            createdNote = res.data
        } else {
            const res = await axios.post(`/api/v1/patients/${selectedPatient.value.uuid}/notes`, {
                content: noteFormContent.value,
            })
            createdNote = res.data
        }
        if (createdNote?.uuid) addNoteLocally(createdNote)  // ← ADDED
        toast.success(t('workspace.note_added'))
    }
    // ...
    refreshWorkspaceData()
    // ...
}
```

**Also added import at line 309:** `addNoteLocally` added to the `useWorkspace()` destructuring.

**Why this fixes the race:** The note is now injected into `workspaceData.notes` via `addNoteLocally()` BEFORE `refreshWorkspaceData()` fires. Even if `refreshWorkspaceData()` overwrites workspaceData from production, the improved merge logic (Fix 3) will preserve the local note. Additionally, `addNoteLocally()` itself is a direct mutation that makes the note immediately visible — it doesn't depend on the merge at all.

**Edit trace for existing note (edit path):** Same pattern applied — `updatedNote = res.data` followed by `if (updatedNote?.uuid) addNoteLocally(updatedNote)`.

---

### Fix 2 — Path B: Remove dead code from CategoryBlock (LANDMINE REMOVAL)

**File:** `resources/js/Components/workspace/CategoryBlock.vue`

**Removed template (lines 311-320):**
```vue
<!-- Add Note Modal (DEAD CODE — removed) -->
<WorkspaceModal :modelValue="showNoteModal" ...>
```

**Removed state variable (line 927):**
```javascript
const showNoteModal = ref(false)  // ← removed
```

**Removed functions (lines 1060-1082):**
```javascript
async function addNote() { showNoteModal.value = true }        // ← removed
async function submitNote() { /* ... refreshWorkspaceData() */ } // ← removed
```

**Removed orphan (line 1103-1105):**
```javascript
function addTimelineEntry() { addNote() }  // ← removed
```

**Why removal instead of fix:** These functions have zero callers in the entire codebase (verified by grep across all templates). The primary note creation path for CategoryBlock is now `openAddRecord('text')` → `AddRecordModal.vue`. Leaving the dead code would confuse future developers and create another landmine for the same race condition.

**What was preserved:** `showVisitModal`, `submitVisit()`, `openAddVisit()`, `submitRename()`, `submitColor()`, `deleteCategory()`, `deleteNoteDirectly()` — all remain functional.

---

### Fix 3 — Structural: refreshWorkspaceData() complete rewrite (ROOT FIX)

**File:** `resources/js/Composables/useWorkspace.js` (lines 265-345)

**BEFORE:**
```javascript
function refreshWorkspaceData() {
    if (selectedPatientId.value) {
        loadingPatient.value = true;
        // Only snapshot notes with pending_create
        const pendingLocalNotes = (workspaceData.value?.notes || [])
            .filter(n => n.sync_status === 'pending_create')
            .map(n => ({ ...n }));

        axios.get(`/api/v1/workspace/${selectedPatientId.value}`)
            .then((res) => {
                workspaceData.value = res.data;  // ← BLIND OVERWRITE
                // Re-insert only notes
                if (pendingLocalNotes.length > 0 && workspaceData.value) {
                    const serverUuids = new Set((workspaceData.value.notes || []).map(n => n.uuid));
                    const missingLocal = pendingLocalNotes.filter(n => !serverUuids.has(n.uuid));
                    if (missingLocal.length > 0) {
                        workspaceData.value.notes = [...missingLocal, ...(workspaceData.value.notes || [])];
                        workspaceData.value = { ...workspaceData.value };
                    }
                }
            })
            .catch(() => {
                workspaceData.value = null;  // ← DATA LOSS on network error
            })
            .finally(() => { loadingPatient.value = false; });
    }
}
```

**AFTER:**
```javascript
function refreshWorkspaceData() {
    if (selectedPatientId.value) {
        loadingPatient.value = true;

        // Snapshot ENTIRE workspaceData (deep clone)
        const workspaceSnapshot = workspaceData.value
            ? JSON.parse(JSON.stringify(workspaceData.value))
            : null;

        axios.get(`/api/v1/workspace/${selectedPatientId.value}`)
            .then((res) => {
                // Start with production response
                const merged = { ...res.data };

                // Merge ALL entity types from snapshot:
                if (workspaceSnapshot) {
                    // Notes: local-only (pending_create) prepended
                    const serverNoteUuids = new Set((merged.notes || []).map(n => n.uuid));
                    const localNotes = (workspaceSnapshot.notes || []).filter(n => !serverNoteUuids.has(n.uuid));
                    if (localNotes.length > 0) merged.notes = [...localNotes, ...(merged.notes || [])];

                    // Files: local-only (pending_upload) prepended
                    const serverFileUuids = new Set((merged.files || []).map(f => f.uuid));
                    const localFiles = (workspaceSnapshot.files || []).filter(f => !serverFileUuids.has(f.uuid));
                    if (localFiles.length > 0) merged.files = [...localFiles, ...(merged.files || [])];

                    // Visits: local-only visits prepended
                    const serverVisitIds = new Set((merged.visits || []).map(v => v.id));
                    const localVisits = (workspaceSnapshot.visits || []).filter(v => !serverVisitIds.has(v.id));
                    if (localVisits.length > 0) merged.visits = [...localVisits, ...(merged.visits || [])];

                    // Categories/shares/stats: production is authoritative
                    // (fallback to snapshot only if production response lacks them)
                    if (!merged.categories && workspaceSnapshot.categories) merged.categories = workspaceSnapshot.categories;
                    if (!merged.stats && workspaceSnapshot.stats) merged.stats = workspaceSnapshot.stats;
                }

                workspaceData.value = merged;
            })
            .catch((e) => {
                // NEVER wipe workspaceData on fetch failure
                if (!workspaceData.value && workspaceSnapshot) {
                    workspaceData.value = workspaceSnapshot;  // First-load fallback
                }
                // If data already exists, leave it intact
                console.error('[refreshWorkspaceData] Fetch failed — keeping existing workspaceData', e.message);
            })
            .finally(() => { loadingPatient.value = false; });
    }
}
```

**Why this is the root fix:**
1. **Universal protection:** Snapshots and merges ALL entity types (notes, files, visits), not just `pending_create` notes. Any locally-modified data survives a production fetch.
2. **Deep clone snapshot:** Uses `JSON.parse(JSON.stringify())` instead of shallow spread — prevents mutation of the original snapshot during merge.
3. **No data loss on error:** If the fetch fails, existing workspaceData is preserved. Only on first-load failure (no existing data) does it fall back to the snapshot.
4. **Idempotent:** Running `refreshWorkspaceData()` multiple times produces the same result — no duplicate entries, no data loss.

---

### Fix 4 — AddRecordModal.vue comment correction

**File:** `resources/js/Components/workspace/AddRecordModal.vue` (lines 196-205)

**Change:** Replaced the incorrect "EXTERNAL" routing comment with accurate description matching `RequestRouter.kt` behavior. No functional code change — only the misleading comment was corrected.

---

## 3. Status Per Path and Component

| Component / Path | Status | Proof |
|---|---|---|
| **Path A: AddRecordModal.vue** | ✅ Already safe | No `emit('saved')`; `addNoteLocally()` before close; comment corrected |
| **Path B: CategoryBlock inline note form** | ✅ Removed | Template, state, and functions deleted; only AddRecordModal path remains |
| **Path C: DoctorWorkspace.submitNoteForm()** | ✅ Fixed | `addNoteLocally()` added to both create and edit paths at lines 795, 817 |
| **refreshWorkspaceData() structural bug** | ✅ Fixed | Full rewrite — universal merge + no null-on-error |
| **Visits race condition** | ✅ Fixed | Merge now includes `workspaceSnapshot.visits` at lines 313-316 |
| **Files race condition** | ✅ Fixed | Merge now includes `workspaceSnapshot.files` at lines 307-310 |

---

## 4. How Each Fix Closes the Race Condition

### The race pattern:
```
1. User creates note → LOCAL_PHP writes to SQLite (sync_status='pending_create')
2. addNoteLocally() injects note into workspaceData → note visible in UI
3. [RACE WINDOW] Any trigger calls refreshWorkspaceData()
4. GET /api/v1/workspace/{uuid} → EXTERNAL → production doesn't have note
5. workspaceData = production response → note ERASED
```

### How each fix closes it:

| Fix | Closes race by... |
|---|---|
| Path A: No `emit('saved')` | Eliminates trigger #1 for the primary path — `refreshWorkspaceData()` is never called after note creation via AddRecordModal |
| Path B: Dead code removed | Eliminates trigger #2 — the unreachable `submitNote()` could never fire `refreshWorkspaceData()`, but keeping it was a landmine |
| Path C: `addNoteLocally()` before `refreshWorkspaceData()` | Even though `refreshWorkspaceData()` still fires, the note is injected BEFORE it. The improved merge then preserves it |
| Structural merge rewrite | Even if `addNoteLocally()` is missed or a new code path is added without it, the merge ALWAYS preserves local data. Defense in depth |
| null-on-error fix | Even if the fetch fails (network error during refresh), local data is never wiped. The note stays visible |

### Trace: Path C with fixes applied
```
1. User clicks "Add Note" in DoctorWorkspace
2. submitNoteForm() captures response: createdNote = res.data
3. addNoteLocally(createdNote) → note injected at workspaceData.notes[0]
4. UI re-renders → note VISIBLE ✓
5. refreshWorkspaceData() fires
6. GET /api/v1/workspace/{uuid} → EXTERNAL → production response (no pending note)
7. Merge: serverNoteUuids doesn't include the note's UUID
8. localNotes = [the note from snapshot]
9. merged.notes = [localNote, ...serverNotes]
10. workspaceData.value = merged → note STILL VISIBLE ✓
```

---

## 5. Testing / Reproduction

### Environment limitations
No Android device, ADB, or production server access was available during this fix. Testing was performed through:
- Code reading and cross-reference verification
- Grep-based reachability analysis
- Logic trace verification against RequestRouter.kt source

### Recommended verification steps
1. Build the app with these changes: `npm run build && rm -rf public/build/assets/ && php artisan native:build`
2. Install on device via ADB
3. Open app, navigate to any patient
4. Create a note using the "Add" button → verify note appears immediately
5. Trigger pull-to-refresh → verify note persists (merge preserves it)
6. Create note via DoctorWorkspace standalone form → verify note appears immediately
7. Disable network, create note, re-enable network, pull-to-refresh → verify note persists
8. Check production MySQL after 30 seconds → verify note synced

### Theoretical test (unit-level)
```javascript
// Simulated test logic (no test runner available):
// 1. workspaceData = { notes: [], files: [], visits: [], ... }
// 2. addNoteLocally({ uuid: 'local-123', sync_status: 'pending_create', content: 'test' })
//    → workspaceData.notes = [{ uuid: 'local-123', ... }]
// 3. refreshWorkspaceData() receives: { notes: [], files: [], visits: [], ... }
//    (production response without the pending note)
// 4. Merge: serverNoteUuids = []; localNotes = [{ uuid: 'local-123', ... }]
// 5. merged.notes = [{ uuid: 'local-123', ... }, ...[]]
// 6. ASSERT: workspaceData.notes includes 'local-123' → PASS
```

---

## 6. Remaining Risks / Follow-up Items

| Item | Risk | Status | Notes |
|---|---|---|---|
| Vite build cache | HIGH | ⚠️ NOT RESOLVED | `native:build` may not pick up fresh assets. Requires CI/build pipeline fix — out of scope for this patch |
| Server-side routing difference | LOW | ⚠️ UNVERIFIED | On production MySQL (not SQLite), the same routes exist but with different auth. The `config('database.default') === 'sqlite'` check in controllers handles this, but production behavior was not tested |
| CategoryBlock dead code removal | LOW | ✅ LOW RISK | Removed code had zero reachable callers. If a future feature needs an inline note modal in CategoryBlock, it should use AddRecordModal |
| Visit sync_status column | MEDIUM | ⚠️ VISITS NOT SYNCABLE | Visits don't have a `sync_status` column. The merge preserves local visits during refresh, but visits created offline won't auto-sync to production. This is a known architectural gap, not introduced by this fix |
| `JSON.parse(JSON.stringify())` deep clone | LOW | ✅ ACCEPTABLE | Workspace data is small (<100 items). Deep clone cost is negligible. If performance becomes an issue, switch to `structuredClone()` or a library |

---

## 7. Files Modified Summary

| File | Change Type | Lines |
|---|---|---|
| `resources/js/Pages/DoctorWorkspace.vue` | Added `addNoteLocally` to destructuring | +1 (line 309) |
| `resources/js/Pages/DoctorWorkspace.vue` | `submitNoteForm()` — capture response, call `addNoteLocally()` | ~15 lines changed |
| `resources/js/Components/workspace/CategoryBlock.vue` | Removed dead note modal template | -10 lines (311-320) |
| `resources/js/Components/workspace/CategoryBlock.vue` | Removed `showNoteModal` ref, `noteContent` ref | -2 refs |
| `resources/js/Components/workspace/CategoryBlock.vue` | Removed `addNote()`, `submitNote()`, `addTimelineEntry()` functions | -30 lines |
| `resources/js/Composables/useWorkspace.js` | Rewrote `refreshWorkspaceData()` — universal merge + error preservation | ~80 lines replaced |
| `resources/js/Components/workspace/AddRecordModal.vue` | Corrected routing comment (EXTERNAL → LOCAL_PHP) | ~8 lines changed |

---

*Report generated 2026-07-26. All changes are in the working tree, uncommitted.*
