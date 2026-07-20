# Medical Plus V3 — Task Tracker

> **Last updated:** 2026-07-20
> **Branch:** `ui-redesign`
> **Production:** https://prof-hosam-fekry.online/
> **Existing .ai/TASKS.md:** Phases 1-15 marked COMPLETE. Phase 16 IN PROGRESS with unfinished items.
> **Existing .ai/RULES.md:** Provides project philosophy. This file (root `RULES.md`) supersedes it for coding conventions.

---

## Status Legend

| Symbol | Meaning |
|--------|---------|
| ✅ | Complete |
| 🔄 | In Progress |
| ⏸️ | Blocked |
| 🆕 | New / Not Started |
| 🐛 | Bug |
| 🔒 | Security |
| ⚠️ | Technical Debt |

---

## Phase 1 — Project Analysis ✅ COMPLETE

| # | Task | Status |
|---|------|--------|
| 1.1 | Folder structure analysis | ✅ |
| 1.2 | Domain model analysis (Models, Migrations) | ✅ |
| 1.3 | Controller & Service layer analysis | ✅ |
| 1.4 | Middleware, Policies, Routes analysis | ✅ |
| 1.5 | API endpoint inventory (web + mobile) | ✅ |
| 1.6 | Generate `ARCHITECTURE.md` | ✅ |
| 1.7 | Identify dual offline-queue technical debt | ✅ |

**Deliverable:** `ARCHITECTURE.md` (1671 lines — authoritative reference)

---

## Phase 2 — Mobile Foundation ✅ COMPLETE

| # | Task | Status |
|---|------|--------|
| 2.1 | NativePHP install (`php artisan native:install`) | ✅ |
| 2.2 | `.env.native` + `.nativephp/` config | ✅ |
| 2.3 | `NativeServiceProvider` (5 plugins registered) | ✅ |
| 2.4 | `ApiService` singleton + DI wiring | ✅ |
| 2.5 | Repository pattern: 5 interfaces + 3-tier binding | ✅ |
| 2.6 | Service layer: FullSync, SyncQueue, NetworkStatus, 6 Upload services | ✅ |
| 2.7 | SQLite setup (`storage/data/medical_plus.sqlite`, 24 tables) | ✅ |
| 2.8 | 25 migrations applied to SQLite | ✅ |

---

## Phase 3 — Authentication ✅ COMPLETE

| # | Task | Status |
|---|------|--------|
| 3.1 | Login UI (Vue Inertia, `np_persist_login` flag) | ✅ |
| 3.2 | API login (`/api/v1/login` → Sanctum token) | ✅ |
| 3.3 | Token storage (session primary + encrypted `sync_states` fallback) | ✅ |
| 3.4 | Offline login (local SQLite `Auth::attempt()` + remote fallback) | ✅ |
| 3.5 | Session restore (cookie persistence + startup sync) | ✅ |

---

## Phase 4 — Synchronization Engine ✅ COMPLETE

| # | Task | Status |
|---|------|--------|
| 4.1 | `NetworkStatusService` — connectivity monitor (60s cache) | ✅ |
| 4.2 | `sync_queue` table + `SyncQueueService` (priority, retry, status) | ✅ |
| 4.3 | Hybrid repo offline queue (local-first → API → enqueue) | ✅ |
| 4.4 | Conflict resolution (`client_updated_at` delta + 422 → force-delete) | ✅ |
| 4.5 | `NativeSyncController` — push + pull, 5 endpoints | ✅ |

**Technical Debt identified:** Two parallel queue systems (SyncQueue vs PendingOperations). See `RULES.md` §4.4.

---

## Phase 5 — Patients ✅ COMPLETE

| # | Task | Status |
|---|------|--------|
| 5.1 | Patient list (paginated, search, archived toggle) | ✅ |
| 5.2 | Unified search (patients + files + doctors, min 2 chars) | ✅ |
| 5.3 | Filters (date, time, type, mime_type, sort) | ✅ |
| 5.4 | Pagination (6/page categories, 50 visits/notes, 100 mobile files) | ✅ |
| 5.5 | Patient details (full payload, visits/files/notes counts, permissions) | ✅ |
| 5.6 | Create/Edit/Delete (full medical fields, soft-delete, restore, force-delete) | ✅ |
| 5.7 | Offline CRUD (local-first write, API sync, queue on failure) | ✅ |
| 5.8 | Sync validation (401 → re-login, 422 → force-delete stale) | ✅ |

---

## Phase 6 — Visits & Notes ✅ COMPLETE

| # | Task | Status |
|---|------|--------|
| 6.1 | Visits CRUD (type, reason, session_details, diagnosis, prescription, cost) | ✅ |
| 6.2 | Notes CRUD (author tracking, default category 'general') | ✅ |
| 6.3 | Visit/Note offline sync (PendingOperation + SyncQueueItem) | ✅ |

---

## Phase 7 — Files & Media ✅ COMPLETE

| # | Task | Status |
|---|------|--------|
| 7.1 | Direct file upload (max 5 GB, MIME type detection) | ✅ |
| 7.2 | Chunked upload (init/chunk/complete/cancel/status + resume, 1–50 MB chunks) | ✅ |
| 7.3 | File streaming (HTTP Range 206, signed URLs 6h, thumbnails via ffmpeg/GD) | ✅ |
| 7.4 | File management (update metadata, delete with cleanup) | ✅ |
| 7.5 | File categorization (6 default categories, bilingual, user custom) | ✅ |
| 7.6 | File offline sync (HybridPatientFileRepository, priority 3 queue) | ✅ |

---

## Phase 8 — Sharing & Permissions ✅ COMPLETE

| # | Task | Status |
|---|------|--------|
| 8.1 | Patient sharing CRUD (list/create/delete, idempotent updateOrCreate) | ✅ |
| 8.2 | Access levels (read / read_write, expiry support) | ✅ |
| 8.3 | Doctor search (name/email/specialization, min 2 chars) | ✅ |

---

## Phase 9 — Admin & Dashboard ✅ COMPLETE

| # | Task | Status |
|---|------|--------|
| 9.1 | Admin dashboard (doctor stats, recent doctors) | ✅ |
| 9.2 | Doctor management (CRUD, suspend/activate, per-doctor stats) | ✅ |
| 9.3 | User dashboard (stats cards) | ✅ |
| 9.4 | Mobile dashboard (`/api/v1/mobile/dashboard/stats`) | ✅ |

---

## Phase 10 — Upload & Media Processing ✅ COMPLETE

| # | Task | Status |
|---|------|--------|
| 10.1 | Chunked upload pipeline (6 service classes) | ✅ |
| 10.2 | Video optimization (ffmpeg faststart + thumbnail extraction) | ✅ |
| 10.3 | Upload frontend (`useUploads` composable: 4-parallel, semaphore, resume, retry) | ✅ |
| 10.4 | Direct-write optimization (byte-offset seek, no temp files) | ✅ |

---

## Phase 11 — Settings & Profile ✅ COMPLETE

| # | Task | Status |
|---|------|--------|
| 11.1 | Profile management (avatar via cropper.js, name, email, phone) | ✅ |
| 11.2 | Password management (strength indicator, confirmation) | ✅ |
| 11.3 | Preferences (theme: light/dark/system, locale: en/ar with RTL) | ✅ |
| 11.4 | Category management (CRUD, super-admin global vs per-user) | ✅ |
| 11.5 | App download (GitHub releases API, APK download) | ✅ |

---

## Phase 12 — Printing & Export ✅ COMPLETE

| # | Task | Status |
|---|------|--------|
| 12.1 | Patient print view (`PatientPrint.vue`, `@media print` CSS) | ✅ |
| 12.2 | Patient export (JSON streaming + ZIP via `ExportPatientFilesJob`) | ✅ |

---

## Phase 13 — UI Foundation (Vue + Inertia) ✅ COMPLETE

| # | Task | Status |
|---|------|--------|
| 13.1 | Inertia app setup (3 persistent Vue roots) | ✅ |
| 13.2 | Layout system (3-column grid, mobile sidebar, bottom nav) | ✅ |
| 13.3 | Base components (Button, Card, Dialog, Input, GlobalDialog, Toast, PullToRefresh) | ✅ |
| 13.4 | i18n system (`en.json` + `ar.json`, 601 lines each, RTL, Cairo + Inter fonts) | ✅ |
| 13.5 | Theme system (light/dark/system with Tailwind v4) | ✅ |
| 13.6 | Navigation & guards (client-side auth, redirect to /login) | ✅ |

---

## Phase 14 — NativePHP Integration ✅ COMPLETE

| # | Task | Status |
|---|------|--------|
| 14.1 | NativePHP Android + iOS scaffolds | ✅ |
| 14.2 | Native Service Provider (5 plugins, conditional registration) | ✅ |
| 14.3 | 12 plugin bridge functions (Camera, Dialog, File, Network, Share) | ✅ |
| 14.4 | Native build scripts (dev + production pipelines) | ✅ |
| 14.5 | NativePHP config (470 lines, SDK 35, min SDK 26, R8/ProGuard) | ✅ |
| 14.6 | App version management (v1.0.36, code 49, `com.medicalplus.app`) | ✅ |
| 14.7 | Startup lifecycle (auto-sync on open, migration version check) | ✅ |
| 14.8 | Android stabilization (JNI, Gradle, manifest, dependency cleanup) | ✅ |

**Deliverable:** `NATIVE_ANDROID_STABILIZATION.md`

---

## Phase 15 — Developer Tooling ✅ COMPLETE

| # | Task | Status |
|---|------|--------|
| 15.1 | Profiler middleware (dev-only, timing/memory/SQL) | ✅ |
| 15.2 | Upload diagnostics (opt-in chunk-level profiler) | ✅ |
| 15.3 | Client error reporting (`captureError` → `/api/v1/log/client-error`) | ✅ |
| 15.4 | Activity logging (`ActivityLogger` audit trail) | ✅ |
| 15.5 | `/debug-state` endpoint (404 when `APP_DEBUG=false`) | ✅ |

---

## Phase 16 — Production Readiness & Quality Gates 🔄 IN PROGRESS

**Phase goal:** Optimize the build, pass quality gates, and achieve production-ready status.

### 16.1 Dependencies Cleanup ✅ COMPLETE

| # | Task | Status |
|---|------|--------|
| 16.1.1 | Move `@vitejs/plugin-vue` from `dependencies` → `devDependencies` | ✅ |
| 16.1.2 | Remove unused `concurrently` from `devDependencies` | ✅ |

### 16.2 Vite Production Optimization ✅ COMPLETE

| # | Task | Status |
|---|------|--------|
| 16.2.1 | Add `build.minify: 'terser'` with `compress.drop_console: true` | ✅ |
| 16.2.2 | Vendor code splitting (`vendor` + `media` chunks) | ✅ |
| 16.2.3 | Per-route dynamic `import()` for code splitting (replaced eager glob) | ✅ |

### 16.3 Debug Code Removal ✅ COMPLETE

| # | Task | Status |
|---|------|--------|
| 16.3.1 | Guard `console.log` in `useWorkspace.js` with `import.meta.env.DEV` | ✅ |
| 16.3.2 | Guard `console.log` in `useUploads.js` with `import.meta.env.DEV` | ✅ |
| 16.3.3 | Move `useUploadDiagnostics.js` behind dynamic import (opt-in via `?debug` or localStorage flag) | ✅ |

### 16.4 Asset Optimization 🔄 IN PROGRESS

| # | Task | Priority | Status | Notes |
|---|--------|----------|--------|-------|
| 16.4.1 | Remove duplicate `icon.png` from `public/` (keep `resources/images/`) | P2 | 🔄 | Duplicate exists |
| 16.4.2 | Subset Cairo and Inter font files to used glyphs only | P3 | 🆕 | Reduces font blob size |
| 16.4.3 | Verify `public/fonts/` are not duplicated in bundle | P2 | 🆕 | Check Vite asset pipeline |

### 16.5 NativePHP Bundle Cleanup 🔄 IN PROGRESS

| # | Task | Priority | Status | Notes |
|---|--------|----------|--------|-------|
| 16.5.1 | `storage/app/mobile-cache/` in cleanup_exclude_files | ✅ | ✅ Already set in config/nativephp.php | |
| 16.5.2 | Verify `.env` files fully scrubbed from bundle (not just keys redacted) | P1 | ✅ | All `.env.*` are gitignored; signing secrets extracted to `.nativephp/signing.env` |
| 16.5.3 | Verify `storage/app/public/` files excluded from bundle | P1 | ✅ | Already in cleanup_exclude_files; added `storage/app/mobile-cache` |

### 16.6 Project Root Cleanup ✅ PARTIALLY COMPLETE

| # | Task | Priority | Status | Notes |
|---|--------|----------|--------|-------|
| 16.6.1 | Remove `.env.backup*` files from project root | P1 | ✅ | 5 backup files removed (security) |
| 16.6.2 | Remove `app_debug.log` from project root → `storage/logs/` | P1 | ✅ | |
| 16.6.3 | Remove `.DS_Store` files from repository | P3 | 🆕 | 10+ files scattered |

### 16.7 Testing Infrastructure 🆕 IN PROGRESS

| # | Task | Priority | Status | Notes |
|---|--------|----------|--------|-------|
| 16.7.1 | PHPUnit 12 installed + `phpunit.xml` with SQLite :memory: | P1 | ✅ | 2 feature + 2 unit tests (baseline) |
| 16.7.2 | API endpoint tests for mobile auth flow | P1 | ✅ | Login, token, 401 handling in AuthTest.php |
| 16.7.3 | API endpoint tests for patient CRUD | P1 | ✅ | Create, read, update, delete, soft-delete in PatientApiTest.php |
| 16.7.4 | Offline sync integration test | P1 | ✅ | FK ordering, withoutGlobalScopes, user sync in OfflineSyncTest.php |
| 16.7.5 | File upload / chunked upload test | P1 | 🆕 | Init → chunk → complete flow |
| 16.7.6 | Authorization / DoctorIsolationScope test | P1 | ✅ | Per-role data access in DoctorIsolationTest.php (5 tests) |
| 16.7.7 | Add xdebug/pcov for code coverage reporting | P2 | 🆕 | Required for coverage metrics |

### 16.8 Performance Verification 🆕 NOT STARTED

| # | Task | Target | Priority | Status |
|---|--------|--------|----------|--------|
| 16.8.1 | APK size after optimizations | < 25 MB preferred, < 30 MB acceptable | P1 | 🆕 |
| 16.8.2 | Cold start time | < 3 seconds | P1 | 🆕 |
| 16.8.3 | Screen transitions | < 300ms | P1 | 🆕 |
| 16.8.4 | List scroll with 1000+ items | 60fps | P2 | 🆕 |
| 16.8.5 | Search debouncing (300ms) | Works correctly | P2 | 🆕 |
| 16.8.6 | Vue re-render audit | No unnecessary re-renders | P2 | 🆕 |
| 16.8.7 | Batch SQLite operations for sync | Verified | P2 | 🆕 |
| 16.8.8 | Memory usage during normal use | < 150 MB | P1 | 🆕 |
| 16.8.9 | Background sync doesn't block UI | Verified | P1 | 🆕 |

### 16.9 Regression Testing 🆕 NOT STARTED

| # | Task | Priority | Status | Notes |
|---|--------|----------|--------|-------|
| 16.9.1 | Verify all 128 web routes still work | P0 | 🆕 | Critical |
| 16.9.2 | Verify all 36 mobile API routes still work | P0 | 🆕 | Critical |
| 16.9.3 | Auth flow (login/logout/token) | P0 | 🆕 | Critical |
| 16.9.4 | Offline login still works | P0 | 🆕 | Critical |
| 16.9.5 | Session restore (`np_persist_login`) | P1 | 🆕 | |
| 16.9.6 | Sync engine (push + pull) | P0 | 🆕 | Critical |
| 16.9.7 | Patient CRUD operations | P0 | 🆕 | Critical |
| 16.9.8 | File upload (direct + chunked) | P0 | 🆕 | Critical |
| 16.9.9 | Video streaming + thumbnails | P1 | 🆕 | |
| 16.9.10 | Sharing + permissions | P0 | 🆕 | Critical |
| 16.9.11 | Admin doctor management | P1 | 🆕 | |
| 16.9.12 | Settings/profile/password | P1 | 🆕 | |
| 16.9.13 | Print + export | P1 | 🆕 | |
| 16.9.14 | i18n (EN + AR + RTL) | P1 | 🆕 | |
| 16.9.15 | Theme switching (light/dark/system) | P2 | 🆕 | |

### 16.10 Final Acceptance 🆕 NOT STARTED

| # | Criterion | Priority | Status |
|---|-----------|----------|--------|
| 16.10.1 | All Phase 16 tasks completed | P1 | 🆕 |
| 16.10.2 | All tests pass (`phpunit --testdox`) | P0 | 🆕 |
| 16.10.3 | Offline mode: local SQLite + queue working | P0 | 🆕 |
| 16.10.4 | Online mode: API sync working | P0 | 🆕 |
| 16.10.5 | Synchronization reliable (no data loss) | P0 | 🆕 |
| 16.10.6 | UI matches production website | P1 | 🆕 |
| 16.10.7 | Performance smooth (see 16.8) | P0 | 🆕 |
| 16.10.8 | Build optimized (APK < 30 MB, dead code removed) | P0 | 🆕 |
| 16.10.9 | No critical bugs remain | P0 | 🆕 |
| 16.10.10 | Zero TODO/FIXME/HACK in production code | P1 | 🆕 |

---

## Phase 17 — Mobile API Resources 🚨 MISSING (Blocking NativePHP)

> **Blocker:** `app/Domains/Mobile/Resources/` is an **empty directory**.
> The NativePHP mobile app has NO mobile-specific API resource files.
> Currently it likely falls back to raw Eloquent models from the Api repositories,
> which causes inconsistent JSON responses (snake_case vs camelCase, missing computed fields, extra internal columns).

| # | Task | Priority | Status |
|---|--------|----------|--------|
| 17.1 | Create `MobilePatientResource.php` | P0 | 🆕 ⏸️ |
| 17.2 | Create `MobilePatientFileResource.php` | P0 | 🆕 ⏸️ |
| 17.3 | Create `MobilePatientNoteResource.php` | P0 | 🆕 ⏸️ |
| 17.4 | Create `MobilePatientVisitResource.php` | P0 | 🆕 ⏸️ |
| 17.5 | Create `MobileDoctorResource.php` | P0 | 🆕 ⏸️ |
| 17.6 | Create `MobileUserResource.php` | P0 | 🆕 ⏸️ |
| 17.7 | Create `MobileCategoryResource.php` | P1 | 🆕 ⏸️ |
| 17.8 | Bind mobile resources to mobile API controllers | P0 | 🆕 ⏸️ |

**Unblock condition:** These resources must mirror the web API resource output exactly (same camelCase keys, same conditional loading, same computed fields). See `RULES.md` §8.4.

---

## Active Blockers & Risks

| ID | Blocker | Impact | Owner | Resolution |
|----|---------|--------|-------|-----------|
| B-1 | `app/Domains/Mobile/Resources/` empty | NativePHP receives inconsistent API responses | — | Create 6–7 resource files (Phase 17) |
| B-2 | Dual offline-queue systems (SyncQueue + PendingOperations) | Sync bugs, maintenance confusion | — | Migrate Visits + Users to SyncQueue (Phase 20) |
| B-3 | `.env.backup*` files in git history | Security (bleached credentials may exist) | — | `git filter-branch` to purge |
| B-4 | No production test suite | Regression risk on every change | — | Write tests (Phase 16, §16.7) |
| B-5 | Unverified APK size after optimizations | May exceed 30 MB limit | — | Build + measure (Phase 16, §16.8.1) |

---

## Milestones

| Milestone | Target Date | Description | Dependencies |
|-----------|-------------|-------------|--------------|
| **M1 — Quality Gates Pass** | 2026-07-25 | All Phase 16 quality items (assets, cleanup, debug removal) complete | No blockers |
| **M2 — Test Suite Baseline** | 2026-07-28 | Minimum 6 test classes covering auth, patients, upload, sync, isolation, and exports | PHPUnit 12 + test structure |
| **M3 — Mobile API Parity** | 2026-07-25 | Mobile Resources directory populated, mobile API returns same camelCase as web API | Phase 17 unblocked |
| **M4 — Performance Verified** | 2026-07-30 | All 9 performance metrics measured and passing targets | APK built, test devices |
| **M5 — Regression Clear** | 2026-07-30 | All 15 regression checks pass | M1, M2 |
| **M6 — Production Ready** | 2026-08-02 | All acceptance criteria from 16.10 met | M1 + M2 + M3 + M4 + M5 |

---

## Future Phases (Not Yet Started)

| Phase | Description | Priority | Notes |
|-------|-------------|----------|-------|
| **Phase 18** | Performance benchmarks (automated Lighthouse / PHP benchmarks) | P2 | After M4 |
| **Phase 19** | UI redesign implementation (Vue component updates) | P2 | Web UI polish |
| **Phase 20** | Sync queue consolidation (migrate PendingOperations → SyncQueue) | P1 | Removes B-2 blocker |
| **Phase 21** | Mobile notification system (push via Laravel + FCM) | P2 | Feature |
| **Phase 22** | Medical reports & analytics (admin dashboards) | P2 | Feature |
| **Phase 23** | Multi-language support expansion | P3 | If needed beyond EN/AR |
| **Phase 24** | Security audit (penetration testing, dependency audit) | P1 | Before next production release |

---

## Files Generated This Session

| File | Description |
|------|-------------|
| `RULES.md` | Complete coding conventions, naming, architecture rules, business rules, security rules |
| `TASKS.md` | This file — task tracker, phase breakdown, blockers, milestones |

---

## How to Update This Document

- Mark tasks `[x]` when complete
- Add new tasks under the appropriate phase with `🆕`
- Update milestone dates when they shift
- Add blockers to the "Active Blockers & Risks" table
- Never delete completed task rows — they serve as audit history
