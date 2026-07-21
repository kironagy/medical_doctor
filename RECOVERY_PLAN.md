# Recovery Plan — Medical Plus Production Readiness

## Architecture Overview

```
┌─────────────────────────────────────────────────────────────────┐
│                        WEB BROWSER                              │
│  Inertia + Vue 3 SPA ──► Laravel Web Controllers ──► MySQL     │
│  Session Auth (cookie)                                           │
└─────────────────────────────────────────────────────────────────┘

┌─────────────────────────────────────────────────────────────────┐
│                    NATIVEPHP MOBILE APP                         │
│  ┌──────────────┐     ┌──────────────────┐     ┌────────────┐  │
│  │ Vue Frontend │────►│ Local PHP Server │────►│ Remote API │  │
│  │ (WebView)    │◄────│ (127.0.0.1:XXXX) │◄────│ (prof-     │  │
│  │              │     │ Hybrid Repos     │     │ hosam-     │  │
│  │              │     │ SQLite Cache     │     │ fekry.     │  │
│  │              │     │ Sync Queue       │     │ online)    │  │
│  └──────────────┘     └──────────────────┘     └────────────┘  │
└─────────────────────────────────────────────────────────────────┘
```

**Key principle:** When online, the app MUST call the remote API directly. SQLite is cache + offline fallback only. When offline, operations are queued and pushed via sync.

---

## Consolidated Issue Tracker

All issues from both reports, deduplicated and grouped by domain:

### SEC — Security (Critical)
| ID | Issue | Report Ref | Priority |
|----|-------|-----------|----------|
| SEC-1 | APP_DEBUG=true on production server | C1, H8 | **IMMEDIATE** |
| SEC-2 | Debug endpoint leaks internal state | C7 | IMMEDIATE |
| SEC-3 | Debug endpoint wrong namespace (ClassNotFoundException) | H8 (related) | MEDIUM |
| SEC-4 | Token stored in plaintext in SQLite | H6 | HIGH |
| SEC-5 | No rate limiting on API endpoints | H3 | HIGH |
| SEC-6 | No input sanitization on file names | H4 | HIGH |

### SYN — Sync Architecture (Critical)
| ID | Issue | Report Ref | Priority |
|----|-------|-----------|----------|
| SYN-1 | Sync routes use `auth` (web session) instead of `auth:sanctum` → always 401 | RC1 | **CRITICAL** |
| SYN-2 | Dual sync systems (SyncQueueService vs PendingOperationsService) → duplicates | C2 | CRITICAL |
| SYN-3 | SyncManager namespace resolution error → fatal crash | RC5 | CRITICAL |
| SYN-4 | Sync semaphore is in-memory → lost on crash → dead lock | M2 | HIGH |
| SYN-5 | No conflict resolution in sync pull → silent data overwrite | C5 | CRITICAL |
| SYN-6 | No dependency ordering in push → child pushes before parent → 404 | C6 | CRITICAL |
| SYN-7 | Legacy PendingOperation records never cleaned | M3 | MEDIUM |

### AUTH — Authentication
| ID | Issue | Report Ref | Priority |
|----|-------|-----------|----------|
| AUTH-1 | Token expiry + login throttle (10/min) creates 1-min blackout | RC2 | **CRITICAL** |
| AUTH-2 | `setOnline(false)` called on 401 → confuses auth failure with network failure | RC3 | CRITICAL |
| AUTH-3 | refreshToken() has no backoff → hammers throttle limit | RC2.3 | HIGH |
| AUTH-4 | No health endpoint for NetworkStatusService (relies on 404) | RC3.4 | MEDIUM |
| AUTH-5 | Credentials encrypted with APP_KEY → lost on key rotation | RC2.6 | HIGH |

### OFF — Offline & Data Integrity
| ID | Issue | Report Ref | Priority |
|----|-------|-----------|----------|
| OFF-1 | Remote UUID reassignment orphans local references | C3 | **CRITICAL** |
| OFF-2 | DoctorIsolationScope disabled on NativePHP | C4 | CRITICAL |
| OFF-3 | No UUID on local User model (breaks isolation + sync) | H7 | HIGH |
| OFF-4 | N+1 sync pull (3 API calls per patient) | H5 | HIGH |
| OFF-5 | Optimistic UI not rolled back on sync failure | (report) | MEDIUM |

### BIZ — Business Logic
| ID | Issue | Report Ref | Priority |
|----|-------|-----------|----------|
| BIZ-1 | NoteController auth bypass (web API) | H1 | **HIGH** |
| BIZ-2 | File forceDelete bypasses soft delete | H2 | HIGH |
| BIZ-3 | No patient_id validation on upload start | M5 | HIGH |
| BIZ-4 | Patient code collision risk | M1 | MEDIUM |
| BIZ-5 | No max length on note content | L2 | LOW |
| BIZ-6 | Hardcoded remote API URLs | L1 | LOW |

### API — API Completeness
| ID | Issue | Report Ref | Priority |
|----|-------|-----------|----------|
| API-1 | Categories API not token-accessible | M4 | **HIGH** |
| API-2 | User model UUID always null | H7 (same) | HIGH |

---

## Dependency Graph

```
SEC-1 (APP_DEBUG=false)
  └── No dependencies — can fix immediately

SEC-2 (Debug endpoint leak)
  └── Depends on: nothing

SYN-1 (Sync routes auth → sanctum)
  └── Depends on: AUTH-4 (health endpoint) for NetworkStatusService
  └── Blocked by: nothing — can switch to sanctum immediately

SYN-2 (Dual sync → single)
  └── Depends on: SYN-7 (clean up legacy first)
  └── Depends on: SYN-3 (fix namespace first so tests pass)

SYN-3 (Fix SyncManager namespace)
  └── Depends on: nothing (1-line import fix)

SYN-4 (DB-backed sync lock)
  └── Depends on: SYN-3 (same file being modified)

SYN-5 (Conflict resolution)
  └── Depends on: SYN-2 (single sync system to inject)

SYN-6 (Dependency ordering in push)
  └── Depends on: SYN-2 (single sync system to order)

AUTH-1 (Token expiry + throttle)
  └── Depends on: AUTH-2, AUTH-3 (fix before improving throttle)

AUTH-2 (Don't setOnline on 401)
  └── Depends on: nothing — fix Hybrid repos

AUTH-3 (Exponential backoff on refresh)
  └── Depends on: AUTH-1 (must understand throttle to fix it)

AUTH-4 (Health endpoint)
  └── Depends on: nothing

OFF-1 (UUID reassignment)
  └── Depends on: SYN-2 (single sync system to modify)

OFF-2 (DoctorIsolationScope)
  └── Depends on: AUTH-2 (don't mix 401 with offline)

BIZ-1 through BIZ-6
  └── Independent fixes — can be done in any order
```

---

## Execution Order

### Phase 1: Production Security (IMMEDIATE — deploy to server)
| Step | Issue | Action | Verification |
|------|-------|--------|-------------|
| 1.1 | SEC-1 | Set `APP_DEBUG=false` on production `.env` | `curl` nonexistent patient → JSON error, no stack trace |
| 1.2 | SEC-2 | Add auth middleware to `/debug-state` route | `curl /debug-state` → 401 |
| 1.3 | SEC-3 | Fix debug endpoint namespace (class name) | `curl /debug-state` → works without crash |
| 1.4 | SEC-5 | Add throttle middleware to API routes | `ab -n 20` → 429 after 10 |

### Phase 2: Fix Sync Architecture (make sync actually work)
| Step | Issue | Action | Verification |
|------|-------|--------|-------------|
| 2.1 | SYN-1 | Change sync routes from `auth` to `auth:sanctum` in `routes/api.php` | `curl POST /api/native/sync` with token → 200 |
| 2.2 | SYN-3 | Add `use App\Services\Sync\SyncManager` to `FullSyncService.php` | PHPUnit passes |
| 2.3 | SYN-7 | Clean up: migrate legacy `PendingOperation` records to `SyncQueueItem` or delete | DB has no pending_operations entries |
| 2.4 | SYN-2 | Remove `PendingOperationsService` — consolidate to `SyncQueueService` alone | All tests pass, no dead code |
| 2.5 | SYN-4 | Replace in-memory sync lock with database-backed lock (sync_states table + expiry) | Concurrent syncs are serialized |
| 2.6 | SYN-6 | Add dependency ordering to `SyncQueueService::processPendingOperations()` | Patient → files → notes → visits pushed in order |
| 2.7 | SYN-5 | Inject and use `ConflictResolver` in sync pull for all child resources | Local edits survive sync pull |

### Phase 3: Fix Authentication
| Step | Issue | Action | Verification |
|------|-------|--------|-------------|
| 3.1 | AUTH-2 | Hybrid repos: don't `setOnline(false)` on 401; only on connection/timeout errors | 401 → re-authenticate, not queue |
| 3.2 | AUTH-3 | Add exponential backoff (1s, 2s, 4s) to `refreshToken()` calls | Login endpoint not hammered |
| 3.3 | AUTH-4 | Add `GET /api/v1/mobile/ping` health endpoint that returns 200 without auth | NetworkStatusService uses ping endpoint |
| 3.4 | AUTH-1 | Increase login throttle from 10/min to 60/min; separate refresh throttle | Login throttle not exhausted by refresh |
| 3.5 | AUTH-5 | Store credentials with stable key; add re-login prompt when refresh fails | Refresh works across key rotation |

### Phase 4: Offline Architecture
| Step | Issue | Action | Verification |
|------|-------|--------|-------------|
| 4.1 | OFF-1 | Don't reassign UUIDs on sync; send local UUID with create requests | File created offline → same UUID on server |
| 4.2 | OFF-2 | Fix DoctorIsolationScope: filter by `primary_doctor_id` or `patient_shares.doctor_id` | Doctor A sees only own + shared patients |
| 4.3 | OFF-3 | Add UUID to User model; populate from remote API response on login | `mirrorRemoteUser()` stores UUID |

### Phase 5: Business Logic + Security
| Step | Issue | Action | Verification |
|------|-------|--------|-------------|
| 5.1 | BIZ-1 | Fix NoteController: always authorize against patient (remove auth-bypass path) | Note edit without access → 403 |
| 5.2 | BIZ-2 | Change `forceDelete()` to `delete()` in both file controllers | File deletion → soft delete |
| 5.3 | BIZ-3 | Validate `patient_id` resolves to UUID and user has access in UploadsController | Unauthorized upload → 403 |
| 5.4 | BIZ-4 | Add unique index on `code`; add collision check loop | Duplicate codes impossible |
| 5.5 | BIZ-5 | Add `max:65535` to note content validation | >65535 chars → 422 |
| 5.6 | SEC-4 | Use encrypted token storage by default on production builds | Token in DB is encrypted |
| 5.7 | SEC-6 | Add filename sanitization in FileController | Path traversal characters stripped |

### Phase 6: API Completeness
| Step | Issue | Action | Verification |
|------|-------|--------|-------------|
| 6.1 | API-1 | Move/add category routes to `/api/v1/mobile/categories` with Sanctum auth | Mobile can CRUD categories |
| 6.2 | BIZ-6 | Consolidate hardcoded URLs to `config('app.mobile_api_url')` | Single source of truth for URL |

### Phase 7: Performance
| Step | Issue | Action | Verification |
|------|-------|--------|-------------|
| 7.1 | OFF-4 | Add batch sync endpoints (`GET /api/v1/mobile/sync/batch`) | Single API call for all resources |

### Phase 8: Verification
| Step | Action | Evidence |
|------|--------|----------|
| 8.1 | Run PHPUnit — all tests pass | Test output |
| 8.2 | Run each workflow end-to-end on mobile | Screenshots, API logs |
| 8.3 | Verify sync: create offline → come online → data appears | Server DB has new records |
| 8.4 | Verify auth: token expires → refresh → operations continue | Logs show 200 after refresh |
| 8.5 | Verify offline: airplane mode → create → data visible locally | UI shows data |
| 8.6 | Build APK → install → test on device | APK builds |
| 8.7 | Re-run audit methodology from scratch | New audit reports |

---

## Risk Analysis

| Risk | Likelihood | Impact | Mitigation |
|------|-----------|--------|------------|
| Sync route change (auth→sanctum) breaks existing web sync | Low | Medium | Keep backward compat: check both auth guards |
| Consolidating sync systems drops pending items | Medium | High | Migrate all pending items before deploy |
| DB-backed lock deadlock under extreme load | Low | Medium | Lock has TTL expiry (5 min) |
| App_KEY rotation breaks credential decryption | Low | High | Store credentials without encryption dependency |
| APK build fails after changes | Medium | High | Run `php native build android` early to validate |

## Rollback Strategy

Every fix is independently revertible via git. Phases are ordered so that:
- Phase 1: Revert `.env` change + route change
- Phase 2-7: Revert specific commits
- If build fails: old APK still works with old sync system

## Testing Strategy

1. **PHPUnit** after every phase
2. **API endpoint tests** via curl against production/staging
3. **Mobile workflow** on actual Android device after APK build
4. **Sync verification**: create data offline → come online → verify on server
5. **Regression**: run every scenario from both reports

---

## Expected Outcome

After all phases:
1. **Sync actually works**: operations queue when offline, push when online
2. **No 401 storms**: token refresh has backoff, separate throttle
3. **Auth failures don't disable networking**: 401 → re-authenticate, not queue
4. **Conflict resolution prevents data loss**: Last-write-win with timestamp comparison
5. **Doctor isolation works**: doctors see only their patients
6. **No silent data loss**: UUIDs are stable, soft delete works
7. **Performance**: N+1 queries fixed in sync
8. **Security**: APP_DEBUG=false, rate limited, input sanitized
9. **API complete**: categories accessible via token
