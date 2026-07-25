# Evidence Report: 401 Unauthenticated on `POST /api/v1/mobile/patients`

> **Status**: Evidence collected. No code modified.
> **Date**: 2026-07-25

---

## 1. Complete Route Definition

**File**: `routes/api.php` (lines 22-52)

```
URI:     POST /api/v1/mobile/patients
Controller: App\Http\Controllers\Api\Mobile\PatientController::store
Method:     store(Request $request)

Route group structure:
  Route::prefix('v1')                          # /api/v1/...
    Route::prefix('mobile')                    # /api/v1/mobile/...
      ->middleware(['mobile.auth', 'auth:sanctum'])  # <-- STACKED middleware
        Route::post('/patients', [PatientController::class, 'store']);
```

The full request URI is: `POST /api/v1/mobile/patients`

---

## 2. Complete Middleware Stack (in execution order)

| Order | Middleware | Class | Can return 401? | Modifies Auth? |
|-------|-----------|-------|-----------------|----------------|
| 1 | `mobile.auth` | `App\Http\Middleware\MobileApiAuth` | **YES** — lines 70-76 | YES — calls `Auth::guard('sanctum')->setUser()` |
| 2 | `auth:sanctum` | `Illuminate\Auth\Middleware\Authenticate` with guard `sanctum` | **YES** — throws `AuthenticationException` | YES — calls `Auth::shouldUse('sanctum')` |

### Middleware registration

**File**: `bootstrap/app.php` (line 55)
```php
'mobile.auth' => \App\Http\Middleware\MobileApiAuth::class,
```

`auth:sanctum` is registered by `Illuminate\Auth\AuthServiceProvider` (Laravel core) as middleware alias `auth:` with guard name `sanctum`.

---

## 3. Complete Authentication Flow (with Auth::user() state at each step)

### Step 0: Request arrives
```
Auth::user()  → null  (default guard = 'web', no session)
Auth::guard('sanctum')->user() → null
$request->bearerToken() → "1|abc123def456..." (PRESENT)
```

### Step 1: `mobile.auth` — `MobileApiAuth::handle()`
**File**: `app/Http/Middleware/MobileApiAuth.php`

```php
$bearerToken = $request->bearerToken();  // "1|abc123..."
if (!$bearerToken) {
    return $next($request);  // NOT taken — token IS present
}

try {
    $accessToken = \Laravel\Sanctum\PersonalAccessToken::findToken($bearerToken);
    
    if ($accessToken && $accessToken->tokenable) {
        Auth::guard('sanctum')->setUser($accessToken->tokenable);
        // Auth::guard('sanctum')->user() now → $user
        return $next($request);
    }
    
    // ★★★ THIS IS THE 401 ★★★
    Log::warning('[MobileApiAuth] Invalid Bearer token', [...]);
    return response()->json(['message' => 'Unauthenticated.'], 401);
    
} catch (\Throwable $e) {
    // ★★★ OR THIS IS THE 401 ★★★
    return response()->json(['message' => 'Unauthenticated.'], 401);
}
```

**After:**
```
Auth::user() → null  (default guard NOT changed — still 'web')
Auth::guard('sanctum')->user() → ONLY IF findToken succeeded → $user
```

### Step 2: `auth:sanctum` — `Illuminate\Auth\Middleware\Authenticate`
**File**: `vendor/laravel/framework/src/Illuminate/Auth/Middleware/Authenticate.php`

```php
protected function authenticate($request, array $guards)
{
    foreach ($guards as $guard) {  // $guard = 'sanctum'
        if ($this->auth->guard($guard)->check()) {
            // If mobile.auth set the user → check() returns true
            return $this->auth->shouldUse($guard);
            // Sets default guard to 'sanctum'
            // Auth::user() now uses sanctum guard → returns the user
        }
    }
    // Only reached if mobile.auth didn't set the user AND Sanctum's
    // own Bearer resolution also fails
    $this->unauthenticated($request, $guards);  // Throws AuthenticationException
}
```

### Step 3: Controller (only if middleware passes)
**File**: `app/Http/Controllers/Api/Mobile/PatientController.php`

```php
public function store(Request $request)
{
    $validated = $request->validate([...]);
    
    $user = $request->user();  // Should have the user after middleware
    if (!$user) {
        $user = $this->resolveFromBearerToken($request);  // SECOND fallback
    }
    // ...
}
```

---

## 4. `AuthenticateWithBearer` trait

**File**: `app/Http/Middleware/AuthenticateWithBearer.php` (trait, NOT middleware)

### How `resolveFromBearerToken()` works:

```php
private function resolveFromBearerToken(Request $request): ?\App\Domains\Users\Models\User
{
    $bearerToken = $request->bearerToken();
    if (!$bearerToken) {
        return null;
    }

    try {
        $accessToken = \Laravel\Sanctum\PersonalAccessToken::findToken($bearerToken);
        if ($accessToken && $accessToken->tokenable) {
            $user = $accessToken->tokenable;
            Log::info(...);
            return $user;  // Returns user WITHOUT logging in
        }
        Log::warning('Invalid Bearer token', [...]);
    } catch (\Throwable $e) {
        Log::warning('Bearer token resolution failed', [...]);
    }
    return null;
}
```

### What it does NOT call:
- ❌ `Auth::login($user)` — NO
- ❌ `Auth::setUser($user)` — NO
- ❌ `Auth::guard('sanctum')->setUser($user)` — NO
- ❌ `$request->setUserResolver(...)` — NO

It **only returns the user object**. Callers must decide what to do with it.

**Usage in `PatientController::store()`:**
```php
$user = $request->user();
if (!$user) {
    $user = $this->resolveFromBearerToken($request);  // Fallback
}
if ($user) {
    $validated['primary_doctor_id'] = $user->id;
}
```

---

## 5. Auth Guards

**File**: `config/auth.php`

### Only ONE guard defined:
```php
'guards' => [
    'web' => [
        'driver' => 'session',
        'provider' => 'users',
    ],
],
```

### No `sanctum` guard in config/auth.php!

The `sanctum` guard is registered **DYNAMICALLY** by Sanctum's service provider:

**File**: `vendor/laravel/sanctum/src/SanctumServiceProvider.php` (line 35-40)
```php
public function register()
{
    config([
        'auth.guards.sanctum' => array_merge([
            'driver' => 'sanctum',
            'provider' => null,
        ], config('auth.guards.sanctum', [])),
    ]);
}
```

### How the sanctum guard is constructed:
**File**: `vendor/laravel/sanctum/src/SanctumServiceProvider.php` (lines 77-89)
```php
protected function createGuard($auth, $config)
{
    return new RequestGuard(
        new Guard(
            $auth,
            config('sanctum.expiration'),
            $config['provider'],
            config('sanctum.last_used_at', true)
        ),
        request(),
        $auth->createUserProvider($config['provider'] ?? null)
    );
}
```

So the sanctum guard is a `RequestGuard` wrapping Sanctum's `Guard`.

### Default guard:
```php
'defaults' => [
    'guard' => env('AUTH_GUARD', 'web'),
],
```
Default is `'web'`. Changed to `'sanctum'` by `auth:sanctum` middleware via `shouldUse()`.

### Sanctum config:
```php
'guard' => ['web'],  // Stateful guard used for first-party SPA auth
'expiration' => null,  // Tokens never expire
```

---

## 6. Sanctum Token Resolution

### `PersonalAccessToken::findToken()` implementation:

**File**: `vendor/laravel/sanctum/src/PersonalAccessToken.php` (lines 49-60)
```php
public static function findToken($token)
{
    if (strpos($token, '|') === false) {
        // Plain token (no ID prefix) — hash and search
        return static::where('token', hash('sha256', $token))->first();
    }

    // Token format: "{id}|{plaintext}"
    [$id, $token] = explode('|', $token, 2);  // $id = "1", $token = "abc123..."

    if ($instance = static::find($id)) {  // Lookup by PRIMARY KEY
        return hash_equals($instance->token, hash('sha256', $token)) ? $instance : null;
    }
    
    return null;  // Token ID not found → returns null
}
```

### How tokens are created (LoginAction):
```php
$token = $user->createToken('auth_token')->plainTextToken;
// Returns: "1|abc123def456..."
// Stored in DB: token = hash('sha256', "abc123def456...")
//                 id = 1
```

### How tokens are sent:
The full token `"1|abc123..."` is sent as `Authorization: Bearer 1|abc123...`

### Why `findToken` could return null:
1. **Token ID not found**: `static::find($id)` returns null → token doesn't exist in the DB
2. **Hash mismatch**: `hash_equals($instance->token, hash('sha256', $token))` returns false
3. **Plain token not found**: If token has no `|`, hash search fails

---

## 7. PatientController before Controller execution

**File**: `app/Http/Controllers/Api/Mobile/PatientController.php`

Inside `store()`:
```php
$user = $request->user();            // Uses default guard (sanctum after shouldUse())
if (!$user) {
    $user = $this->resolveFromBearerToken($request);  // Manual fallback
}
if ($user) {
    $validated['primary_doctor_id'] = $user->id;
}
```

If middleware passed, `$request->user()` returns the user. The `resolveFromBearerToken` fallback is only reached if middleware somehow passed without a user (shouldn't happen normally).

---

## 8. Before vs After Architecture Refactor

### Changes affecting Sanctum/auth in Phase 1:

| Change | File | Impact |
|--------|------|--------|
| **Shared Bearer trait** | `AuthenticateWithBearer.php` NEW | Consolidated 7+ duplicated Bearer resolution implementations into 1 trait. Returns user without `Auth::login()` side effect. |
| **MobileApiAuth unchanged** | `MobileApiAuth.php` | Still uses `Auth::guard('sanctum')->setUser()`. NOT changed in Phase 1. |
| **MakesApiRequests simplified** | `MakesApiRequests.php` | Single token source (ApiService singleton). Previously had dual-source. |
| **Token file encrypted** | `ApiService.php` | Changed `base64_encode`/`base64_decode` to `encrypt()`/`decrypt()`. Old base64 tokens fail decryption → cleared. |
| **Logout cleans all tokens** | `AuthController.php` | Now cleans all tokens, not just session-remember. |

### Key observation:
The `MobileApiAuth` middleware was **NOT modified** during the Phase 1 refactor. It still uses the original `Auth::guard('sanctum')->setUser()` approach. The only change is that `AuthenticateWithBearer` trait was created as a **separate** utility, but `MobileApiAuth` does NOT use it.

---

## 9. Runtime Evidence Needed

To determine the exact root cause, **runtime logging** would be needed to answer:

1. **Does the request reach the production server or the embedded Laravel?**
   - Check if `mobile_api_url` resolves correctly
   - Check network connectivity

2. **Is the Bearer token present in the Authorization header?**
   - `$request->bearerToken()` should return the token

3. **Does `PersonalAccessToken::findToken()` find the token?**
   - Run `PersonalAccessToken::where('token', hash('sha256', $plaintext))->first()` on the production DB
   - Check if the token ID exists in MySQL `personal_access_tokens` table

4. **Which database is being queried?**
   - Check the DB connection used by `PersonalAccessToken` model
   - Verify the token exists in that database

---

## 10. Final Diagnosis (Facts Only)

### Fact 1: The 401 is returned by `MobileApiAuth` middleware
**File**: `app/Http/Middleware/MobileApiAuth.php`
**Lines**: 70-76 (invalid token) or 80-83 (exception)
**Condition**: `\Laravel\Sanctum\PersonalAccessToken::findToken($bearerToken)` returned null

### Fact 2: `auth:sanctum` never runs when `MobileApiAuth` returns 401
Because `MobileApiAuth` runs FIRST in the middleware stack (`['mobile.auth', 'auth:sanctum']`), and returns a JSON 401 response directly (not throwing an exception), the `auth:sanctum` middleware is never reached.

### Fact 3: `findToken()` returns null because the token doesn't exist in the queried database
`PersonalAccessToken::findToken()` either:
- Calls `static::find($id)` which returns null (token ID not found)
- Or calls `static::where('token', hash(...))->first()` which returns null (plain token not found)

### Fact 4: Most likely root cause — Token not in the production DB
The token was likely:
- Created on the embedded Laravel (SQLite) but the request goes to production (MySQL), OR
- Created on production but subsequently deleted (e.g., logout on another device), OR
- Created by a different mechanism that doesn't persist to the `personal_access_tokens` table

### Fact 5: Alternative root cause — Token format issue
If the token was stored/transmitted with a prefix (`SANCTUM_TOKEN_PREFIX`) that doesn't match, or with encoding differences (e.g., URL encoding), the hash comparison in `findToken` could fail. However, `findToken` normalizes the token by stripping everything before the first `|`, so this is less likely.

### Fact 6: The `MobileApiAuth` middleware was originally created as a workaround for Sanctum's intermittent Bearer token failures
The docstring in `MobileApiAuth.php` states: "Sanctum's `auth:sanctum` middleware rejects these tokens intermittently, causing 401 on every mobile endpoint." This suggests the underlying Sanctum issue was never fully resolved, and `MobileApiAuth` is a band-aid that can itself fail.

### Fact 7: `EnsureFrontendRequestsAreStateful` is NOT in the middleware stack
This Sanctum middleware is only prepended to the middleware priority but is not explicitly added to the `mobile` route group. It does NOT run for `POST /api/v1/mobile/patients`.

### Fact 8: `ShouldUse('sanctum')` is never called when `MobileApiAuth` returns 401
Since `auth:sanctum` never runs when `MobileApiAuth` returns 401, the `shouldUse('sanctum')` call never happens. The default guard remains `'web'`.

---

## Appendix: Key Files

| File | Purpose |
|------|---------|
| `routes/api.php:46` | Route definition with middleware `['mobile.auth', 'auth:sanctum']` |
| `app/Http/Middleware/MobileApiAuth.php` | **Returns the 401** (lines 70-76) |
| `app/Http/Middleware/AuthenticateWithBearer.php` | Shared Bearer resolution trait (not used by MobileApiAuth) |
| `app/Http/Controllers/Api/Mobile/PatientController.php` | Controller that never executes due to 401 |
| `config/auth.php` | No `sanctum` guard defined (added dynamically) |
| `config/sanctum.php` | Sanctum config: `expiration => null`, `stateful => localhost` |
| `vendor/laravel/sanctum/src/PersonalAccessToken.php:49-60` | `findToken()` implementation |
| `vendor/laravel/sanctum/src/SanctumServiceProvider.php:35-40` | Dynamic sanctum guard registration |
| `vendor/laravel/sanctum/src/Guard.php:23-61` | Sanctum's `Guard::__invoke()` — Bearer resolution logic |
| `vendor/laravel/framework/src/Illuminate/Auth/RequestGuard.php` | `RequestGuard::user()` checks `$this->user` first |
| `vendor/laravel/framework/src/Illuminate/Auth/Middleware/Authenticate.php:42-52` | `auth:sanctum` middleware logic |
| `app/Services/Mobile/ApiService.php` | Token storage/retrieval singleton |
| `app/Repositories/Api/Traits/MakesApiRequests.php` | API request trait (uses ApiService token) |
| `bootstrap/app.php:55` | `mobile.auth` alias registration |
| `app/Domains/Auth/Actions/LoginAction.php:30-33` | Token creation via `createToken('auth_token')` |
