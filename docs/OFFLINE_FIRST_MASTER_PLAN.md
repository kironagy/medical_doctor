# Offline-First Master Plan — Medical Plus (Laravel + Vue + NativePHP)

> **Status:** Living document — this is the single source of truth for the Offline-First hardening project.
> **Last updated:** 2026-08-05
> **Do not delete history from this file.** Update statuses in place; append notes instead of overwriting past sprint outcomes.

---

## Project Goal

Medical Plus ships as two faces of the same application: a web app (always online, source of truth) and a NativePHP mobile app (offline-first, backed by a local SQLite cache) used by doctors in the field. Today the two diverge under real-world conditions (flaky networks, app restarts, reinstalls, large video uploads), which produces silent data loss and unpredictable behavior.

This project does **not** redesign the architecture. It makes the *existing* architecture (SQLite local cache + `SyncQueue` + `DownloadSyncService`/`*SyncService` classes + chunked upload pipeline) trustworthy enough for production clinical use, with the smallest possible code diff and the smallest possible regression surface.

By the end of this roadmap, the application must guarantee:

- **Patient data never disappears** — created/edited patients survive offline queueing, app kills, and reinstalls.
- **Files are never lost** — uploaded images/videos/documents always end up on the server intact, or the failure is visible and retryable, never silent.
- **Notes are never lost** — clinical notes created offline always reach the server, and notes created elsewhere always reach the device.
- **Offline edits survive app restarts** — force-closing or crashing the app must not lose queued work.
- **Manual Sync always reconciles both sides** — running Manual Sync is guaranteed to converge local and remote state, not just "do something."
- **Reinstalling the application downloads everything again** — a fresh install with valid credentials fully rehydrates from the server with zero gaps.
- **SQLite becomes a reliable offline cache** — not a second, quietly-diverging database.
- **Website and Mobile always converge to the same state after synchronization** — no permanently orphaned records, no phantom duplicates, no records that exist on one side only because of a bug (as opposed to a deliberate offline queue state).

---

## Architecture Principles

Every sprint, every PR, every future Claude session **must** respect these rules. If a proposed change violates one of these, stop and flag it instead of proceeding.

1. **UUID is the primary identity.** Local autoincrement integer IDs (`patients.id`, `visits.id`, …) are a SQLite implementation detail only. Any code that sends a local numeric ID to a remote endpoint (instead of `remote_uuid`) is a bug, not a feature.
2. **Never trust local numeric IDs across the network boundary.** Every sync operation (create/update/delete) must resolve and use the UUID/remote identifier before talking to the API.
3. **Never delete local data before a successful, confirmed sync.** Deletion is always: queue the intent → confirm remote success → then remove locally (or mark tombstoned). Never delete-then-sync.
4. **No breaking changes to the API contract** between mobile and web unless a sprint explicitly says so and both sides are updated together.
5. **Preserve backward compatibility** with existing local SQLite databases already installed on doctors' devices — no migration may silently drop data.
6. **Small, isolated commits.** One bug class per commit. Do not bundle a Patient fix with a Files fix.
7. **One feature/bug-class at a time**, per sprint. Do not start Sprint N+1 work while Sprint N acceptance criteria are unmet.
8. **Regression testing after every sprint**, using the shared Regression Checklist below — not just the sprint's own acceptance criteria.
9. **Errors must be visible, never silently swallowed.** Any change that catches an exception must either re-surface it (log + user-visible state) or justify in a code comment why silence is safe. Silent `catch` blocks are treated as bugs.
10. **Idempotency by default.** Any sync operation must be safe to run twice (crash-and-retry, duplicate queue item, re-run of Manual Sync) without creating duplicates or corrupting state.
11. **Prefer additive changes over rewrites.** Fix the function in place; do not replace a whole service class unless the sprint explicitly scopes a rewrite.

---

## How This Plan Was Built

This document is based on three independent audits of the current codebase (Sync engine, Upload/Media pipeline, Auth/bootstrap layer) performed before this plan was written. Every issue found in those audits is tracked in the **Known Issues** section and mapped to the sprint that owns fixing it. No new issues should be invented without evidence from the actual code.

---

## Sprint 1 — Patient Lifecycle

**Goal:** Make the complete Patient lifecycle (Create, Update, Delete, Upload, Download, Manual Sync, Offline Queue, Reinstall Recovery) production-safe.

**Scope is patients only** — not notes, not visits, not files (those are separate sprints, even though they hang off a patient).

### Current Problems

- Incremental patient download uses `patients_last_sync` captured *after* the fetch loop completes, not before — a patient created/modified server-side mid-pagination can fall in the gap and never be re-fetched on the next sync. (`DownloadSyncService.php`)
- Local vs. remote `updated_at` comparison is a **text/lexicographic** comparison between two different timestamp formats (`Y-m-d H:i:s` vs ISO `…T…Z`), which is unreliable for same-day comparisons on SQLite. This directly affects whether a patient is considered "changed" for cascading note/visit re-fetch.
- No confirmed guarantee that a patient created **offline** and queued survives an app force-close before its first sync attempt (needs verification — not yet confirmed as broken, must be tested explicitly in this sprint).
- Reinstall/first-run download flow has not been verified end-to-end against a large real dataset (pagination correctness, partial-failure resume).
- Blocking prerequisite discovered in the Auth layer (see Known Issues, "Auth bypass on offline builds") — this must be resolved before Sprint 1's Reinstall Recovery work can be considered safe, since reinstall flow re-enters the login screen.

### Expected Result

- A patient created/edited on the website is guaranteed to appear on mobile after the next sync, regardless of same-day timing.
- A patient created/edited offline on mobile is guaranteed to appear on the website after the next Manual Sync, and survives app kill/restart before that sync happens.
- Deleting a patient propagates correctly in both directions without resurrecting the record on the next sync.
- A fresh reinstall, given valid login, downloads the complete patient list with no gaps, regardless of dataset size or pagination.

### Files Expected To Change

- `app/Services/Sync/DownloadSyncService.php`
- `app/Domains/Sync/Services/SyncQueueService.php` (only the parts relevant to patient queue items)
- `app/Repositories/PatientRepository.php`, `app/Repositories/Eloquent/EloquentPatientRepository.php`
- `app/Domains/Patients/Models/Patient.php` (only if a timestamp/UUID field needs a fix, not a schema rewrite)
- `app/Http/Controllers/Api/Mobile/PatientController.php` (only if a server-side response/filter needs alignment)

### Files That Must NOT Change

- Notes/Visits sync services (`NoteSyncService.php`, `VisitSyncService.php`) — out of scope for this sprint.
- Upload/Chunk pipeline (`app/Services/Upload/*`) — out of scope.
- `bootstrap/app.php` global exception handling — owned by Sprint 5, do not touch here even though it's tempting.
- Any Vue/frontend component not directly displaying patient sync status.

### Regression Risks

- Fixing the timestamp comparison could change which patients are considered "already synced," potentially triggering a one-time large re-download on existing installs — must be tested against a realistic existing SQLite DB, not just a fresh one.
- Changing the cutoff-capture timing (before vs after fetch loop) could cause an infinite re-fetch loop if not paired correctly with pagination logic — verify termination.

### Acceptance Criteria

- [ ] Patient created on website appears on mobile after one sync, tested same-day (not just next-day).
- [ ] Patient edited on website (no field changes to name/UUID, just e.g. notes count trigger) is correctly detected as "changed" for cascading sync.
- [ ] Patient created offline on mobile survives a force-close before syncing, and syncs correctly afterward.
- [ ] Patient deleted on website disappears from mobile after sync, and does not reappear on a second sync.
- [ ] Patient deleted on mobile (queued offline) is deleted on website after Manual Sync.
- [ ] Fresh install + login downloads 100% of patients for a test account with a dataset larger than one page.
- [ ] No duplicate patient rows after two consecutive Manual Syncs with no intervening changes.

### Manual Test Checklist

1. Create patient on website → run Manual Sync on mobile same day → confirm patient appears.
2. Create patient on mobile while offline → force-kill app → reopen → confirm patient still queued → go online → Manual Sync → confirm it appears on website.
3. Delete patient on website → Manual Sync on mobile → confirm removal, then sync again → confirm it does not come back.
4. Uninstall and reinstall the mobile app → log in → confirm full patient list matches website within one sync cycle.
5. Run Manual Sync twice in a row with no changes in between → confirm patient count is unchanged (no duplicates).

### Sprint Status

- [ ] Not Started
- [ ] In Progress
- [ ] Blocked
- [ ] Completed

---

## Sprint 2 — Notes Lifecycle

**Goal:** Make the complete Notes lifecycle (Create, Update, Delete, Offline, Upload, Download, Recovery) production-safe.

**Depends on:** Sprint 1 must be Completed first (notes hang off a correctly-syncing patient).

### Current Problems

- Note/visit incremental download is gated on "did the *parent patient's* `updated_at` change," not on any direct signal from the notes themselves. If the server doesn't bump the patient row when a note is added elsewhere, new notes never reach the device.
- Note sync queue items call `markFailed` (consuming one of the 5 allowed retries) merely because the parent patient isn't yet marked `synced` — this is a dependency-ordering problem being treated as a failure, burning retries that should be reserved for real errors.
- No confirmed idempotency check: does re-syncing the same offline-created note twice (e.g., due to a retry after a false failure) create a duplicate note on the server?

### Expected Result

- A note created on the website reaches mobile on the next sync, independent of whether the patient record itself changed.
- A note created offline on mobile reaches the website after Manual Sync, exactly once, even if the sync had to retry.
- A note queued before its parent patient has synced is not penalized with a wasted retry — it waits correctly and then sends once the parent is ready.
- Notes survive app restart before they've synced.

### Files Expected To Change

- `app/Services/Sync/NoteSyncService.php`
- `app/Services/Sync/DownloadSyncService.php` (notes-download portion only — coordinate with Sprint 1 changes, do not re-touch the patient-download portion)
- `app/Domains/Sync/Services/SyncQueueService.php` (add a distinct "waiting on dependency" state, separate from "failed", if needed)
- `app/Repositories/Eloquent/EloquentPatientNoteRepository.php`

### Files That Must NOT Change

- Patient sync logic already completed in Sprint 1 — do not reopen unless a regression is found.
- Visit and File sync services — out of scope, handled in Sprints 3 and 4.

### Regression Risks

- Introducing a "waiting on dependency" queue state must not break the existing `retry_count < 5` filtering logic used elsewhere — audit every place that reads `SyncQueue` status.
- Decoupling note-download from patient `updated_at` may increase API call volume (a per-patient notes poll) — must be checked against server rate limits/performance.

### Acceptance Criteria

- [ ] Note added on website (with no other patient field changes) appears on mobile after next sync.
- [ ] Note created offline on mobile appears on website exactly once after Manual Sync (no duplicates even after a forced retry).
- [ ] A note queued before its parent patient syncs does not consume a "failure" retry; it sends successfully once the parent is ready.
- [ ] Note created offline survives force-close/restart before syncing.
- [ ] Deleting a note propagates correctly in both directions without resurrecting.

### Manual Test Checklist

1. Add a note to an existing, already-synced patient on the website → Manual Sync on mobile → confirm note appears.
2. Create a **new** patient and a note on it, both offline, in the same offline session → go online → Manual Sync → confirm both patient and note reach the website, note not lost.
3. Force-kill the app immediately after creating an offline note, before syncing → reopen → confirm note still queued → sync → confirm it arrives once.
4. Delete a note on mobile offline → sync → confirm deleted on website → sync again → confirm it does not reappear.

### Sprint Status

- [ ] Not Started
- [ ] In Progress
- [ ] Blocked
- [ ] Completed

---

## Sprint 3 — Files & Attachments Lifecycle

**Goal:** Make the complete file/attachment lifecycle production-safe across all media types: Images, Videos, PDF, Documents, Audio.

**This is the largest sprint.** Depends on Sprint 1 (patient) being complete; can run in parallel with Sprint 2/4 if staffed separately, but must not touch note/visit sync files.

### Current Problems

- **Checksum validation is bypassed** for the direct-write (video/resumable) upload path — the final hash is never computed for this path, so a corrupted/truncated upload can be accepted as valid.
- **Resumable upload does not actually resume.** Every retry (including automatic ones triggered by a sync-queue retry) opens a brand-new upload session from scratch instead of recovering a prior in-progress session, causing full re-upload of large files (e.g., videos) after any network drop.
- **Orphaned partial files accumulate on disk.** Cleanup only removes the legacy chunk-directory files; the direct-write path's partial file at `final_path` is never deleted when a session expires or is abandoned, silently consuming server disk space over time.
- **A successful `/chunk/complete` can be reported as a failure.** The HTTP client's automatic retry can re-send `/chunk/complete` after a slow-but-successful merge, hitting "session not in uploading state" and causing the client to treat a successful upload as failed, triggering a wasted re-upload.
- **File handle leak on chunk upload failure** — the local file handle used to read chunks for upload is not closed if an exception occurs mid-loop, leaking file descriptors under repeated failures on poor connections.
- No per-chunk retry — a single chunk failure discards the entire in-progress upload attempt rather than retrying just that chunk.

### Expected Result

- Every uploaded file (any media type) is verified end-to-end with a checksum before being accepted as complete, regardless of upload path (chunked or direct-write).
- A dropped connection mid-upload resumes from the last successfully-uploaded chunk on retry, not from zero.
- No partial/orphaned files accumulate on the server after failed or abandoned uploads — cleanup covers both the legacy chunk path and the direct-write path.
- A slow-but-successful merge is never misreported as a failure to the client.
- File uploads (and their deletions) survive app restarts and are visible in Manual Sync status.
- Reinstalling the app and re-downloading a patient's files reproduces the exact same file set with no duplicates and no missing files.

### Files Expected To Change

- `app/Services/Upload/ChunkMergeService.php`
- `app/Services/Upload/UploadSessionService.php`
- `app/Services/Upload/UploadCleanupService.php`
- `app/Services/Upload/UploadChecksumService.php`
- `app/Services/Upload/UploadValidationService.php`
- `app/Services/Sync/FileSyncService.php`
- `app/Services/Mobile/RemoteApiService.php` (only the retry/timeout config for chunk endpoints — do not touch unrelated request paths)
- `app/Http/Controllers/Api/ChunkUploadController.php`
- `app/Domains/Media/Services/UploadService.php`
- `app/Domains/Media/Models/UploadSession.php`

### Files That Must NOT Change

- `app/Services/Mobile/FileCacheService.php` — already confirmed correct (proper buffered range streaming), do not touch without a specific found bug.
- `app/Services/OfflineUploadService.php` — already confirmed correct (streaming hash, no full-file memory loads).
- Patient/Note/Visit sync services — out of scope.
- `app/Domains/Media/Jobs/OptimizeVideoForStreaming.php`, `GenerateThumbnailJob.php` — post-processing, out of scope unless a bug in this sprint's territory is found to originate there.

### Regression Risks

- Adding checksum verification to the direct-write path could reject legitimately-large videos if the hash computation isn't streamed (memory risk) — must reuse the existing streaming-hash pattern already proven correct in `OfflineUploadService.php`.
- Making resumable uploads actually resumable requires session lookup keyed by file identity (e.g., sha256 + patient + size) — must not collide across different patients/files with coincidentally similar names.
- Cleanup changes must not delete a file mid-upload due to a race between an active session and the cleanup job's expiry check.

### Acceptance Criteria

- [ ] Every completed upload (chunked and direct-write) has a non-null, verified checksum recorded.
- [ ] A video upload interrupted mid-transfer resumes from the last chunk on next attempt, not from zero (verified by measured bytes re-sent).
- [ ] No orphaned partial files remain on disk 24 hours after a simulated series of failed uploads.
- [ ] A `/chunk/complete` call that times out client-side but succeeded server-side does not cause a duplicate upload or a false failure.
- [ ] File deletion (image, video, PDF, document, audio) propagates in both directions without resurrecting.
- [ ] Category-to-file mapping survives sync and reinstall.
- [ ] Reinstall + re-download reproduces the identical file set for a test patient (count and checksums match).
- [ ] File handles are closed correctly even on upload failure (no descriptor leak under repeated-failure testing).

### Manual Test Checklist

1. Upload a large video, kill network mid-upload, restore network, confirm it resumes rather than restarting.
2. Upload a video on a throttled/flaky connection until at least one retry occurs, then verify checksum matches original file.
3. Deliberately fail an upload 3 times, then check the server's temp/upload directory for orphaned partial files — confirm cleanup removes them.
4. Upload one file of each type (image, video, PDF, document, audio) to a patient, delete one, sync, confirm correct state on website.
5. Reinstall the mobile app, log in, sync, and diff the downloaded file set (count + checksums) against the website for one test patient.
6. Simulate a slow merge (large file) and confirm no duplicate "failed then succeeded" behavior in sync queue status.

### Sprint Status

- [ ] Not Started
- [ ] In Progress
- [ ] Blocked
- [ ] Completed

---

## Sprint 4 — Visits Lifecycle

**Goal:** Make the complete Visit lifecycle (Create, Update, Delete, Offline, Upload, Download, Recovery) production-safe.

**Depends on:** Sprint 1 complete. Can run after or alongside Sprint 2 (similar shape of work), but must not touch note-sync files.

### Current Problems

- Visit update/delete operations use `$visit->remote_uuid ?? $visit->id` to identify the remote resource. If a visit's `create` sync item hasn't completed yet (e.g., stuck in the queue, or a dependency-ordering delay), a subsequent update/delete sends the **local integer ID** instead of the UUID — risking a 404 that masks the real bug, or worse, mutating an unrelated visit that happens to share that numeric ID on the server.
- Visit incremental download shares the same parent-patient-`updated_at` gating problem identified in Sprint 2 for notes — needs the same fix pattern applied consistently.

### Expected Result

- Visit create/update/delete always resolves and uses the correct remote identifier — never a raw local ID sent to the server.
- An update/delete queued before the visit's own create has synced is deferred correctly (not attempted with a wrong ID, and not silently dropped).
- Visits created on the website reach mobile on the next sync, independent of unrelated patient-field changes.
- Visits survive app restart before they've synced.

### Files Expected To Change

- `app/Services/Sync/VisitSyncService.php`
- `app/Services/Sync/DownloadSyncService.php` (visits-download portion only — coordinate with Sprint 1/2 changes)
- `app/Repositories/Eloquent/EloquentPatientVisitRepository.php`
- `app/Domains/Sync/Services/SyncQueueService.php` (reuse the "waiting on dependency" state introduced in Sprint 2, if applicable — do not build a second, parallel mechanism)

### Files That Must NOT Change

- `app/Services/Sync/NoteSyncService.php` — Sprint 2's territory; reuse its dependency-waiting pattern, don't reimplement it here.
- File/upload pipeline — out of scope.
- Patient sync logic — already completed in Sprint 1.

### Regression Risks

- Reusing the Sprint 2 "waiting on dependency" queue state must be verified against Visit's specific queue item shape — don't assume identical structure to Notes without checking.
- Guarding against local-ID leakage to the server requires auditing every call site that reads `visit->id` near a network call, not just the two known lines.

### Acceptance Criteria

- [ ] No network call ever sends a local integer ID where a UUID/remote identifier is expected for a visit (audited across the whole service, not just previously-known lines).
- [ ] A visit update/delete queued immediately after its create (before the create has synced) resolves correctly once the create completes, without hitting the wrong record.
- [ ] Visit added on website appears on mobile after next sync, independent of other patient field changes.
- [ ] Visit created offline survives force-close/restart before syncing.
- [ ] Visit deletion propagates in both directions without resurrecting.

### Manual Test Checklist

1. Create a visit offline, then immediately edit it offline before ever syncing → go online → Manual Sync → confirm the edit applied to the correct visit on the website (not a different one).
2. Create a visit on the website → Manual Sync on mobile same day → confirm it appears.
3. Force-kill the app right after creating an offline visit → reopen → confirm it's still queued → sync → confirm correct arrival.
4. Delete a visit on mobile offline → sync → confirm removal on website → sync again → confirm no resurrection.

### Sprint Status

- [ ] Not Started
- [ ] In Progress
- [ ] Blocked
- [ ] Completed

---

## Sprint 5 — Reliability & Synchronization Engine

**Goal:** Cross-cutting reliability fixes to the sync engine itself, once Patient/Notes/Files/Visits lifecycles are individually correct. **No architecture redesign.**

**Depends on:** Sprints 1–4 complete.

### Current Problems

- **Sync queue items are permanently orphaned after 5 failed attempts**, with no recovery/requeue path visible to the user. A multi-day network outage or a string of dependency-ordering false-failures (partially fixed in Sprints 2/4) can strand records forever.
- **Global exception handling swallows errors silently** on the offline/SQLite build (`bootstrap/app.php`): unhandled exceptions during sync-triggering requests are converted into silent redirects with no log and no user-visible error, making sync failures nearly impossible to diagnose or report.
- **Dashboard "synced" counter reads the wrong status value**, always showing zero synced items regardless of actual queue state — this hides the real health of the sync system from the user, making Known Issue #1 (permanent orphaning) invisible until data loss is already noticed.
- No confirmed database-transaction boundary around multi-step sync operations — a crash mid-sync could leave local state partially applied (needs explicit audit, not just assumed).

### Expected Result

- A sync queue item that fails 5 times is surfaced to the user with an explicit "needs attention" state and a manual retry action — never silently dropped from all future sync attempts.
- Errors during sync (network, server, database) are always logged with enough detail to diagnose, and never presented as a silent redirect.
- The synced-item counter on the dashboard accurately reflects real queue state.
- Sync operations are wrapped in transactions where multi-step local writes occur, so a crash mid-sync leaves either the old state or the new state, never a partial mix.
- Manual Sync is confirmed idempotent: running it repeatedly with no underlying changes produces zero net effect.

### Files Expected To Change

- `app/Domains/Sync/Services/SyncQueueService.php`
- `bootstrap/app.php` (exception handling for the sqlite/offline build path only — do not touch the online/web exception path unless a matching bug is found there)
- `routes/web.php` (only the dashboard "synced" counter query)
- `app/Services/SyncEngineService.php`
- `app/Services/ManualSyncService.php`
- `app/Services/Sync/ConflictResolverService.php` (only if the transaction/idempotency audit finds a concrete gap — do not preemptively rewrite conflict logic)

### Files That Must NOT Change

- Any lifecycle-specific sync service already hardened in Sprints 1–4 (`PatientSyncService.php` scope, `NoteSyncService.php`, `VisitSyncService.php`, `FileSyncService.php`) — this sprint only touches the shared engine/queue layer around them, not their internal per-entity logic.
- Upload/chunk pipeline internals — already handled in Sprint 3.
- `app/Http/Controllers/AuthController.php` — the auth-bypass issue is a separate blocking prerequisite (see Known Issues), not part of this sprint's scope, to avoid conflating security fixes with reliability fixes in one review.

### Regression Risks

- Changing the retry-exhaustion behavior (from "silently drop" to "surface for manual retry") changes user-visible UI — needs a corresponding minimal UI affordance, coordinate scope with whoever owns the dashboard view.
- Tightening exception visibility in `bootstrap/app.php` must not reintroduce raw stack traces or sensitive data to end users in production — visible-but-safe error messaging only.
- Adding transactions around existing multi-step sync writes must not introduce lock contention on SQLite (single-writer) that stalls the UI thread during a large sync.

### Acceptance Criteria

- [ ] A queue item that reaches its retry limit is visible to the user with a manual retry option, and can be successfully retried.
- [ ] Simulated exceptions during sync (network error, malformed server response, local DB error) are logged with actionable detail and do not silently redirect the user with no explanation.
- [ ] Dashboard synced-item count matches the actual count of `SyncQueue` rows in the "synced"/completed state.
- [ ] Killing the app mid-sync (simulated) leaves the local DB in a consistent state (either pre-sync or post-sync per record, never partially written).
- [ ] Running Manual Sync twice in a row with no changes produces zero additional queue items, zero duplicate records, zero extra API calls beyond the expected "check for changes" calls.

### Manual Test Checklist

1. Force 5+ consecutive failures on a single queue item (e.g., point the API at an invalid endpoint temporarily) → confirm the item becomes visibly "needs attention" and offer a retry → fix the endpoint → retry → confirm success.
2. Trigger a deliberate server-side error during sync → confirm the error is logged with detail and the user sees a clear (not silently-redirected) error state.
3. Compare the dashboard's synced count against a manual `SyncQueue` table count after a full sync — confirm they match.
4. Force-kill the app mid-sync repeatedly (multiple runs) → confirm no partially-corrupted local records after reopening and re-syncing.
5. Run Manual Sync twice back-to-back with no changes → confirm no new queue items or duplicate records.

### Sprint Status

- [ ] Not Started
- [ ] In Progress
- [ ] Blocked
- [ ] Completed

---

## Sprint 6 — Performance & Production Hardening

**Goal:** Only after every lifecycle and the sync engine itself is correct, optimize for scale and clean up known cruft. **Do not optimize before correctness — this sprint must not start before Sprint 5 is Completed.**

### Current Problems

- No batch/`upsert` usage confirmed in the download-sync paths — likely doing row-by-row writes, which will not scale to large clinics.
- HTTP client retry policy (`RemoteApiService`) applies a blanket retry to all requests, including ones where retrying is actively harmful (e.g., `/chunk/complete` after a slow-but-successful merge — already flagged as a Sprint 3 issue, but the general retry policy design belongs here).
- A dead, unused duplicate repository layer exists (`app/Services/Mobile/PatientRepository.php`, `VisitRepository.php`, `NoteRepository.php`) with identical class names to the real, wired-in repositories in `app/Repositories/*`, but with zero error handling and zero offline-fallback logic. Not currently causing bugs (never imported), but a live landmine for a future accidental import.
- No centralized logging/metrics around sync success/failure rates — hard to know in production how often devices are failing to converge.
- `AuthController::login`'s broad `catch (Throwable)` around remote token acquisition only logs a warning and lets the user proceed as if fully logged in, deferring an auth failure into a more confusing later error.

### Expected Result

- Bulk sync operations (large patient/file lists) use batch/`upsert` writes instead of row-by-row loops, without changing observable behavior.
- HTTP retry policy is deliberately scoped per-endpoint (retry-safe idempotent calls only), not blanket-applied.
- The dead duplicate repository classes are removed entirely (they are unused — confirmed by earlier audit).
- Basic sync success/failure metrics are logged in a structured, queryable way.
- Memory usage during large sync/upload operations stays bounded (streaming, not full-buffer loads) — audit and fix any remaining full-file loads outside what Sprint 3 already covered.

### Files Expected To Change

- `app/Services/Sync/DownloadSyncService.php` (batch/upsert conversion only — no logic changes beyond that, coordinate with whatever state Sprints 1/2/4 left it in)
- `app/Services/Mobile/RemoteApiService.php` (retry policy scoping)
- `app/Services/Mobile/PatientRepository.php`, `VisitRepository.php`, `NoteRepository.php` — **delete** (confirmed dead code)
- `app/Http/Controllers/AuthController.php` (login error surfacing only)
- Logging configuration / a new lightweight metrics touchpoint in `SyncEngineService.php` or `ManualSyncService.php`

### Files That Must NOT Change

- Any per-entity sync service logic already correct from Sprints 1–4 — this sprint changes *how* writes happen (batching), not *what* they do.
- `bootstrap/app.php` exception-visibility logic — already fixed in Sprint 5, do not reopen without a found regression.

### Regression Risks

- Batch/upsert conversion can silently change conflict-resolution behavior if the upsert's "which value wins on conflict" semantics differ from the existing row-by-row logic — must be verified against Sprint 5's conflict-resolution guarantees, not assumed equivalent.
- Deleting the dead repository classes must be preceded by a repo-wide grep confirming zero references (including in tests, service providers, and Vue/JS-side type hints if any exist) — do not delete on assumption alone, re-verify at the time of this sprint.
- Scoping the retry policy per-endpoint could accidentally remove retries from an endpoint that legitimately needs them (e.g., transient network blips) — classify endpoints deliberately (idempotent-safe-to-retry vs not), don't just disable retries broadly.

### Acceptance Criteria

- [ ] Full download-sync of a large test dataset (e.g., 1000+ patients) completes measurably faster than the row-by-row baseline, with identical resulting data.
- [ ] Retry policy is documented per-endpoint (which retry, which don't, and why).
- [ ] Dead repository classes are removed; app still builds/runs with no broken references.
- [ ] Sync success/failure events are visible in logs in a structured, greppable format.
- [ ] No full-file memory loads remain in any sync/upload code path (confirmed via code audit, not just the files Sprint 3 touched).
- [ ] Login failures during token acquisition are surfaced to the user immediately, not deferred to a later, more confusing failure.

### Manual Test Checklist

1. Run a full sync against a large seeded dataset before and after the batch/upsert change — compare timing and confirm identical resulting record counts/content.
2. Grep the entire codebase for any reference to the three dead repository classes before deleting them; confirm zero hits outside their own files.
3. Deliberately break remote login (wrong token) and confirm the user sees an immediate, clear error instead of a false "logged in" state.
4. Review logs after a full sync run and confirm success/failure counts are extractable without reading raw request logs.

### Sprint Status

- [ ] Not Started
- [ ] In Progress
- [ ] Blocked
- [ ] Completed

---

## Regression Checklist

**Run this full checklist after every sprint, in addition to that sprint's own acceptance criteria.** A sprint is not "Completed" until both its own acceptance criteria and this shared checklist pass.

- [ ] Create patient on website
- [ ] Sync
- [ ] Patient appears on mobile
- [ ] Create patient offline on mobile
- [ ] Manual Sync
- [ ] Patient appears on website
- [ ] Delete patient (either side) → propagates correctly, does not resurrect on next sync
- [ ] Delete notes (either side) → propagates correctly, does not resurrect
- [ ] Delete files (either side) → propagates correctly, does not resurrect
- [ ] Upload images → succeed, checksum verified, visible on both sides
- [ ] Upload videos → succeed (including resumed-after-drop case), checksum verified, visible on both sides
- [ ] Restart app (normal close/reopen) mid-workflow → no data loss
- [ ] Force close app (kill, not graceful close) mid-workflow → no data loss
- [ ] Reinstall app → log in → download everything again → matches website exactly
- [ ] No duplicate records anywhere (patients, notes, visits, files) after repeated syncs
- [ ] No orphan files on server disk after failed/abandoned uploads
- [ ] Categories remain correctly mapped to their files after sync
- [ ] UUID mapping preserved (no local-ID leakage to remote calls, anywhere)
- [ ] Sync queue is empty (or only contains legitimately-pending items) after a successful full sync

---

## Known Issues

Every issue below was discovered during the pre-planning audits of the current codebase. Each is tagged with the sprint that owns fixing it. Do not fix an issue outside its owning sprint without updating this table first, to keep sprint scope honest.

### Critical

- [ ] **Auth bypass on offline/native builds.** `AuthController::showLogin` auto-logs in as the first row in the local `users` table with no password check when `database.default === 'sqlite'`. Affected: `app/Http/Controllers/AuthController.php`. Why it matters: any person with physical access to the device gets full access to patient records with no credential check; also interacts with the exception-swallowing issue below (a crash can bounce a user through this auto-login path). **Blocking prerequisite — must be fixed before Sprint 1's Reinstall Recovery work is considered done**, tracked here since it doesn't fit the lifecycle sprint shape.
- [ ] **Global exception swallowing on offline builds.** `bootstrap/app.php` converts unhandled exceptions on non-API/non-JSON requests into silent redirects with no logging when running on sqlite with debug off. Affected: `bootstrap/app.php`. Why it matters: makes every other bug in this document harder to detect and diagnose in the field. **Owned by Sprint 5.**
- [ ] **Timestamp text-comparison bug breaks same-day incremental sync.** `DownloadSyncService` compares differently-formatted timestamp strings lexicographically, causing same-day changes to be excluded from incremental fetch. Affected: `app/Services/Sync/DownloadSyncService.php`. Why it matters: this is a primary source of "notes/visits just don't show up" complaints. **Owned by Sprint 1 (patient-level fix), consumed by Sprints 2 and 4.**
- [ ] **Note/Visit incremental sync gated on the wrong signal** (parent patient's `updated_at` used as a proxy for "child records changed"). Affected: `DownloadSyncService.php`, cascades into `NoteSyncService.php` / `VisitSyncService.php` download logic. Why it matters: notes/visits added elsewhere can silently never reach a device. **Owned by Sprint 2 (notes), replicated fix in Sprint 4 (visits).**
- [ ] **Checksum validation bypassed for direct-write (video) uploads.** Affected: `app/Services/Upload/ChunkMergeService.php`. Why it matters: corrupted/truncated video uploads can be accepted as valid, silently. **Owned by Sprint 3.**

### High

- [ ] **Sync queue items permanently orphaned after 5 failed attempts**, with no recovery path. Affected: `app/Domains/Sync/Services/SyncQueueService.php`. Why it matters: outages or false-failures (see dependency-ordering issue below) can strand records forever with no user-visible recourse. **Owned by Sprint 5** (dependency-ordering false-failures specifically owned by Sprints 2 and 4).
- [ ] **Visit update/delete can target the wrong remote resource** when a visit's own create hasn't synced yet, sending a local integer ID instead of a UUID. Affected: `app/Services/Sync/VisitSyncService.php`. Why it matters: risk of mutating an unrelated record on the server. **Owned by Sprint 4.**
- [ ] **Resumable video upload does not actually resume** — every retry starts a brand-new session from scratch. Affected: `app/Services/Upload/UploadSessionService.php`, `app/Services/Sync/FileSyncService.php`. Why it matters: repeated full re-uploads of large files on flaky connections, wasting bandwidth and time, and increasing failure likelihood. **Owned by Sprint 3.**
- [ ] **Orphaned partial files accumulate on server disk** for direct-write (video) uploads — cleanup only handles the legacy chunk-directory path. Affected: `app/Services/Upload/UploadCleanupService.php`. Why it matters: silent disk exhaustion over time. **Owned by Sprint 3.**
- [ ] **Dead duplicate repository classes** (`app/Services/Mobile/PatientRepository.php`, `VisitRepository.php`, `NoteRepository.php`) with no error handling, sharing class names with the real wired-in repositories. Why it matters: not currently active, but a landmine for a future accidental import that reintroduces an unguarded always-online code path. **Owned by Sprint 6 (removal).**

### Medium

- [ ] **A successful `/chunk/complete` can be reported as a failure** due to blanket HTTP retry policy re-sending the request after a slow-but-successful merge. Affected: `app/Services/Mobile/RemoteApiService.php`, `app/Services/Upload/ChunkMergeService.php`. Why it matters: false failures trigger wasted re-uploads. **Owned by Sprint 3** (specific fix), general retry-policy scoping **owned by Sprint 6**.
- [ ] **File handle leak on chunk upload failure** — handle not closed if an exception occurs mid-loop. Affected: `app/Services/Sync/FileSyncService.php`. Why it matters: descriptor exhaustion under repeated failures on poor connections. **Owned by Sprint 3.**
- [ ] **Race window in patient download cutoff** — cutoff timestamp captured after, not before, a paginated fetch loop. Affected: `app/Services/Sync/DownloadSyncService.php`. Why it matters: a patient created/modified mid-pagination can be skipped on the next sync. **Owned by Sprint 1.**
- [ ] **Dashboard "synced" counter reads the wrong status value**, always showing zero. Affected: `routes/web.php`. Why it matters: hides real sync health, masking the retry-exhaustion issue until data loss is already noticed. **Owned by Sprint 5.**
- [ ] **CSRF token-mismatch redirect loop has no cap.** Affected: `bootstrap/app.php`. Why it matters: a corrupted session can loop indefinitely with no diagnostic. **Owned by Sprint 5.**
- [ ] **Broad `catch (Throwable)` in `AuthController::login`** lets the user proceed as "logged in" even when remote token acquisition failed, deferring the real error to a more confusing later failure. Affected: `app/Http/Controllers/AuthController.php`. Why it matters: makes auth failures harder to diagnose. **Owned by Sprint 6.**

---

## Sprint Progress Dashboard

| Sprint | Status | Started | Completed | Notes |
|---|---|---|---|---|
| Sprint 1 — Patient Lifecycle | Not Started | | | |
| Sprint 2 — Notes Lifecycle | Not Started | | | |
| Sprint 3 — Files & Attachments Lifecycle | Not Started | | | |
| Sprint 4 — Visits Lifecycle | Not Started | | | |
| Sprint 5 — Reliability & Synchronization Engine | Not Started | | | |
| Sprint 6 — Performance & Production Hardening | Not Started | | | |

---

## Session Handoff

**Read this section first if you are a new Claude session picking up this project.**

1. **Find the current sprint.** Check the Sprint Progress Dashboard above for the first sprint whose status is not "Completed." That is the active sprint (or the next one to start, if the current one is "Completed").
2. **Never skip unfinished acceptance tests.** If a sprint's acceptance criteria checkboxes are not all checked, that sprint is not done — do not start the next sprint, even if it seems like unrelated work. Cross-sprint dependencies (documented per-sprint above) are real: skipping ahead will produce rework.
3. **Never start the next sprint until the current sprint is fully completed** — meaning both its own Acceptance Criteria *and* the shared Regression Checklist pass, and its Sprint Status is manually updated to "Completed" in both its own section and the dashboard table.
4. **Always update this document after finishing work.** At minimum: check off completed acceptance criteria and regression checklist items, update the Sprint Status section (both inline and in the dashboard), add a dated note in the dashboard's Notes column summarizing what changed and any deviations from plan, and update the Known Issues table (check off resolved issues, but leave them in the list with a `[x]` rather than deleting — this preserves audit history).
5. **If you discover a new bug while working a sprint that is out of that sprint's scope**, add it to Known Issues under the correct severity with a sprint assignment, but do not fix it outside its owning sprint unless it is a blocking prerequisite for the current work (state why, explicitly, in your commit message and in this document).
6. **Use this file as the project's single source of truth.** If you find this document disagrees with the actual code (e.g., a listed problem was already fixed by someone outside this process), update the document to match reality and note the discrepancy — do not silently trust either the code or the document without reconciling them.
7. **Respect the Architecture Principles section above in every change**, regardless of which sprint you're working in.
