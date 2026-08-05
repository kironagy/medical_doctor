# WORKLOG.md — Medical Plus (Medical_Plus_v4)

> Living changelog. Append one entry per completed task, newest at the bottom. Never delete or rewrite past entries — if something described here turns out wrong, add a correction entry, don't erase history.
> See `.ai/PROJECT_MEMORY.md` for the standing architecture/known-issues reference this log feeds into.

---

## 2026-08-05 — Codebase reliability audit (Sync / Upload / Auth)

**Task:** Analyze the project for critical bugs preventing reliable online/offline behavior (user request: app should behave as smoothly as the website, both online and offline).

**Files changed:** None — read-only audit, no source files modified.

**Reason:** User reported frequent bugs and asked for root-cause analysis before any fix work started.

**Risks:** None (no code touched).

**Tests performed:** None (analysis only, no code changes to test).

**Result:** 16 issues identified and severity-ranked across three areas:
- Sync engine (`app/Services/Sync/*`, `DownloadSyncService.php` in particular) — timestamp-comparison bug breaking same-day incremental sync, notes/visits download gated on the wrong signal, sync_queue permanent-orphan-after-5-retries, VisitSyncService local-ID leakage risk.
- Upload/media pipeline (`app/Services/Upload/*`, `FileSyncService.php`) — checksum bypass on video direct-write path, resumable uploads that don't actually resume, orphaned partial files, retry-induced false failures, file handle leak.
- Auth/bootstrap layer (`AuthController.php`, `bootstrap/app.php`) — full auth bypass on offline/SQLite builds, global exception-swallowing hiding all other bugs, dead duplicate repository classes as a landmine.

**Next recommended task:** Turn findings into an actionable, trackable implementation roadmap (see next entry).

---

## 2026-08-05 — Created `docs/OFFLINE_FIRST_MASTER_PLAN.md`

**Task:** Build a permanent, sprint-based implementation roadmap for making the existing offline-first architecture production-ready, based on the audit above — no redesign, minimum-diff, minimum-regression-risk approach.

**Files changed:**
- `docs/OFFLINE_FIRST_MASTER_PLAN.md` (new)

**Reason:** User wants a durable planning document any future session (human or AI) can pick up mid-project without losing context, structured around the entity lifecycles (Patient/Notes/Files/Visits) plus cross-cutting reliability and performance work.

**Risks:** None — documentation only, no source changes.

**Tests performed:** None applicable.

**Result:** 6-sprint roadmap created (Patient Lifecycle → Notes Lifecycle → Files & Attachments Lifecycle → Visits Lifecycle → Reliability & Sync Engine → Performance & Production Hardening), each with Current Problems / Expected Result / Files Expected To Change / Files That Must NOT Change / Regression Risks / Acceptance Criteria / Manual Test Checklist / Sprint Status. Also includes a shared Regression Checklist, a Known Issues table (all 16 audit findings, severity-grouped, sprint-mapped), a Sprint Progress Dashboard, and a Session Handoff section. Two findings (auth bypass, exception-swallowing) didn't fit the lifecycle-sprint shape cleanly and were flagged explicitly as a blocking prerequisite (auth bypass) and a Sprint 5 item (exception handling) rather than forcing a new sprint.

**Next recommended task:** Begin Sprint 1 (Patient Lifecycle) — but resolve the auth-bypass blocking prerequisite first, per the master plan's own flag.

---

## 2026-08-05 — Created `.ai/` permanent AI memory (`PROJECT_MEMORY.md` + this file)

**Task:** Build a permanent, machine-readable project memory so future AI sessions don't need to re-scan the repository from scratch — one full structural pass (tech stack, folder structure, Laravel/Vue/NativePHP architecture, DB schema, routes, middleware, jobs, repositories, services, models/relationships, UUID strategy, sync queue flow) plus the known-bugs/technical-debt context already gathered in the audit above.

**Files changed:**
- `.ai/PROJECT_MEMORY.md` (new)
- `.ai/WORKLOG.md` (new, this file)

**Reason:** User wants a standing "brain" for the project so future sessions read two small files instead of re-exploring the whole codebase, per the RULES this file and `PROJECT_MEMORY.md` now enforce (read `.ai/*` first, only then search the repo for task-specific files, update both after every coding task).

**Risks:** None — documentation only. Main risk going forward is these files going stale if future sessions skip the update-after-every-task rule; that rule is written into both files' RULES sections to guard against it.

**Tests performed:** None applicable.

**Result:** `PROJECT_MEMORY.md` covers all 30 requested sections (architecture, stack, folder map, Laravel/Vue/NativePHP/offline/sync/auth/upload/download flows, SQLite vs MySQL schema, models/services/controllers/routes/middleware/jobs/repositories inventories, relationships, UUID strategy, sync queue detail, known bugs, technical debt, hidden assumptions, current sprint/status, prioritized reading order, never-scan list, source-of-truth list, safe-modification guide, never-change list, and the update RULES). Three new/refined findings surfaced during this pass that were not yet in the master plan's Known Issues table: `ProcessUploadedFileJob` has no found dispatch call site (possibly dead code), `Console/Kernel.php`'s schedule is entirely empty so upload-cleanup/video-optimize commands never run automatically, and `PatientShare` lacks the uuid/soft-delete pattern every sibling entity has.

**Next recommended task:** Before starting Sprint 1 implementation work, reconcile the three newly-surfaced findings above into `docs/OFFLINE_FIRST_MASTER_PLAN.md`'s Known Issues table (they currently exist only in `PROJECT_MEMORY.md` §21), then proceed with the auth-bypass blocking prerequisite.

---

## 2026-08-05 — Sprint 1 (Patient Lifecycle) execution — code fixes for the two in-scope bugs

**Task:** Execute Sprint 1 of `docs/OFFLINE_FIRST_MASTER_PLAN.md` per its documented scope (Patient Lifecycle: Create/Update/Delete/Upload/Download/Manual Sync/Offline Queue/Reinstall Recovery), using existing `.ai/` memory and the master plan as source of truth — no re-audit, no repo re-exploration beyond reading the specific files the sprint scopes.

**Files changed:**
- `app/Services/Sync/DownloadSyncService.php` — (1) `downloadPatients()`: capture the `patients_last_sync` cutover timestamp *before* the paginated fetch loop instead of after, closing the mid-pagination race window. (2) `downloadNotes()`/`downloadVisits()`: write the `notes_last_sync`/`visits_last_sync` cutover in `Y-m-d H:i:s` format (matching `Patient::updated_at`'s storage format) instead of ISO-8601, fixing the lexicographic same-day string-comparison bug in `eligiblePatientsSince()`.
- `docs/OFFLINE_FIRST_MASTER_PLAN.md` — marked the two fixed Current Problems in Sprint 1, added a Sprint 1 Progress Log, set Sprint 1 status to In Progress, checked off the two corresponding Known Issues (Critical timestamp bug, Medium race window), updated the Sprint Progress Dashboard row.
- `.ai/PROJECT_MEMORY.md` — updated §21 Known Bugs (struck through the two fixed items) and §24 Current Sprint/Status to reflect Sprint 1 In Progress + what was fixed + the open blocker.

**Reason:** These were the only two concretely-identified, in-scope code bugs for Sprint 1 per the master plan's "Current Problems"/"Files Expected To Change" for this sprint. `PatientRepository::create()` was reviewed and found already transaction-safe for offline force-close (no code change needed there — confirms, doesn't fix, since nothing was broken).

**Risks:** Low. Both changes are timing/format-only, no logic branches changed, no schema changes, no API contract changes. `notes_last_sync`/`visits_last_sync` are read/written exclusively within this one file (confirmed via repo-wide grep) so the format change can't break another consumer. One transient effect: on the very first sync after this deploy, a stale ISO-formatted cutoff value already stored in `sync_states` from before this fix will still be compared against the new format for one cycle — self-corrects on the next sync since the value gets overwritten in the new format every run; not a data-loss risk, at most one extra sync cycle of re-fetched (not lost) data.

**Tests performed:** `php -l` syntax check on the modified file (passed). No automated test suite exists for `DownloadSyncService`, `PatientRepository`, or Patient sync generally (`tests/` has no matching files) — this is a pre-existing test-coverage gap, not introduced by this change. No live two-sided (mobile+server) environment was available to exercise the actual sync flow in this session.

**Result:** Both in-scope Sprint 1 code bugs fixed. Sprint 1 is **In Progress**, not Completed — blocked from closing by (a) the Auth-bypass prerequisite (intentionally not fixed here — see Blocker below) and (b) the full Manual Test Checklist and Acceptance Criteria in `docs/OFFLINE_FIRST_MASTER_PLAN.md` Sprint 1, none of which have been runtime-verified yet (they require live device/website testing this session couldn't perform).

**Next recommended task:** Get a decision on the Auth-bypass blocker (see below), then run Sprint 1's Manual Test Checklist against a real mobile build + website to close out acceptance criteria before starting Sprint 2.

**Blocker discovered — STOPPED per project rules, did not proceed automatically:** Sprint 1's Reinstall Recovery work cannot be considered production-safe while `AuthController::showLogin` (`app/Http/Controllers/AuthController.php`) auto-logs in as the first `users` row with no password check whenever `database.default === 'sqlite'`. This fix was **not** made in this pass because: (1) `AuthController.php` is not in Sprint 1's documented "Files Expected To Change" list; (2) it's a security/auth-flow fix, categorically different from patient-sync logic; (3) the master plan itself flagged this as a cross-cutting "blocking prerequisite" rather than assigning it cleanly to a sprint. Needs an explicit decision: fix it now as an approved exception to Sprint 1's file scope, or handle it as a separate pre-Sprint-1 task before Reinstall Recovery is marked accepted.

---

## 2026-08-05 — Fix: patients never hydrated into local SQLite cache (`SELECT COUNT(*) FROM patients` stayed 0)

**Task:** User reported that after (1) opening the app online, (2) closing it completely, (3) disabling internet, (4) reopening — the app has zero patients locally, even though SQLite is confirmed persistent (not `:memory:`), migrations ran, and `users` exist. Root-cause and fix, reusing existing architecture only.

**Root cause (confirmed by reading code, not assumed):** `PatientRepository` (the bound `PatientRepositoryInterface`, `app/Repositories/PatientRepository.php`) reads exclusively from local SQLite by design — correct, per architecture. The only code that ever writes remote patients into that table is `DownloadSyncService::downloadPatients()` (already correctly paginated/upserted by `uuid`), but it was `private` and only ever invoked from inside `SyncEngineService::syncAll()` / `ManualSyncService`, which only runs when: (a) the user taps "Sync Now" (`useSyncEngine.js` explicitly documents "NO automatic synchronization — strictly manual trigger", a deliberate design decision, left untouched), or (b) a one-shot fire-and-forget call inside `Login.vue`'s `submit()` handler. That handler only executes on an actual login **form submission** — [Login.vue:80-82](resources/js/Pages/Auth/Login.vue) redirects an already-authenticated user straight to `/workspace`, bypassing `submit()` entirely. So any app open after the very first login never re-populates patients, and even that first-login call is a best-effort fire-and-forget that can lose the race if the app is closed quickly. `BootstrapController::refreshCache()` — the endpoint explicitly built for "cache all master data required for offline operation," already invoked after login — cached categories and the user profile but never patients, which was the actual gap.

**Files changed:**
- `app/Services/Sync/DownloadSyncService.php` — made `downloadPatients()` public (was private) so it can be triggered standalone without running the rest of the push/pull pipeline; split its combined counter into `inserted`/`updated`; added `[PatientCache] started` / `downloaded count=` / `inserted count=` / `updated count=` / `failed reason=` log lines. No change to the pagination/upsert/UUID-preservation logic itself (already correct).
- `app/Http/Controllers/Api/Mobile/BootstrapController.php` — `refreshCache()` now also calls `DownloadSyncService::downloadPatients()` after the categories step, wrapped in try/catch, result recorded in the `cached` response payload. Reuses the already-SQLite-guarded, already-token-aware bootstrap endpoint — no new sync system.
- `resources/js/Layouts/AppLayout.vue` — added `hydratePatientCacheOnce()`, fired from the existing `onMounted` "Initial data refresh when online on startup" block (only `if (navigator.onLine)`), calling the same `/_native/api/bootstrap/refresh` endpoint Login.vue already calls. Guarded by a module-level flag so it fires once per app launch, not on every Inertia page navigation. This is what closes the "already logged in, just reopening the app" gap — the actual scenario in the bug report.

**Reason:** Minimal, additive fix per Architecture Principles (`docs/OFFLINE_FIRST_MASTER_PLAN.md`) — no new sync mechanism, no changes to `sync_queue`/pending_create/pending_update/pending_delete, `SyncEngineService`, or notes/visits sync (untouched, out of scope). Retry-on-transient-failure and expired-token handling are already provided by `RemoteApiService::get()` (2x retry, throws `AuthenticationException` on 401) and were left as-is — the new code's try/catch around `downloadPatients()` already logs and swallows those gracefully without partial writes (each Eloquent create/update is a single atomic statement).

**Risks:** Low. `downloadPatients()`'s signature changed from `private function downloadPatients(array &$summary): void` to `public function downloadPatients(?array &$summary = null): array` — the sole existing caller (`downloadChanges()`) still passes `$summary` positionally and ignores the new return value, so it's unaffected. The new frontend call is fire-and-forget and non-blocking; `ws.refreshPatientList()` (which reads the already-loaded local list) still fires immediately after it, so the visible patient list on boot is unaffected — only backfilled by the hydration call in the background for the *next* time the list is read while offline.

**Tests performed:** `php -l` on both modified PHP files (passed). No live device/two-sided environment available this session — this is unverified against a real mobile build; see verification commands below for the user to run themselves.

**Result:** Root cause fixed by adding the missing "download patients on every online app boot" trigger, reusing 100% of existing sync services. Sprint 1 status/master-plan not modified — this fix is a bug fix within Sprint 1's existing "Patient Lifecycle" scope (`DownloadSyncService.php` is already in Sprint 1's "Files Expected To Change" list), not a new sprint.
