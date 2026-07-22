# 05 — Offline / Online Validation

> **Purpose**: Verify the application works correctly in all connectivity states.
> **Scope**: Network transitions, data integrity, sync behavior.

---

## Connectivity States

| State | `navigator.onLine` | `NetworkStatusService::isOnline()` | Behavior |
|-------|-------------------|-----------------------------------|----------|
| Fully Online | true | true | Writes → API + local (Hybrid); Reads → local SQLite |
| Soft Offline (API unreachable) | true | false | Writes → local + queue; Reads → local SQLite |
| Hard Offline (no network) | false | false | Writes → SyncMiddleware → local + queue; Reads → local SQLite |
| Transitioning (just came online) | true | false → true | Auto-sync triggered after 2s delay |

---

## Test 1: Fresh Install — First Launch

| Step | Action | Expected Result | Status |
|------|--------|----------------|--------|
| 1.1 | Install app, launch | Login page displayed | ✅ |
| 1.2 | Login with credentials | Login succeeds, token stored in `sync_states` | ✅ |
| 1.3 | Workspace loads | Initial Inertia props from SQLite (empty or cached) | ✅ |
| 1.4 | Background sync triggers | `syncAndRefresh()` called after 100ms | ✅ |
| 1.5 | Patient list populated | Patients appear after sync completes | ✅ |

**Failure**: Empty login screen → check API URL in `.env`
**Failure**: Sync never triggers → check `app.js` event listener

---

## Test 2: Start Offline

| Step | Action | Expected Result | Status |
|------|--------|----------------|--------|
| 2.1 | Disable network | App shows offline banner | ✅ |
| 2.2 | Open app | Login page loads from local SQLite | ✅ |
| 2.3 | Login while offline | Auth::attempt() against local SQLite | ✅ |
| 2.4 | Workspace loads | Patient list from local SQLite | ✅ |
| 2.5 | Create patient offline | Patient appears in sidebar; saved to SQLite | ✅ |
| 2.6 | Add note offline | Note appears via addNoteLocally() | ✅ |
| 2.7 | Add visit offline | Visit added to workspaceData.visits | ✅ |

**Validation**: Check `sync_queue` table for pending items after offline operations

---

## Test 3: Online → Offline Transitions

| Step | Action | Expected Result | Status |
|------|--------|----------------|--------|
| 3.1 | Start online, sync completes | Local SQLite up-to-date | ✅ |
| 3.2 | Disable network | Offline banner appears within 5s | ✅ |
| 3.3 | Edit patient name | Optimistic UI update; rollback if API was in-flight | ✅ |
| 3.4 | Add note | `navigator.onLine` detects offline → addNoteLocally() | ✅ |
| 3.5 | Upload file | SyncMiddleware checks isOnline → saves locally | ✅ |
| 3.6 | Switch patients | Workspace loads from local SQLite | ✅ |
| 3.7 | Search patients | Search query sent to API → fails → no results | ⚠️ Search unavailable offline |

**Validation**: Verify sync_queue has pending items after step 3.3-3.5

---

## Test 4: Offline → Online Transitions

| Step | Action | Expected Result | Status |
|------|--------|----------------|--------|
| 4.1 | App is offline with pending changes | sync_queue has pending items | ✅ |
| 4.2 | Enable network | Online event fires | ✅ |
| 4.3 | `useSyncState` marks online | `isOnline.value = true` | ✅ |
| 4.4 | `AppLayout` connectivity listener | Triggers `/api/native/sync/background` | ✅ |
| 4.5 | Background sync runs | `BackgroundSyncService.run()` → `FullSyncService.syncMetadataOnly()` | ✅ |
| 4.6 | Pending operations pushed | `syncPendingOperations()` processes queue | ✅ |
| 4.7 | Metadata pulled | `pullPaginatedPatients()` fetches updated data | ✅ |
| 4.8 | UI refreshes | `triggerUiRefresh()` → `sync-completed` event → `refreshPatientList()` | ✅ |
| 4.9 | Offline banner hides | Banner hidden when `isOffline = false` | ✅ |

**Validation**: Verify sync_queue pending count drops to 0 after sync

---

## Test 5: Conflict Scenarios

| Step | Action | Expected Result | Status |
|------|--------|----------------|--------|
| 5.1 | Edit patient name offline | Saved locally with `client_updated_at` updated | ✅ |
| 5.2 | Same patient edited on another device online | Server has different version | ✅ |
| 5.3 | Come online, sync triggers | ConflictResolver compares timestamps | ✅ |
| 5.4 | Local is newer | Local version kept; API updated | ✅ |
| 5.5 | Remote is newer | Remote version pulled; local changes queued | ✅ |
| 5.6 | Same timestamp | Default: remote wins | ⚠️ Acceptable |

---

## Test 6: Data Integrity

| Step | Action | Expected Result | Status |
|------|--------|----------------|--------|
| 6.1 | Create 10 patients offline | All appear in patient list, all in sync_queue | ✅ |
| 6.2 | Go online | All 10 patients synced to API | ✅ |
| 6.3 | Remote server has 50 patients | Incremental sync pulls new ones (updated_since) | ✅ |
| 6.4 | Toggle airplane mode mid-sync | In-flight API call fails → NetworkStatusService marks offline → remaining ops queued | ✅ |
| 6.5 | Come online again | Remaining operations resume | ✅ |

---

## Summary

| Scenario | Pass/Fail | Notes |
|----------|-----------|-------|
| Fresh install — first launch | ✅ | Sync triggers after mount |
| Start offline — login | ✅ | Auth via local SQLite |
| Create/edit offline | ✅ | SyncMiddleware saves locally |
| Online → offline transition | ✅ | 5s network status cache detection |
| Offline → online transition | ✅ | Auto-sync with 2s debounce |
| Conflict resolution | ✅ | Timestamp-based with hasPendingChanges() |
| Extended offline (100+ ops) | ✅ | Queue persists across app restarts |
| Airplane mode mid-sync | ✅ | Graceful failure, remaining ops queued |
| Search offline | ⚠️ | No results — API search unavailable offline |
| File upload offline | ⚠️ | Files > middleware bypass limit not saved locally |
