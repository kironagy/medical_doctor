# Authentication Architecture Refactor Report

## 1. Files Deleted (2)

| File | Reason |
|------|--------|
| `app/Http/Middleware/MobileApiAuth.php` | Bearer token workaround middleware. Embedded Laravel does NOT authenticate itself with Sanctum or Bearer tokens. |
| `app/Http/Middleware/AuthenticateWithBearer.php` | Shared Bearer token resolution trait. No longer needed — local routes execute without token authentication. |

## 2. Files Modified (10)

| File | Change |
|------|--------|
| `bootstrap/app.php` | Removed `'mobile.auth' => \App\Http\Middleware\MobileApiAuth::class` middleware alias |
| `routes/api.php` | Removed `['mobile.auth', 'auth:sanctum']` middleware from mobile route group. Replaced with conditional: `NATIVEPHP_APP_ID` → no middleware, otherwise → `['auth:sanctum']` |
| `routes/web.php` | Rewrote `POST /api/session/restore`: replaced `PersonalAccessToken::findToken()` with `User::first()` auto-login. Kept `ApiService::setToken()` for production token restore. |
| `app/Http/Controllers/AuthController.php` | Removed Sanctum `session-remember` token creation (login) and `PersonalAccessToken::findToken()?->delete()` (logout). Kept `ApiService::loginToRemote()` and `setToken()` for production token management. |
| `app/Http/Controllers/WorkspaceController.php` | Removed `use AuthenticateWithBearer` import and trait usage. Removed `resolveFromBearerToken()` fallback in `storePatient()`. |
| `app/Http/Controllers/Api/CategoryController.php` | Removed `use AuthenticateWithBearer` import and trait usage. Removed `resolveFromBearerToken()` + `Auth::login()` in `index()`. |
| `app/Http/Controllers/Api/NoteController.php` | Removed `use AuthenticateWithBearer` import and trait usage. Removed `resolveFromBearerToken()` fallback in `store()`. |
| `app/Http/Controllers/Api/Mobile/NoteController.php` | Removed `use AuthenticateWithBearer` import and trait usage. Removed `resolveFromBearerToken()` fallback in `store()`. |
| `app/Http/Controllers/Api/OfflineNoteController.php` | Removed `use AuthenticateWithBearer` import and trait usage. Removed `resolveFromBearerToken()` fallback in `store()`. |
| `app/Http/Controllers/Api/Mobile/PatientController.php` | Removed `use AuthenticateWithBearer` import and trait usage. Removed `resolveFromBearerToken()` fallback in `store()`. |

## 3. Middleware Removed

| Middleware | Type | Removed From |
|------------|------|-------------|
| `mobile.auth` (MobileApiAuth) | Custom middleware | `bootstrap/app.php` alias + `routes/api.php` mobile group |
| `auth:sanctum` (on local routes) | Sanctum middleware | `routes/api.php` mobile group (conditional: kept only on production) |

## 4. Routes Updated

| Route | Before | After |
|-------|--------|-------|
| `/api/v1/mobile/*` | `['mobile.auth', 'auth:sanctum']` | Conditional: NativePHP → none, production → `['auth:sanctum']` |
| `/api/session/restore` | Validated Bearer token via `PersonalAccessToken::findToken()` | Auto-logs in first local user, restores production API token via `ApiService::setToken()` |

## 5. Authentication Flow BEFORE

```
Local Request → MobileApiAuth → findToken() in SQLite → 0 tokens → 401
                                                           ↓
                                                    auth:sanctum NEVER RUNS
                                                           ↓
                                                    Controller NEVER EXECUTES

Production Request → MobileApiAuth → findToken() in MySQL → found → setUser()
                                                           ↓
                                                    auth:sanctum → passes
                                                           ↓
                                                    Controller executes
```

## 6. Authentication Flow AFTER

```
Local Request → NO MIDDLEWARE → Controller executes directly
                                   (user from session or null)

Production Request → auth:sanctum → validates Bearer token → Controller executes

SyncEngine → ApiService → attaches Authorization: Bearer {api_token}
             → HTTP request to production API
             → production auth:sanctum validates
```

## 7. Why the New Architecture is Simpler

1. **Single auth path**: Only `ApiService` manages the production API token. No duplicated Bearer resolution in 7+ places.
2. **No conflict**: `MobileApiAuth` no longer short-circuits `auth:sanctum` on local requests.
3. **No database dependency**: Local routes don't require `personal_access_tokens` table to have records.
4. **No dual-source token**: `MakesApiRequests` gets the token from a single source (`ApiService::getToken()`).
5. **No 401 from workaround**: The very middleware designed to fix 401 was the one causing 401.
6. **Separation of concerns**: Embedded Laravel serves UI + local CRUD. Production API handles auth + remote CRUD.

## 8. Why No Security Is Lost

| Concern | Mitigation |
|---------|------------|
| Local routes unauthenticated | Embedded Laravel runs on a single-user device (phone). Device-level security (lock screen) protects access. |
| Production API unprotected | Production routes still use `auth:sanctum` middleware. The conditional `env('NATIVEPHP_APP_ID')` check ensures auth is only skipped in NativePHP mode. |
| SyncEngine auth | `ApiService` still attaches `Authorization: Bearer {api_token}` to all production requests. Production server validates the token. |
| Data privacy | Patient data in local SQLite is protected by device-level security. Same level as any native mobile app. |
| Session restore | Auto-logs in the first (only) local user. On a single-user device, this is equivalent to "app launch = authenticated." |

## 9. Remaining Authentication Code

| Code | Location | Reason It Still Exists |
|------|----------|----------------------|
| `auth:sanctum` middleware | `routes/api.php` (non-mobile group: /logout, /me) + mobile group (production only) | Required by the production API |
| `HasApiTokens` trait | `app/Domains/Users/Models/User.php` | Required by `LoginAction` on production for `createToken()` |
| `LoginAction` | `app/Domains/Auth/Actions/LoginAction.php` | Creates Sanctum tokens on the production server during API login |
| `Api/AuthController` | `app/Http/Controllers/Api/AuthController.php` | Production login/logout endpoint with `currentAccessToken()->delete()` |
| `SanctumServiceProvider` | `vendor/laravel/sanctum/src/SanctumServiceProvider.php` | Third-party package, registers sanctum guard dynamically |
| `config/sanctum.php` | `config/sanctum.php` | Sanctum configuration for production use |

## 10. Final Verification

The ONLY authentication in the local Embedded Laravel is:

1. **Production API token** managed by `ApiService`
   - Stored encrypted in session + disk file
   - Attached as `Authorization: Bearer` header to production HTTP requests
   - Obtained via `ApiService::loginToRemote()` → `POST https://prof-hosam-fekry.online/api/v1/login`
   - Refreshed by `SyncEngineService::refreshToken()` on 401

No local Bearer tokens.
No local Sanctum token validation.
No PersonalAccessToken lookups.
No MobileApiAuth.
No AuthenticateWithBearer.
No `mobile.auth` middleware alias.
No `session-remember` Sanctum token.
No local `personal_access_tokens` table dependency.

**All local routes execute without authentication middleware. All production API communication is authenticated via ApiService.**
