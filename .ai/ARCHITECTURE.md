# Architecture
## Medical Plus Mobile Application

Version: 1.0

Status: Production

---

# Overview

Medical Plus follows a simple client-server architecture.

The mobile application does not contain a local database.

The backend is the single source of truth.

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

The backend database is the only source of truth.

Never store application data locally unless explicitly introduced in future roadmap phases.

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

Current application state exists only in memory while the application is running.

No local persistence exists yet.

Future persistence must follow the roadmap.

---

# Offline

Current Status:

Disabled.

There is currently:

- No SQLite
- No local cache
- No synchronization
- No pending operations
- No conflict resolution

Any proposal introducing these features before the appropriate roadmap phase is incorrect.

---

# Current Phase Responsibilities

Phase 2 focuses only on preserving the WebView state.

Examples:

Allowed:

- Restore last rendered page.
- Preserve navigation history.
- Restore WebView state.

Not Allowed:

- Offline CRUD.
- Local database.
- Data synchronization.
- Cached business data.

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

- Read-only cache
- Offline CRUD
- File synchronization
- Background synchronization

However,

these features do not currently exist.

They must not be implemented before their roadmap phase.

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
