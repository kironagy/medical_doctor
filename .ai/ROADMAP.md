# Development Roadmap
## Medical Plus Mobile Application

Version: 1.0

Status: Active

---

# Purpose

This roadmap defines the order in which features are implemented.

The roadmap is mandatory.

Features must only be implemented when their phase becomes active.

Future phases must never be implemented early.

---

# Development Strategy

The project follows an incremental development model.

Only one phase is active at any given time.

Each phase must become fully stable before the next phase begins.

The objective is to minimize complexity while maintaining production stability.

---

# Current Phase

## ACTIVE PHASE

Phase 4

Only Phase 4 may be implemented.

If a requested task belongs to a future phase,

the conflict must be explained before any implementation is suggested.

---

# Phase 1

## API Only

Status:

Completed

Objective:

Remove the previous offline architecture.

Restore a stable production environment.

Completed Work:

- API-only communication
- Direct Laravel integration
- MySQL as single source of truth
- Removal of SQLite
- Removal of synchronization engine
- Removal of hybrid repositories
- Removal of pending queue
- Removal of conflict resolution

Result:

Stable online application.

---

# Phase 2

## Preserve WebView State

Status:

Completed

Objective:

Improve user experience when reopening the application without internet access.

Requirements:

- Preserve WebView state.
- Preserve rendered page.
- Restore navigation state if supported.
- Avoid displaying browser error pages after restart.

Allowed:

- WebView lifecycle improvements.
- NativePHP WebView state restoration.
- Navigation restoration.

Forbidden:

- SQLite
- Local database
- Offline business data
- Offline CRUD
- Background synchronization
- Cache layer
- Synchronization queue

Success Criteria:

The application restores the previous rendered page without requiring internet immediately after restart.

---

# Phase 3

## Authentication Persistence

Status:

Future

Objective:

Persist user session between application launches.

Expected Features:

- Authentication persistence
- Session persistence
- Cookies
- Last visited URL
- Navigation history

Not Included:

Offline data.

SQLite.

Synchronization.

---

# Phase 4

## Read-Only Patients Cache

Status:

Current

Objective:

Allow previously viewed patients to be read while offline.

Scope:

Read-only.

No editing.

No synchronization.

No pending operations.

---

# Phase 5

## Offline Patients CRUD

Status:

Future

Objective:

Allow patient creation and editing while offline.

Expected Features:

- Local persistence
- Offline create
- Offline update
- Offline delete

Synchronization is still limited.

---

# Phase 6

## Files Cache

Status:

Future

Objective:

Cache downloaded files for offline viewing.

Scope:

Read-only.

No upload synchronization.

---

# Phase 7

## Offline File Upload

Status:

Future

Objective:

Allow uploading files created while offline.

Requires:

Reliable local storage.

Synchronization preparation.

---

# Phase 8

## Offline Notes

Status:

Future

Objective:

Support offline notes.

Requires:

Stable local persistence.

Reliable synchronization.

---

# Phase 9

## Pending Queue

Status:

Future

Objective:

Queue operations created while offline.

Expected Features:

- Pending operations
- Retry mechanism
- Failure recovery

---

# Phase 10

## Background Synchronization

Status:

Future

Objective:

Synchronize local changes automatically.

Expected Features:

- Background sync
- Conflict detection
- Conflict resolution
- Incremental synchronization

This is the final phase because it is the most complex.

---

# Rules

Never implement work from a future phase.

Never prepare infrastructure for future phases.

Never add placeholder code for future functionality.

Each phase must remain independent.

---

# Phase Completion

A phase is considered complete only if:

All planned functionality works.

No known blocking bugs remain.

The implementation is production-ready.

Regression testing passes.

Documentation is updated.

Only then may development proceed to the next phase.

---

# If a Request Conflicts

If a requested feature belongs to another phase:

Stop.

Explain the conflict.

Recommend waiting until the appropriate phase.

Do not silently implement future functionality.

---

# Final Principle

The roadmap exists to protect the project from unnecessary complexity.

Stable progress is more valuable than rapid progress.

The project grows one verified phase at a time.
