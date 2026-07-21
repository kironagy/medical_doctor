# Network Request Investigation Report

## Executive Summary

The mobile app **does send API requests** to the production server, and those requests **do reach the server's Nginx**. However, they frequently return **401 Unauthorized** due to expired Sanctum tokens and session authentication failures. The app catches these errors, silently queues operations in local SQLite, and returns fake success responses to the UI. The sync mechanism (which should push queued operations) **never works** because it uses web session auth on API routes that have no session middleware.

---

## Timeline of Execution (from server logs)

```
21:16:51  GET  /api/v1/mobile              → 404  (NetworkStatusService ping)
21:16:52  POST /api/v1/login                → 200  (Login – token issued)
21:17:05  GET  /api/v1/mobile/doctors       → 200  (Token works)
21:17:06  GET  /api/v1/mobile/patients      → 200  (Token works)
21:17:07  GET  /api/v1/mobile/patients/*    → 200  (Token works)
...many GET requests succeed...
21:20:16  POST /api/v1/mobile/patients      → 201  (Patient created – token works)
21:21:48  GET  /api/v1/mobile/patients      → 401  ⚠️ TOKEN EXPIRED
21:21:49  POST /api/v1/mobile/patients      → 401  ⚠️
21:21:49  GET  /api/v1/mobile/patients/*    → 401  ⚠️
21:21:50  PUT  /api/v1/mobile/patients/*    → 401  ⚠️
21:21:50  DELETE /api/v1/mobile/patients/*  → 401  ⚠️
...82-second gap with all 401s...
21:23:10  POST /api/v1/mobile/patients      → 201  (Token refreshed – works again)
21:24:16  POST /api/v1/mobile/patients      → 201  (Works)
```

### Sync endpoint (always fails):
```
21:20:22  POST /api/native/sync  → 401  (always, every time)
21:25:22  POST /api/native/sync  → 401  (always, every time)
```

---

## Complete Request Flow

### CRUD Operations (e.g., Create Patient)

```
User taps "Create Patient"
  │
  ▼
Vue Component (AddPatientModal.vue)
  │ axios.post('/api/v1/workspace/patients', formData)
  ▼
LOCAL NativePHP Server (127.0.0.1)
  │ routes/web.php → WorkspaceController::storePatient()
  ▼
WorkspaceController calls $this->patientRepo->create($data)
  │ Interface bound to HybridPatientRepository (NATIVEPHP_RUNNING=true)
  ▼
HybridPatientRepository::create():
  1. Saves to local SQLite (always succeeds)
  2. Checks NetworkStatusService::isOnline()
  3. → TRUE → calls ApiPatientRepository::create($data)
     │ MakesApiRequests trait:
     │   GET token from ApiService (session → DB)
     │   Http::withToken($token)->post('https://.../api/v1/mobile/patients')
     ▼
PRODUCTION SERVER (prof-hosam-fekry.online)
  │ POST /api/v1/mobile/patients
  │ Middleware: auth:sanctum
  │   → Token valid?   → 200/201 → response returned to Hybrid repo
  │   → Token invalid? → 401     → MakesApiRequests catches → refreshToken()
  │     → Refresh OK?    → retry request with new token
  │     → Refresh FAIL?  → throws AuthenticationException
  ▼
HybridPatientRepository catches exception:
  → NetworkStatusService::setOnline(false)
  → SyncQueueService::enqueueOperation('Patient', 'create', ...)
  → Returns local SQLite data (fake success)
  ▼
UI shows "Patient created successfully" (from local data)
  ⚠️ BUT the patient was NEVER saved to the production server
```

### Sync Operation (Push Queued Items)

```
Frontend (app.js):
  fetch('/api/native/sync/background', { method: 'POST' })
  │ Called on: window.online event, every 120s, visibilitychange
  ▼
LOCAL NativePHP Server (127.0.0.1)
  │ routes/api.php → Route::middleware('auth') → NativeSyncController
  │
  ▼
⚠️ API MIDDLEWARE GROUP (no StartSession middleware):
  │ Session cookie in request is NEVER read
  │ auth middleware checks web guard → no session → 401
  ▼
Sync request FAILS before reaching NativeSyncController
  ⚠️ Queued operations remain queued FOREVER
```

---

## Root Cause #1: Session Auth on API Routes (Sync Broken)

**File:** `routes/api.php:103`
```
Route::middleware('auth')->group(function () {
    Route::post('/native/sync', [NativeSyncController::class, 'sync']);
    Route::post('/native/sync/background', ...);
    Route::get('/native/sync/status', ...);
});
```

**File:** `bootstrap/app.php:36-38`
```
$middleware->api(append: [
    \App\Http\Middleware\SyncMiddleware::class,
]);
```

**Problem:** The `api` middleware group does NOT include `StartSession`, `EncryptCookies`, or `AddQueuedCookiesToResponse`. The `auth` middleware (default `web` guard) requires a session to authenticate. Without session middleware, the session cookie is never read, `Auth::check()` always returns false, and the response is **always 401**.

**Evidence:**
```
POST /api/native/sync  → 401  (every single time, never succeeds)
```

**Why it was done this way:** The comment in `routes/api.php:100-101` says the routes are in `api.php` to avoid CSRF verification. But the consequence — loss of session middleware — was not accounted for.

**Impact:** Operations queued locally by Hybrid repositories are NEVER pushed to the server. The sync queue fills up permanently.

---

## Root Cause #2: Token Expiry with Throttle-Induced Refresh Failure

**Files:**
- `app/Repositories/Api/Traits/MakesApiRequests.php:48-110`
- `app/Services/Mobile/ApiService.php:359-387`
- `routes/api.php:22` — `Route::post('/login', ...)->middleware('throttle:10,1')`

**Problem Chain:**

1. Sanctum API token expires (or becomes invalid)
2. MakesApiRequests trait receives 401 on an API call
3. Calls `ApiService::refreshToken()` which calls `POST /api/v1/login`
4. Multiple concurrent requests all trigger `refreshToken()` simultaneously
5. Login endpoint is throttled to **10 requests per minute** (`throttle:10,1`)
6. After ~10 refresh attempts, login returns **429 Too Many Requests**
7. `refreshToken()` fails because 429 response has no `token` field
8. `MakesApiRequests` throws `AuthenticationException`
9. Hybrid repository catches exception → sets `isOnline(false)` → queues operation
10. **For the next ~1 minute**, all API calls are queued instead of sent
11. After throttle resets, the next request succeeds → token refreshed → operations resume

**Evidence in logs:**
```
21:21:48  GET  /api/v1/mobile/patients           → 401
21:21:49  POST /api/v1/mobile/patients           → 401
21:21:49  GET  /api/v1/mobile/patients/{uuid}    → 401
21:21:50  PUT  /api/v1/mobile/patients/{uuid}    → 401
21:21:50  DELETE /api/v1/mobile/patients/{uuid}  → 401
...
21:23:10  POST /api/v1/mobile/patients           → 201  (82-second gap = throttle window)
```

**Impact:** Every time a token expires, there's a ~1 minute window where ALL write operations are silently queued and never sent. The UI shows success, but data is lost on server restart.

---

## Root Cause #3: NetworkStatusService Cached False Offline

**File:** `app/Services/NetworkStatusService.php:17-61`

**Problem:** After an API failure (e.g., 401 → exception caught in Hybrid repo), `NetworkStatusService::setOnline(false)` is called. This sets `self::$isOnline = false` for the **current request only**. On the NEXT request, `isOnline()` re-pings the server. But if the server is unreachable during that ping, the result is cached as `false` for **15 seconds** (when offline) or **60 seconds** (when online). During this period, ALL operations are queued.

Additionally, the in-memory static `$isOnline` persists across the entire request lifecycle. If ANY operation fails with a network error, all subsequent operations in the same request are treated as offline.

**Impact compounds Root Cause #2:** After the 401 failure, `setOnline(false)` marks the connection as offline for the rest of the request. The next request re-checks, but if the token refresh is still throttled, the login ping also fails → `isOnline()` returns false and caches it for 15 seconds. Each failure extends the offline window.

---

## Root Cause #4: Wrong Initial Access Log File

**File inspected:** `/var/log/nginx/chemicals.access.log`
**Actual log file:** `/var/log/nginx/prof-hosam-fekry.access.log`

**Problem:** The Nginx site config at `/etc/nginx/sites-available/prof-hosam-fekry.online` writes access logs to `prof-hosam-fekry.access.log`, NOT `chemicals.access.log`. The `chemicals` log file contained only generic bot traffic and did not show API requests. The investigation initially appeared to confirm "no requests reach the server" because the wrong file was being checked.

**Evidence:**
```
server {
    ...
    access_log /var/log/nginx/prof-hosam-fekry.access.log;   ← correct log
    ...
}
```

The correct log (`prof-hosam-fekry.access.log`) has **1,635 lines** containing `/api/v1/mobile` and **81 lines** containing `native/sync` — proving requests DO reach the server.

---

## Root Cause #5: SyncManager Namespace Resolution Error

**File:** `app/Services/FullSyncService.php:49`
```
public static function isSyncInProgress(): bool
{
    return SyncManager::isSyncInProgress();  // Wrong namespace!
}
```

**Problem:** `SyncManager` is in `App\Services\Sync\SyncManager`, but `FullSyncService` is in `App\Services`. Without a `use` statement or fully qualified reference, PHP looks for `App\Services\SyncManager` which doesn't exist. This causes a **fatal error** every time `isSyncInProgress()` is called (from `WorkspaceController::patientData()`).

**Evidence from production.log:**
```
[2026-07-21 19:20:24] production.ERROR: Class "App\Services\SyncManager" not found
```

**Impact:** The workspace page crashes with a 500 error when a patient is selected (`GET /api/v1/workspace/{uuid}`), because `WorkspaceController::patientData()` calls `FullSyncService::isSyncInProgress()` on line 364.

---

## Summary of Failure Chain

```
1. Token expires (Sanctum default expiry)
       │
       ▼
2. API returns 401 on next request
       │
       ├─► MakesApiRequests::executeWithRefresh()
       │      │
       │      ▼
       │   ApiService::refreshToken() → POST /api/v1/login
       │      │                           (throttle:10,1)
       │      ▼
       │   Login throttled after ~10 attempts
       │      → refreshToken() returns false
       │
       ▼
3. AuthenticationException thrown
       │
       ▼
4. HybridRepository catches → setOnline(false)
       │                      → enqueueOperation()
       │                      → return local data (fake success)
       │
       ▼
5. UI shows "success" — data only in local SQLite
       │
       ▼
6. Sync triggered: POST /api/native/sync
       │ routes/api.php :: Route::middleware('auth')
       │ API middleware group has NO StartSession
       ▼
7. auth middleware (web guard) fails → 401
   ⚠️ Sync NEVER succeeds
       │
       ▼
8. Queued operations remain queued FOREVER
   Data is never pushed to production server
```

---

## Files Responsible

| File | Line(s) | Issue |
|------|---------|-------|
| `routes/api.php` | 103 | `middleware('auth')` needs session but API group has no session middleware |
| `bootstrap/app.php` | 36-38 | API middleware group only appends SyncMiddleware, no session support added |
| `app/Services/Mobile/ApiService.php` | 389-430 | `send()` retry loop can exhaust login throttle |
| `app/Repositories/Api/Traits/MakesApiRequests.php` | 48-110 | Token refresh has no throttle-aware backoff |
| `app/Repositories/Hybrid/HybridPatientRepository.php` | (all write methods) | Catches exceptions broadly → `setOnline(false)` → queues without verifying server state |
| `app/Services/NetworkStatusService.php` | 17-61 | In-memory `$isOnline` cache can be corrupted by a single API failure |
| `app/Services/FullSyncService.php` | 49 | Wrong namespace for `SyncManager` → fatal error |
| `app/Http/Controllers/WorkspaceController.php` | 364 | Calls `FullSyncService::isSyncInProgress()` → crashes page |

---

## Recommended Architectural Fixes

### Critical (Sync Broken):
1. **Change sync routes from `auth` to `auth:sanctum`** in `routes/api.php:103`
   - Add a Sanctum token to the sync requests so they authenticate via token instead of session
2. **OR move sync routes to `routes/web.php`** with explicit CSRF exemption in `AppServiceProvider`

### High (Token Expiry Chaos):
3. **Add exponential backoff** to `MakesApiRequests::executeWithRefresh()` to avoid hammering the login endpoint
4. **Increase login throttle** from 10/min to 60/min, or use a separate throttle for refresh attempts
5. **Don't call `setOnline(false)` on 401** — 401 means the user needs to re-authenticate, not that the network is offline
6. **Store credentials with a stable encryption key** or store them plaintext (like the token) to survive APP_KEY changes

### Medium:
7. **Fix `FullSyncService::isSyncInProgress()`** namespace resolution
8. **Add `StartSession` middleware** to the API group if session auth must remain
9. **Add a health endpoint** that doesn't require auth for `NetworkStatusService` to ping (instead of relying on 404 from the prefix)
