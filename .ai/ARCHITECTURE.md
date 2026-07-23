# Architecture
## Medical Plus Mobile Application

Version: 1.0

Status: Production

---

# Overview

Medical Plus follows a simple client-server architecture.

The mobile application contains a local SQLite database for offline operation.

The backend is the single source of truth for all business data.

Every user action communicates directly with the Laravel REST API.

---

# High-Level Architecture

```
                User
                  │
                  ▼
      NativePHP Mobile (WebView)
                  │
                  ▼
          Vue.js + Inertia
                  │
                  ▼
               Axios
                  │
                  ▼
          Laravel REST API
                  │
                  ▼
               MySQL
```

---

# Technology Stack

## Backend

- Laravel
- REST API
- MySQL

---

## Frontend

- Vue.js
- Inertia.js
- Axios

---

## Mobile

- NativePHP Mobile

The mobile application is a WebView that renders the Vue application.

Business logic remains on the server.

---

# Source of Truth

The backend database is the single source of truth for all business data.

Patients may be created and edited offline using SQLite as a local persistence layer.

Data created offline is marked with `sync_status = 'pending_sync'` and synchronized to the backend inline — when connectivity is restored, the PatientRepository pushes pending changes to the API during the next online operation.

The backend is the authoritative source — local data is always reconciled against the API on the next sync operation.

---

# Request Flow

A typical request follows this sequence:

User Action

↓

Vue Component

↓

Axios Request

↓

Laravel Controller

↓

Service / Business Logic

↓

Database

↓

JSON Response

↓

Vue Update

Every operation is server-driven.

---

# Authentication

Authentication is handled by the backend.

The mobile application should not implement custom authentication logic.

Authentication persistence belongs to Phase 3.

---

# State Management

Current application state exists in memory while running.

Patient data is persisted in SQLite for offline CRUD (Phase 5).

Local patients carry a `sync_status` column (`synced`, `pending_sync`, `conflict`) to track synchronization state.

Future persistence features will follow the roadmap.

---

# Offline

Current Status:

Partial — Phase 6 implemented.

SQLite is used as a local persistence layer for patients and file cache metadata.

Capabilities:

- SQLite patient cache (read-only, Phase 4)
- Offline patient create/edit/delete (Phase 5)
- Sync via `sync_status` column (`synced`, `pending_sync`, `conflict`)
- PatientRepository pushes local changes to the API inline on next online operation
- Read-only file cache with Range/streaming support and LRU 500MB quota (Phase 6)
- Offline file upload to `storage/app/uploads/pending/` with Android Camera/Storage/Audio permission dialogs (Phase 7)
- Auto-sync of pending uploads via `SyncPendingUploadsCommand` every 5 minutes (Phase 7)
- Offline files survive app restart/process death via SQLite + filesystem persistence and rehydration on workspace load (Phase 7)

Not yet available:

- Offline notes (Phase 8)
- Pending queue (Phase 9)
- Background synchronization (Phase 10)

---

# Current Phase Responsibilities

Phase 8 is not yet active.

Phase 7 is now production-ready.

Scope:

Offline file upload with Android permissions, streaming persistence, automatic sync recovery.

Allowed:

- Offline image/PDF/video/audio/document upload.
- File persistence through app restart.
- Preview via Phase 6 cache system.
- Auto-sync when connectivity returns.

Not Allowed:

- Offline notes.

---

# Data Ownership

Backend owns:

- Patients
- Files
- Notes
- Authentication
- Business rules
- Validation

Frontend owns:

- Rendering
- User interaction
- Temporary UI state

The mobile application owns:

- WebView lifecycle
- Native integrations
- Device-specific behavior

---

# Error Handling

Errors should originate from the backend whenever possible.

The frontend should display errors but should not duplicate backend validation logic.

---

# Architectural Principles

The architecture intentionally favors:

- Simplicity
- Predictability
- Maintainability
- Small incremental improvements

It intentionally avoids:

- Complex synchronization
- Duplicate storage
- Multiple data sources
- Hidden business logic
- Parallel architectures

---

# Future Architecture

Future phases may introduce:

- Offline file upload (Phase 7)
- Offline notes (Phase 8)
- Pending queue (Phase 9)
- Background synchronization (Phase 10)

However,

these features must not be implemented before their roadmap phase.

---

# Architecture Modification Policy

Changing the architecture requires:

1. A documented reason.
2. An architectural review.
3. An update to DECISIONS.md.
4. An update to ROADMAP.md (if applicable).
5. Approval before implementation.

No architectural change should be made implicitly.

---

# Final Principle

There must always be one clear answer to the question:

"Where does this data come from?"

At the current stage of the project, the answer is always:

The Laravel backend via the REST API.
