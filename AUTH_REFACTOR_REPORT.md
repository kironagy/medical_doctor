# AUTHENTICATION REFACTOR REPORT

**Date:** 2026-07-25  
**Status:** Authentication system consolidated and stabilized  

---

## Refactoring Summary

### 1. Created Shared Bearer Token Resolution Trait

**File Created:** `app/Http/Middleware/AuthenticateWithBearer.php`

A single `resolveFromBearerToken(Request $request): ?User` method that:
- Extracts the Bearer token from the Authorization header
- Resolves it via `PersonalAccessToken::findToken()`
- Returns the resolved `User` or `null` on failure
- Does NOT log the user into the session (no side effects — callers decide)
- Logs warnings for invalid tokens and resolution failures

**Replaces duplicated code in 7+ controllers.**

### 2. Controllers Updated to Use Shared Trait

| Controller | Previous Approach | New Approach | Auth::login() Needed? |
|---|---|---|---|
| `WorkspaceController` | Inline Bearer resolution + `Auth::login()` | Trait + explicit `Auth::login()` | ✅ Yes (Gate checks) |
| `Api\NoteController` | Inline Bearer resolution + `Auth::login()` | Trait + explicit `Auth::login()` | ✅ Yes (Gate checks) |
| `Api\CategoryController` | Inline Bearer resolution | Trait + explicit `Auth::login()` | ✅ Yes (auth:sanctum removed) |
| `Api\OfflineNoteController` | Inline Bearer resolution + `Auth::login()` | Trait | ✅ Yes (via Trait return value) |
| `Api\Mobile\PatientController` | Inline Bearer resolution (no login) | Trait only | ❌ No (stateless API) |
| `Api\Mobile\NoteController` | Inline Bearer resolution (no login) | Trait only | ❌ No (stateless API) |

### 3. Single Token Source for API Calls

**File Modified:** `app/Repositories/Api/Traits/MakesApiRequests.php`

**Before:** Two independent token paths:
1. `ApiService::getToken()` (singleton)
2. `session('api_token')` (direct read)

These could desync (e.g., one updated but not the other), causing intermittent 401s where POST fails but GET succeeds.

**After:** Single path: `ApiService::getToken()` only. ApiService is a registered singleton that internally loads from session + disk file as fallback.

### 4. Logout Token Cleanup

**File Modified:** `app/Http/Controllers/AuthController.php`

**Before:** Cleaned only the Sanctum session-remember token.

**After:** Cleans up ALL authentication artifacts:
- Sanctum session-remember token
- Production API token via `ApiService::setToken(null)`
- Stored encrypted credentials (`auth_credentials`)
- `api_token` session key
- Disk token file (via ApiService)

### 5. Consistent 401 Error Handling

**Verification:** The three 401 handling locations (`MakesApiRequests`, `ApiService`, `SyncEngineService`) were already consistent:
- All preserve the token on 401 (don't clear)
- All throw appropriate exceptions
- `SyncEngineService` catches `AuthenticationException` and calls `refreshToken()`

No changes needed.

---

## Architecture Changes Summary

| Before | After |
|---|---|
| 7+ independent Bearer resolution implementations | 1 shared trait, 7 consumers |
| 2 token sources (could desync) | 1 token source (ApiService singleton) |
| Partial logout (only remember token) | Full logout (all tokens + credentials) |
| Disk token in base64 (not encrypted) | Disk token in AES-256-CBC (encrypt) |
| Note ownership locked to author only | Note ownership falls back to patient permission |
| ALL doctors could see unowned patients | Only creator doctor can see unowned patients |
