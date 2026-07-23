# Architecture Decisions
## Medical Plus Mobile Application

Version: 1.0

Status: Active

---

# AD-001: Phase 5 Architecture Simplification

Date: 2026-07-23

Status: Implemented

## Context

The previous offline architecture (Phase 4 and earlier) used a complex multi-layered approach:

- `HybridPatientRepository` delegating between Eloquent and SQLite
- `PendingOperation` for queuing offline mutations
- `FullSyncService` / `SyncStatusService` / `NetworkStatusService` for orchestration
- `ApiProxy` for intercepting and routing requests
- `SyncPendingOperationsJob` for background sync

Although technically functional, this architecture produced persistent production issues:

- Data appearing only after application restart
- UI state falling out of sync with backend
- Debugging complexity
- High maintenance burden

## Decision

Simplify the offline architecture for Phase 5 by:

1. **Removing 12 legacy files** — all hybrid repositories, services, and sync infrastructure that were no longer needed.
2. **Introducing `sync_status` column** on the `patients` table — replaces the entire `pending_operations` table and associated queue logic.
3. **New `PatientRepository`** at `app/Repositories/PatientRepository.php` — single repository handling both online (API) and offline (SQLite) operations, with a clear read-through cache pattern.
4. **Direct sync flow** — `PendingSyncController@sync` pushes pending patients directly to the API instead of through a multi-stage queue.
5. **Legacy tables preserved as deprecated** — `sync_queue`, `sync_states`, `sync_jobs`, `pending_operations`, `sync_meta` remain in the database but are not used by any production code.

## Rationale

- The `sync_status` approach is simpler: one column replaces an entire table and its associated services.
- Removing 12 files eliminates the primary source of synchronization bugs.
- Single repository with clear read-through cache is easier to debug and maintain.
- Direct sync (controller → API) is predictable and testable.

## Consequences

- Offline CRUD works but sync requires explicit user action (via Settings or auto-trigger).
- No background sync or conflict resolution — deferred to Phase 10.
- Legacy tables remain as dead schema; they can be dropped in a future cleanup migration.
- The repository layer must be kept clean — avoid re-introducing hybrid patterns.

## Files Affected

- Removed: 12 files (HybridPatientRepository, PendingOperation, FullSyncService, SyncStatusService, NetworkStatusService, ApiProxy, SyncPendingOperationsJob, etc.)
- Created: `app/Repositories/PatientRepository.php`
- Modified: `WorkspaceController`, related Vue components
- Added: `2026_07_22_000001_add_sync_status_to_patients_table.php`
