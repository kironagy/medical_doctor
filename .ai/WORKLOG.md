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
