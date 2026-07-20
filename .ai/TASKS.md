# Medical Plus V3 — Task Tracker

**الحالة العامة: Phases 1-15 COMPLETE | Phase 16 IN PROGRESS**
آخر تحديث: 2026-07-20

---

# Phase 1 - Project Analysis
- [x] Analyze folder structure
- [x] Analyze Models
- [x] Analyze Controllers
- [x] Analyze Services
- [x] Analyze Middleware
- [x] Analyze Policies
- [x] Analyze Routes
- [x] Analyze API
- [x] Generate architecture summary → `ARCHITECTURE.md`

# Phase 2 - Mobile Foundation
- [x] Initialize NativePHP project — `php artisan native:install` generated Android + iOS scaffolds
- [x] Configure project structure — `.env.native`, `.nativephp/` scripts, `NativeServiceProvider` wired
- [x] Setup Dependency Injection — `ApiService` singleton, Hybrid repo auto-switch
- [x] Setup Repository Pattern — 3-tier (Api / Eloquent / Hybrid), 5 interfaces bound
- [x] Setup Service Layer — FullSync, SyncQueue, NetworkStatus, ApiService, 6 upload services
- [x] Setup SQLite — `storage/data/medical_plus.sqlite` (24 tables)
- [x] Setup Migrations — 25 migrations applied to SQLite

# Phase 3 - Authentication
- [x] Login UI — Vue Inertia form, `np_persist_login` flag, auto-redirect on restart
- [x] API Login — `/api/v1/login` → Sanctum token
- [x] Token Storage — session (primary) + encrypted `sync_states` DB (fallback)
- [x] Offline Login — local SQLite `Auth::attempt()` + remote fallback
- [x] Session Restore — WebView cookie persistence + `scheduleStartupSync()`

# Phase 4 - Synchronization Engine
- [x] Connectivity Monitor — `NetworkStatusService` (60s/15s cache)
- [x] Sync Queue — `sync_queue` table, `SyncQueueService` (priority, retry, status)
- [x] Pending Create/Update/Delete — Hybrid repos local-first → API → queue on failure
- [x] Conflict Resolution — `client_updated_at` delta-sync, 422 → force-delete local
- [x] Background Sync — `NativeSyncController` push + pull, 5 sync endpoints

# Phase 5 - Patients
- [x] Patient List — paginated, search, archived toggle
- [x] Search — unified (patients + files + doctors), min 2 chars
- [x] Filters — date range, time range, type, mime_type, sort
- [x] Pagination — 6/page (categories), 50 (visits/notes), 100 (mobile files)
- [x] Patient Details — full payload with visits/files/notes/counts/permissions
- [x] Create/Edit/Delete — full medical fields, soft-delete, restore, force-delete
- [x] Offline CRUD — local-first write, API sync, queue on failure
- [x] Sync Validation — 401 → re-login, 422 → force-delete stale

# Phase 6 - Visits & Notes
- [x] Visits CRUD — type, reason, session_details, diagnosis, prescription, cost
- [x] Notes CRUD — author tracking, default category 'general'
- [x] Visit/Note Offline Sync — PendingOperation + SyncQueueItem both supported

# Phase 7 - Files & Media
- [x] File Upload (Direct) — max 500MB, type detection
- [x] Chunked Upload — init/chunk/complete/cancel/status + resume, 1MB-50MB chunks
- [x] File Access & Streaming — HTTP Range (206), signed URLs (6h), thumbnails (ffmpeg/GD)
- [x] File Management — update metadata, delete with cleanup
- [x] File Categorization — 6 default categories (bilingual EN/AR), custom per-user
- [x] File Offline Sync — HybridPatientFileRepository, priority 3 queue, binary download

# Phase 8 - Sharing & Permissions
- [x] Patient Sharing — list/create/delete shares, updateOrCreate (idempotent)
- [x] Access Levels — read / read_write, expiry support, active-share check
- [x] Doctor Search — by name/email/specialization, min 2 chars

# Phase 9 - Admin & Dashboard
- [x] Admin Dashboard — doctor stats, recent doctors
- [x] Doctor Management — CRUD, suspend/activate, per-doctor patient/file stats
- [x] User Dashboard — stats cards (patients, files, shares, doctors)
- [x] Mobile Dashboard — `/api/v1/mobile/dashboard/stats`

# Phase 10 - Upload & Media Processing
- [x] Chunked Upload Pipeline — 6 service classes, direct-write optimization
- [x] Video Optimization — ffmpeg faststart + thumbnail extraction
- [x] Upload Frontend — `useUploads.js` (4-parallel pool, semaphore, resume, retry)

# Phase 11 - Settings & Profile
- [x] Profile Management — avatar (cropper.js), name, email, phone
- [x] Password Management — strength indicator, confirmation
- [x] Preferences — theme (light/dark/system) + locale (en/ar) with RTL
- [x] Category Management — CRUD, super-admin global vs per-user
- [x] App Download — GitHub releases API, APK download

# Phase 12 - Printing & Export
- [x] Patient Print View — `PatientPrint.vue`, `@media print` CSS
- [x] Patient Export — JSON streaming + ZIP download with `ExportPatientFilesJob`

# Phase 13 - UI Foundation (Vue + Inertia)
- [x] Inertia App Setup — 3 persistent Vue roots
- [x] Layout System — 3-column grid, mobile sidebar, bottom nav
- [x] Base Components — Button, Card, Dialog, Input, GlobalDialog, Toast, PullToRefresh
- [x] i18n System — `en.json` + `ar.json`, RTL, Cairo + Inter fonts
- [x] Theme System — light/dark/system with Tailwind v4
- [x] Navigation & Guards — client-side auth check, redirect to /login

# Phase 14 - NativePHP Integration
- [x] NativePHP Install — Android + iOS scaffolds in `nativephp/`
- [x] Native Service Provider — 5 plugins, conditionally registered
- [x] Plugin Bridge Functions — 12 functions (Camera, Dialog, File, Network, Share)
- [x] Native Build Scripts — dev + production pipelines
- [x] NativePHP Config — 470 lines, SDK 35, min SDK 26, R8/ProGuard
- [x] App Version Management — v1.0.36, code 49, `com.medicalplus.app`
- [x] NativePHP Startup Lifecycle — auto-sync on app open, migration version check

# Phase 15 - Developer Tooling
- [x] Profiler Middleware — dev-only request profiling (timing, memory, SQL)
- [x] Upload Diagnostics — opt-in chunk-level profiler
- [x] Client Error Reporting — global `captureError` → `/api/v1/log/client-error`
- [x] Activity Logging — `ActivityLogger` audit trail
- [x] Debug Endpoint Fix — `/debug-state` returns 404 when `APP_DEBUG=false`

# Phase 16 - Production Readiness & Quality Gates
> Audit date: 2026-07-20
> Source: Performance, Build & Quality Requirements document

## Build Size Targets
- Target APK: < 25 MB preferred, < 30 MB acceptable
- Current public/build output: 1.9 MB (browser assets only)
- NativePHP bundle size depends on PHP runtime + framework + app code

## Dependencies Cleanup ✅ COMPLETED
- [x] Move @vitejs/plugin-vue from dependencies → devDependencies (it is a Vite build plugin, never shipped to browser)
- [x] Remove unused `concurrently` from devDependencies (no concurrent scripts in package.json)

## Vite Production Optimization ✅ COMPLETED
- [x] Add `build.minify: 'terser'` with `compress.drop_console: true`
- [x] Add `build.rollupOptions.output.manualChunks` for vendor splitting:
  - `vendor`: vue, vue-i18n, axios, @inertiajs/vue3
  - `media`: video.js, cropperjs, highlight.js, v-viewer, viewerjs
- [x] Replace eager `import.meta.glob('./Pages/**/*.vue')` with per-route `import()` for code splitting

## Debug Code Removal ✅ COMPLETED
- [x] Guard `console.log` in useWorkspace.js with `if (import.meta.env.DEV)`
- [x] Guard `console.log` in useUploads.js with `if (import.meta.env.DEV)`
- [x] Remove useUploadDiagnostics.js from production bundle (move behind dynamic import triggered by ?debug=1 or localStorage flag)

## Asset Optimization
- [ ] Remove duplicate icon.png from public/ (keep only resources/images/)
- [ ] Subset Cairo and Inter font files to used glyphs only
- [ ] Verify public/fonts/ are properly loaded and not duplicated in bundle

## NativePHP Bundle Cleanup
- [x] Add `storage/app/mobile-cache/` to cleanup_exclude_files in config/nativephp.php
- [x] Verify .env files are fully scrubbed/stripped from bundle (not just env keys redacted)
- [ ] Verify storage/app/public/ files are excluded from bundle

## Project Root Cleanup ✅ COMPLETED
- [x] Remove .env.backup* files from project root (security risk)
- [x] Remove app_debug.log from project root (should be in storage/logs/)
- [ ] Remove .DS_Store files from repository

## Testing Infrastructure
- [x] PHPUnit 12 installed and configured
- [x] phpunit.xml configured with sqlite :memory: database
- [x] Existing tests: 2 Feature tests + 2 Unit tests (baseline)
- [ ] Write API endpoint tests for mobile auth flow
- [ ] Write API endpoint tests for patient CRUD
- [ ] Write offline sync integration test
- [ ] Write file upload/chunked upload test
- [ ] Write authorization/policy test (DoctorIsolationScope)
- [ ] Add xdebug/pcov for code coverage reporting

## Performance Verification
- [ ] Measure APK size after build optimization
- [ ] Verify Cold Start Time < 3 seconds
- [ ] Verify Screen Transition < 300ms
- [ ] Verify List Scroll remains 60fps with 1000+ items
- [ ] Verify Search debouncing (300ms) works correctly
- [ ] Verify no unnecessary re-renders in Vue components
- [ ] Verify batch SQLite operations for sync
- [ ] Verify memory usage stays under 150MB during normal use
- [ ] Verify background sync does not block UI thread

## Regression Testing Checklist
- [ ] Verify existing web routes still work (128 routes)
- [ ] Verify mobile API routes still work (36 routes)
- [ ] Verify authentication flow (login/logout/token)
- [ ] Verify offline login still works
- [ ] Verify session restore (np_persist_login)
- [ ] Verify sync engine (push + pull)
- [ ] Verify patient CRUD operations
- [ ] Verify file upload (direct + chunked)
- [ ] Verify video streaming + thumbnails
- [ ] Verify sharing + permissions
- [ ] Verify admin doctor management
- [ ] Verify settings/profile/password
- [ ] Verify print + export
- [ ] Verify i18n (EN + AR + RTL)
- [ ] Verify theme switching (light/dark/system)

## Final Acceptance Criteria
- [ ] All tasks in Phases 1-16 completed
- [ ] All tests pass (phpunit --testdox)
- [ ] Offline mode works correctly (local SQLite + queue)
- [ ] Online mode works correctly (API sync)
- [ ] Synchronization is reliable (no data loss)
- [ ] UI matches production website (visual regression check)
- [ ] Performance is smooth (see Performance Verification above)
- [ ] Build is optimized (APK < 30MB, dead code removed)
- [ ] No critical bugs remain
- [ ] No unfinished TODOs in production code
