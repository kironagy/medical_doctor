# PROJECT CONTEXT

This project is NOT a new application.

The backend already exists.

The web application is already developed, tested, deployed, and actively used in production.

Production Website

https://prof-hosam-fekry.online/

This production website is considered the SINGLE SOURCE OF TRUTH.

The Laravel backend is already connected to the production database.

The Vue + Inertia frontend is already fully functional.

The mobile application is an extension of the existing production system.

It must integrate with the existing backend.

It must never replace it.

It must never redesign business logic.

It must never duplicate business rules.

It must always respect the production implementation.

---

# IMPORTANT RULE

The existing Laravel project is already deployed on a LIVE PRODUCTION SERVER.

This is NOT a local prototype.

This is NOT a demo project.

This is a real production environment with real users and real data.

Therefore:

• Never assume anything.

• Never modify backend logic without understanding its purpose.

• Never change database structure unless absolutely required.

• Never break existing API compatibility.

• Never remove existing features.

• Never introduce breaking changes.

Every backend modification must be backward compatible.

The existing website must continue working exactly as before.

The mobile application is an additional client of the same backend.

---

# BACKEND OWNERSHIP

The Laravel project is the authoritative backend.

The backend is responsible for:

• Authentication

• Authorization

• Validation

• Business Logic

• Database

• Security

• Permissions

• Reports

• Medical Rules

• API Responses

The mobile application must consume these services.

It must never reimplement backend business logic.

---

# MOBILE RESPONSIBILITY

The mobile application is responsible for:

• Native User Interface

• Offline Storage

• SQLite

• Synchronization

• Local Cache

• Performance

• Background Sync

• Device Features

The mobile application must never become another backend.

---

# API COMMUNICATION

The mobile application communicates ONLY through the official REST API.

Never access MySQL directly.

Never bypass Laravel.

Never perform database operations outside the API.

Every server interaction must go through Laravel.

Laravel remains the only gateway to the production database.

---

# EXISTING WEBSITE

The production website already contains the complete implementation.

Before building any mobile feature:

1. Inspect the existing Laravel code.
2. Inspect the Vue implementation.
3. Understand the business workflow.
4. Understand validation.
5. Understand permissions.
6. Understand API behavior.
7. Reproduce the same behavior inside the mobile application.

The website is the functional reference.

The mobile application must behave identically whenever applicable.

---

# FEATURE PARITY

Every feature implemented in the mobile application must match the production website.

Same validations.

Same permissions.

Same business rules.

Same workflows.

Same API.

The only difference is the presentation layer.

The mobile UI is native.

The backend behavior must remain identical.

---

# NO WEBVIEW POLICY

The mobile application MUST NOT embed the website.

Forbidden:

• WebView
• iframe
• Embedded browser
• Rendering Vue pages
• Rendering Inertia pages

Everything visible on mobile must be built natively inside NativePHP.

Only data comes from the backend.

Never HTML.

Never Vue.

Never Inertia.

Only API + Native UI.

---

# SOURCE OF TRUTH

Business Logic
→ Laravel

Production Database
→ MySQL

Offline Database
→ SQLite

Native Interface
→ NativePHP

Remote Data
→ Laravel REST API

Local Data
→ SQLite

Synchronization
→ Sync Engine

Never violate this architecture.
