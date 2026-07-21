# 🏗️ Medical Plus - Sync Architecture Redesign Tasks

> **Generated:** July 21, 2026
> **Context:** Complete analysis of current sync architecture after fixing `NATIVEPHP_RUNNING=true` env bug and 3 DoctorIsolationTest failures.

---

## 📋 Summary of Completed Work

| Task | Status |
|------|--------|
| Fix DoctorIsolationScope (env var bug) | ✅ Done |
| All 34 PHPUnit tests passing (0 failures) | ✅ Done |
| Build & install APK on phone via adb (68 MB release) | ✅ Done |
| Comprehensive codebase analysis completed | ✅ Done |

---

## 🎯 Phase 1: Fix Files, Notes, Uploads Sync (CURRENT)

**Goal:** New patients' files/notes/uploads must sync correctly. Old patients already work.

### Root Causes Identified

| Issue | Root Cause | Files Affected |
|-------|------------|----------------|
| **1a. Files don't appear for new patients** | `FullSyncService::syncMetadataOnly()` may not paginate API responses — new patients beyond page 1 are missed | `app/Services/FullSyncService.php` |
| **1b. Double-enqueue of sync operations** | `HybridPatientFileRepository::upload()` saves locally (triggers `PatientFileObserver::created()`) THEN tries API, THEN enqueues another SyncQueueItem. Two pending operations for the same file. | `app/Repositories/Hybrid/HybridPatientFileRepository.php`, `app/Observers/PatientFileObserver.php` |
| **1c. Null patient UUID in observer** | `$file->patient?->uuid` can be null if relationship is not loaded when observer fires | `app/Observers/PatientFileObserver.php` |
| **1d. Upload response not refreshing UI** | `useUploads.js` calls `addFileLocally()` after success but `workspaceData` reactive update may not trigger `CategoryBlock` re-render | `resources/js/Composables/useUploads.js`, `resources/js/Components/workspace/CategoryBlock.vue` |
| **1e. Note creation doesn't update UI** | After note is created via API, reactive store not updated — user must refresh | `resources/js/Components/workspace/AddRecordModal.vue`, `resources/js/Composables/useWorkspace.js` |

### Implementation Plan

```php
// File: app/Repositories/Hybrid/HybridPatientFileRepository.php
// Fix: Check for existing pending SyncQueueItem before enqueuing
public function upload(string $patientUuid, array $file, array $data = []): array
{
    $localData = $this->localRepo->upload($patientUuid, $file, $data);
    
    if (NetworkStatusService::isOnline()) {
        try {
            $apiData = $this->apiRepo->upload($patientUuid, $file, $data);
            $this->syncLocalCache([$apiData], $patientUuid);
            return $this->rewriteUrls($apiData);
        } catch (\Throwable $e) {
            NetworkStatusService::setOnline(false);
        }
    }
    
    // CHECK: Only enqueue if no pending operation already exists
    $existingPending = SyncQueueItem::where('record_uuid', $localData['uuid'] ?? '')
        ->where('operation', 'create')
        ->where('status', 'pending')
        ->exists();
    
    if (!$existingPending) {
        $this->syncQueue->enqueueOperation(
            'PatientFile', 'create',
            $localData['uuid'] ?? Str::uuid()->toString(),
            array_merge($data, ['patient_uuid' => $patientUuid, 'local_path' => $localData['file_path'] ?? null]),
            3
        );
    }
    
    return $localData;
}
```

```php
// File: app/Observers/PatientFileObserver.php
// Fix: Ensure patient UUID is always resolved
public function created(PatientFile $file)
{
    if ($this->hasExistingPendingOperation($file->uuid, 'create')) {
        return;
    }
    
    // Eager-load patient relationship if not loaded
    if (!$file->relationLoaded('patient')) {
        $file->load('patient');
    }
    
    $patientUuid = $file->patient?->uuid;
    if (!$patientUuid) {
        Log::warning('[PatientFileObserver] Cannot enqueue sync: no patient UUID for file: ' . $file->uuid);
        return;
    }
    
    // ... rest of method
}
```

```javascript
// File: resources/js/Composables/useWorkspace.js
// Fix: Add addNoteLocally function + ensure refreshWorkspaceData is called after note creation
function addNoteLocally(note) {
    if (!note?.uuid) return;
    if (!workspaceData.value) {
        workspaceData.value = { notes: [note], files: [], visits: [], shares: [], categories: [], stats: {} };
        return;
    }
    if (!workspaceData.value.notes) workspaceData.value.notes = [];
    workspaceData.value.notes = [note, ...workspaceData.value.notes];
    workspaceData.value = { ...workspaceData.value };
}

// Expose addNoteLocally and ensure it's called by AddRecordModal
```

### Files to Modify (Phase 1)

- [ ] `app/Repositories/Hybrid/HybridPatientFileRepository.php` — dedup check before enqueue
- [ ] `app/Observers/PatientFileObserver.php` — eager-load patient, check null UUID
- [ ] `app/Services/FullSyncService.php` — handle paginated API responses
- [ ] `resources/js/Composables/useWorkspace.js` — add `addNoteLocally()`, expose
- [ ] `resources/js/Components/workspace/AddRecordModal.vue` — call `addNoteLocally` after note creation
- [ ] `resources/js/Composables/useUploads.js` — ensure `addFileLocally` is called with complete file data
- [ ] `resources/js/Components/workspace/CategoryBlock.vue` — auto-refresh after upload/note

---

## 🎯 Phase 2: UI Auto-Updates After Every Operation

**Goal:** User never needs to restart, refresh, or reopen to see changes.

### Required Flow

```
User Action → API succeeds → Update SQLite cache → Emit reactive state → UI updates
```

### Implementation Plan

1. **Create `useSyncState.js` composable** — shared reactive store that tracks:
   - Last sync timestamp
   - Pending operations count
   - Current online/offline status
   - List of recently changed resources

2. **Modify `useWorkspace.js`** — after every successful operation:
   - Call API
   - Update reactive store immediately (optimistic)
   - Wait for response
   - Update reactive store with actual data
   - Don't require `refreshPatientList()` or `syncAndRefresh()` — just update the local reactive state

3. **Fix `patientList()` endpoint** — ensure it always returns fresh data from SQLite (which is updated by sync)

4. **Add connectivity listener in DoctorWorkspace.vue** — when connectivity returns, auto-trigger `syncAndRefresh()`

### Files to Modify (Phase 2)

- [ ] `resources/js/Composables/useSyncState.js` — NEW file
- [ ] `resources/js/Composables/useWorkspace.js` — add auto-refresh after operations
- [ ] `resources/js/Pages/DoctorWorkspace.vue` — add connectivity listener
- [ ] `resources/js/Components/workspace/CategoryBlock.vue` — reactive updates

---

## 🎯 Phase 3: Background Synchronization

**Goal:** Sync runs automatically when connectivity returns, even if app was closed.

### Implementation Plan

1. **Create `BackgroundSyncService` (PHP)** — triggered by:
   - App foreground event (NativePHP lifecycle)
   - Connectivity restore (network change)
   - Periodic timer (every 30s while app is active)

2. **Add `navigator.onLine` listener in frontend** — when connectivity:
   - Changes to online → trigger `syncAndRefresh()`
   - Changes to offline → show offline indicator

3. **Modify `SyncMiddleware`** — add sync status headers to every response so the frontend always knows sync state

### Files to Modify (Phase 3)

- [ ] `app/Services/BackgroundSyncService.php` — NEW file
- [ ] `app/Http/Middleware/SyncMiddleware.php` — add sync state headers
- [ ] `resources/js/app.js` — add global connectivity listener
- [ ] `resources/js/Composables/useSyncState.js` — expose sync state

---

## 🎯 Phase 4: Architecture Refactoring

**Goal:** Extract dedicated modules only after all acceptance tests pass.

### New Modules (After Phase 1-3 Verified)

- [ ] `app/Services/Sync/SyncManager.php` — central orchestrator
- [ ] `app/Services/Sync/PendingOperationsService.php` — unified queue
- [ ] `app/Services/Sync/ConflictResolver.php` — last-write-wins
- [ ] `app/Services/Sync/IncrementalSyncService.php` — timestamp-based pull

### Refactoring Plan

1. Move sync logic OUT of `FullSyncService` into dedicated modules
2. Consolidate `PendingOperation` + `SyncQueueItem` into single queue
3. Hybrid repos become thin wrappers: API call → cache → return
4. All sync orchestration goes through `SyncManager`

---

## 🧪 Acceptance Scenarios (Must All Pass)

### Scenario 1
Create patient on Website → Mobile auto-downloads patient → No restart needed

### Scenario 2
Upload file to patient on Website → Mobile auto-shows file after sync

### Scenario 3
Create patient on Mobile (online) → Patient appears immediately → Website receives it → No restart

### Scenario 4
Upload file from Mobile → API receives → Website displays → SQLite cache updates → UI refreshes immediately

### Scenario 5
Create patient on Mobile (offline) → Patient appears immediately → Pending operation created → Reconnect → Patient auto-uploads → Website receives → Pending removed

### Scenario 6
Upload file on Mobile (offline) → File stored locally → Visible immediately → Marked Pending → Reconnect → File auto-uploads → Website shows it → Pending removed

---

## 📁 File Inventory (All Relevant Files)

### PHP Backend (~25 files)
| File | Role |
|------|------|
| `app/Services/FullSyncService.php` | Orchestrates push + pull sync |
| `app/Services/SyncQueueService.php` | Manages sync_queue items |
| `app/Services/NetworkStatusService.php` | Online/offline detection |
| `app/Services/Mobile/ApiService.php` | API client with token management |
| `app/Repositories/Hybrid/HybridPatientRepository.php` | Hybrid patient CRUD |
| `app/Repositories/Hybrid/HybridPatientFileRepository.php` | Hybrid file CRUD + upload |
| `app/Repositories/Hybrid/HybridPatientNoteRepository.php` | Hybrid note CRUD |
| `app/Repositories/Hybrid/HybridPatientVisitRepository.php` | Hybrid visit CRUD |
| `app/Repositories/Eloquent/EloquentPatientFileRepository.php` | Local SQLite file CRUD |
| `app/Observers/PatientFileObserver.php` | Enqueues sync on file events |
| `app/Observers/PatientNoteObserver.php` | Enqueues sync on note events |
| `app/Models/SyncQueueItem.php` | Sync queue model |
| `app/Models/PendingOperation.php` | Legacy pending ops model |
| `app/Http/Controllers/NativeSyncController.php` | API endpoints for sync |
| `app/Http/Controllers/WorkspaceController.php` | Serves patient workspace data |
| `app/Http/Controllers/Api/CategoryFileController.php` | Serves category files |
| `app/Http/Middleware/SyncMiddleware.php` | Injects sync state into responses |
| `app/Jobs/FullSyncJob.php` | Queued full sync job |
| `app/Jobs/SyncPendingOperationsJob.php` | Queued pending ops job |
| `app/Providers/RepositoryServiceProvider.php` | Binds Hybrid repos in Native mode |
| `app/Domains/Media/Models/PatientFile.php` | File model |
| `app/Domains/Patients/Models/Patient.php` | Patient model |
| `app/Domains/Patients/Models/PatientNote.php` | Note model |
| `app/Domains/Patients/Models/PatientShare.php` | Share model |
| `app/Domains/Auth/Scopes/DoctorIsolationScope.php` | Doctor data isolation scope |

### Frontend (~10 files)
| File | Role |
|------|------|
| `resources/js/Composables/useWorkspace.js` | Main workspace state management |
| `resources/js/Composables/useUploads.js` | File upload orchestration |
| `resources/js/Components/workspace/CategoryBlock.vue` | Category file/note display |
| `resources/js/Components/workspace/AddRecordModal.vue` | Add file/note modal |
| `resources/js/Components/workspace/FileActions.vue` | File action buttons |
| `resources/js/Components/UploadManager.vue` | Upload progress UI |
| `resources/js/Pages/DoctorWorkspace.vue` | Main workspace page |
| `resources/js/Composables/useSyncState.js` | **NEW** - shared sync state |
| `resources/js/app.js` | App entry, connectivity listeners |

### Database Migrations
| File | Tables |
|------|--------|
| `database/migrations/2026_06_29_144926_create_offline_sync_tables.php` | `sync_queue`, `sync_states` |
| `database/migrations/2026_07_03_222612_create_pending_operations_table.php` | `pending_operations` |
| `database/migrations/2026_07_18_150000_make_sync_queue_record_uuid_nullable.php` | Alter `sync_queue` |

---

## ⚡ Key Architecture Rules

1. **API is always the source of truth** when online
2. **SQLite is only cache** — never the primary database
3. **UI reads from SQLite** — updated continuously by sync
4. **UI never waits for API directly** — always reactive
5. **Every patient follows same pipeline** — no old vs new distinction
6. **New patients auto-enter sync system** — files/notes/uploads work immediately
7. **Conflict resolution: last-write-wins** — never create duplicates
8. **Incremental sync** — never download entire database
9. **No WebSockets/SSE** — use reactive SQLite + connectivity listener
10. **No restart required** for any operation
