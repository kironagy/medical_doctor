# Application-logic fix history

Index of `BUG-NNN` / `SYNC-NNN` inline-comment fixes as they get touched and
folded in from `app/` and `resources/js/`. Native-bridge-layer fixes go in
`docs/NATIVE_BRIDGE_NOTES.md` instead. Not a full sweep — entries are added
opportunistically when a file with one of these comments is touched for
other reasons; the rest still live as inline comments until then.

## BUG-SYNC-002 — patient delete must mark `pending_delete`, not soft-delete immediately

**Symptom:** deleting a patient on the embedded SQLite device set `deleted_at`
but left `sync_status` at `'synced'`. `SyncEngineService::processPendingDeletes()`
only queries `sync_status = 'pending_delete'`, so the delete never reached the
production server — and the next download-sync cycle saw the patient still
active remotely and re-inserted it.

**Fix:** on `sqlite`, mark `sync_status = 'pending_delete'` instead of
deleting immediately; the sync engine pushes the delete to production first,
then removes the local row. On MySQL/production, soft-delete as normal.

**2026-08-06 — deduplicated:** this exact logic used to be hand-copied into
three places (`Api\Mobile\PatientController::destroy()`,
`EloquentPatientRepository::delete()`, `EloquentPatientRepository::forceDelete()`)
plus a fourth, slightly different version in `PatientRepository::delete()`
(which also pushes onto the `sync_queue` table via `SyncQueueService` — a
second, independent delete-propagation path from `SyncEngineService::
processPendingDeletes()`). `Api\Mobile\PatientController::destroy()` now
delegates to `PatientRepositoryInterface::delete()` (the implementation
actually bound in `RepositoryServiceProvider` and reachable from the
workspace UI) instead of re-deriving the pending_delete branch, so there's
one source of truth for the mobile entry point.

`EloquentPatientRepository::delete()` / `forceDelete()` still carry their own
copy of this branch — they can't be deleted outright because the class
`implements PatientRepositoryInterface` and PHP requires every interface
method to exist, but nothing calls them directly (`PatientRepository` uses
`$this->local` for every other operation except delete/forceDelete, which it
implements itself). They're effectively unreachable; treat them as such if
touched again.

**Still open:** `SyncEngineService::processPendingDeletes()` (queries
`Patient.sync_status`) and the `sync_queue`-based path (`PatientSyncService`
+ `SyncQueueService`, driven by `PatientRepository::delete()`'s
`$this->queueService->push(...)`) are two independent mechanisms that can
both act on the same pending delete. Not fixed yet — needs one of them
retired or made to call into the other.
