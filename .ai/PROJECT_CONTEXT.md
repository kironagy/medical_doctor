# Project Context
## Medical Plus Mobile Application

Version: 1.0

Status: Active

---

# Project Overview

Medical Plus is a medical management system consisting of:

- Laravel Backend
- REST API
- Vue.js + Inertia Frontend
- NativePHP Mobile Application

The mobile application is built using a WebView and communicates directly with the Laravel REST API.

The backend is the single source of truth.

---

# Project Goals

The primary goals of the project are:

- High stability
- Predictable behavior
- Maintainable codebase
- Production-ready implementation
- Incremental development
- Minimal complexity

The project intentionally prioritizes stability over feature count.

---

# Previous Architecture

The project originally implemented a complex offline-first architecture.

It included:

- SQLite
- Hybrid repositories
- Background synchronization
- Sync queues
- Pending operations
- Conflict resolution
- Incremental synchronization
- Local storage
- Offline middleware

The intention was to allow full offline operation.

Although technically complete, the architecture became increasingly difficult to maintain.

---

# Problems Caused by the Previous Architecture

The previous implementation introduced many production issues.

Examples included:

- Patients not appearing immediately.
- Data only appearing after restarting the application.
- Synchronization inconsistencies.
- UI state becoming outdated.
- Files failing to synchronize.
- Notes disappearing.
- Offline and online state conflicts.
- Complex debugging.
- Difficult maintenance.

Most reported bugs were directly or indirectly related to synchronization.

---

# Architectural Decision

After extensive investigation, the previous offline implementation was removed.

This decision was intentional.

It is not considered a temporary workaround.

The project was intentionally simplified to restore production stability.

---

# Current Architecture

The current production architecture uses SQLite as a local persistence layer with API synchronization.

There is:

- SQLite for local patient data
- Online operations communicate with Laravel REST API
- Inline sync: PatientRepository pushes pending changes when connectivity is restored
- sync_status column tracks synchronization state (synced, pending_sync, conflict)
- No background synchronization engine
- No pending queue
- No conflict resolution
- The backend is the single source of truth

---

# Current Data Flow

Mobile Application

↓

Vue Application

↓

Axios

↓

Laravel REST API

↓

MySQL

There is only one source of data.

The backend database.

---

# Development Strategy

Offline support is not being reintroduced all at once.

Instead,

the project follows a phased implementation strategy.

Each phase must become completely stable before beginning the next phase.

This minimizes risk and allows easier debugging.

---

# Current Development Phase

The currently active phase is defined in ROADMAP.md.

Every implementation must respect that document.

Future phases must not be implemented early.

---

# Phase 7 — Offline File Upload

Phase 7 is complete and production-ready.

Capabilities:

- Offline file upload for images, PDFs, videos, audio, and documents.
- Android runtime permission dialogs for Camera (CAMERA), Storage (READ_MEDIA_IMAGES), and Audio (RECORD_AUDIO).
- Files saved to `storage/app/uploads/pending/{uuid}.{ext}` with SHA-256 hash via streaming.
- Metadata persisted in `offline_files` SQLite table with `sync_status` state machine.
- Files appear immediately in UI and survive app restart/process death/WebView recreation.
- Preview via Phase 6 cache system (`/_native/cache/files/{uuid}`).
- Auto-sync when connectivity returns: `SyncPendingUploadsCommand` runs every 5 minutes, batch of 5, max 5 retries.
- Stuck uploads (>10 min in `uploading` state) automatically recovered on next sync cycle.
- All upload operations use streaming only — no `file_get_contents()`, no OOM risk for 500MB files.
- Authorization enforced via `Gate::authorize()` on all endpoints (store, retry, destroy).

---

# Engineering Philosophy

The project intentionally avoids:

- Large rewrites
- Overengineering
- Premature optimization
- Generic enterprise architecture
- Unnecessary abstractions

The preferred solution is the smallest production-ready implementation.

---

# Expectations for AI Assistants

Before implementing any feature:

Understand the current architecture.

Understand the current development phase.

Understand previous architectural decisions.

Only then suggest an implementation.

Never assume that a more complex solution is better.

---

# Expectations for Developers

Developers should:

Implement one feature at a time.

Avoid unrelated refactoring.

Keep changes localized.

Preserve working functionality.

Update documentation whenever architectural decisions change.

---

# Long-Term Vision

Offline functionality will eventually return.

However,

it will be rebuilt gradually.

Every capability will be implemented independently.

Each phase must be validated before continuing.

The objective is to achieve a stable offline-first architecture without repeating the mistakes of the previous implementation.

Until then,

the SQLite + API architecture remains the production standard.
