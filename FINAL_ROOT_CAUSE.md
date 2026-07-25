# FINAL ROOT CAUSE EVIDENCE

> Status: Evidence collected. No code modified.
> Date: 2026-07-25

---

## 1. Runtime Environment

| Property | Value | Source |
|----------|-------|--------|
| APP_ENV | `production` | `.env` file |
| APP_URL | `http://127.0.0.1` | `.env` file |
| APP_NAME | "prof hosam fekry ortho team" | `config/app.php` |
| DB_CONNECTION | `sqlite` | `.env` file |
| DB_DATABASE | `storage/data/medical_plus.sqlite` | `.env` file |
| DB_HOST | NOT SET | `.env` file |
| DB_USERNAME | NOT SET | `.env` file |
| DB_PASSWORD | NOT SET | `.env` file |
| NATIVEPHP_APP_ID | `com.medicalplus.app` | `.env` file |
| SANCTUM_TOKEN_PREFIX | (empty) | `.env` file |
| mobile_api_url | `https://prof-hosam-fekry.online/api/v1/mobile` | `config/app.php` |

### Database determination:
- Is this Embedded Laravel? **YES** — `NATIVEPHP_APP_ID` is set to `com.medicalplus.app`
- Is this Production Laravel? **NO** — this is the local NativePHP embedded runtime
- Is this Local SQLite? **YES** — `DB_CONNECTION=sqlite`, `DB_DATABASE=storage/data/medical_plus.sqlite`
- Is this Production MySQL? **NO** — only SQLite connection is configured in `.env`

---

## 2. Authentication Flow

```
HTTP Request: POST /api/v1/mobile/patients
  Authorization: Bearer <token>
  │
  ▼
Kernel → Route matched
  │
  ▼
middleware[0]: 'mobile.auth' (MobileApiAuth)
  │
  ├─ $request->bearerToken() → "<token>" (PRESENT)
  ├─ PersonalAccessToken::findToken("<token>")
  │   └─ Queries SQLite personal_access_tokens → 0 records → null
  ├─ if (!$accessToken) → TRUE
  │
  ▼
★★★ EXECUTION STOPS HERE ★★★
  Return response()->json(['message' => 'Unauthenticated.'], 401);
  File: app/Http/Middleware/MobileApiAuth.php, LINE 70-76
  │
  ▼
middleware[1]: 'auth:sanctum' → ● NEVER EXECUTED ●
  │
  ▼
PatientController::store() → ● NEVER EXECUTED ●
```

**Evidence file**: `app/Http/Middleware/MobileApiAuth.php` (lines 60-76)
```php
if ($accessToken && $accessToken->tokenable) {
    Auth::guard('sanctum')->setUser($accessToken->tokenable);
    return $next($request);  // ← Only reached if token FOUND
}

// Token present but invalid — return 401
Log::warning('[MobileApiAuth] Invalid Bearer token', [...]);
return response()->json(['message' => 'Unauthenticated.'], 401);
// ← EXECUTION STOPS — auth:sanctum NEVER RUNS
```

---

## 3. Incoming Token

In the development environment, the frontend sends a Bearer token from localStorage. This token is the `auth_token` obtained from `ApiService::loginToRemote()` which calls:

```
POST https://prof-hosam-fekry.online/api/v1/login
→ Returns { token: "{id}|{plaintext}" }
```

The token format is: `{tokenId}|{plaintext}` where:
- `tokenId` = auto-increment ID from `personal_access_tokens` table on PRODUCTION MySQL
- `plaintext` = `{token_prefix}{random40}{crc32b}`

The `.env` has `SANCTUM_TOKEN_PREFIX` = (empty), so the plaintext is exactly 44 characters (40 random + 4 crc32b).

---

## 4. Token Lookup

**Before `findToken()` executes:**

| Property | Value |
|----------|-------|
| Default DB connection | `sqlite` |
| DB connection name | `sqlite` |
| Driver | `sqlite` |
| Database file | `storage/data/medical_plus.sqlite` |

**`PersonalAccessToken::findToken($token)` executes:**

**File**: `vendor/laravel/sanctum/src/PersonalAccessToken.php` (lines 49-60)
```php
public static function findToken($token)
{
    if (strpos($token, '|') === false) {
        return static::where('token', hash('sha256', $token))->first();
    }

    [$id, $token] = explode('|', $token, 2);

    if ($instance = static::find($id)) {
        return hash_equals($instance->token, hash('sha256', $token)) ? $instance : null;
    }
}
```

**Result**: `static::find($id)` returns null because there are **zero records** in `personal_access_tokens` table in the SQLite database.

---

## 5. Search Every Database

There is only **one** configured database connection in the `.env`:

| Connection | Driver | Database | Tokens? |
|------------|--------|----------|---------|
| `sqlite` | sqlite | `storage/data/medical_plus.sqlite` | **0** |

**The MySQL connection IS defined** in `config/database.php` but:
- It has NO credentials in `.env` (no DB_HOST, DB_USERNAME, DB_PASSWORD set)
- The `database` default falls back to `env('DB_DATABASE', 'laravel')` which resolves to `storage/data/medical_plus.sqlite` (the SQLite file)
- The MySQL driver would try to open a SQLite file → this is broken/misconfigured in dev

**No MySQL is available in this environment.** All database queries go to SQLite.

---

## 6. Login Investigation

### Where `createToken()` writes to:

**File**: `vendor/laravel/sanctum/src/HasApiTokens.php` (lines 44-52)
```php
public function createToken(string $name, array $abilities = ['*'], ...)
{
    $plainTextToken = $this->generateTokenString();
    $token = $this->tokens()->create([       // ← morphMany relationship
        'name' => $name,
        'token' => hash('sha256', $plainTextToken),
        'abilities' => $abilities,
        'expires_at' => $expiresAt,
    ]);
    return new NewAccessToken($token, $token->getKey().'|'.$plainTextToken);
}
```

The `tokens()` relationship uses the **same model** as `PersonalAccessToken::findToken()`, which uses the **default database connection**.

### Two login paths:

**Path A: Web Login** (`AuthController::login()`)
```
User submits POST /login
→ AuthController::login()
  → $request->user()->createToken('session-remember')
    → INSERT INTO personal_access_tokens (SQLite) ✅
  → ApiService::loginToRemote()
    → POST https://prof-hosam-fekry.online/api/v1/login
      → LoginAction on PRODUCTION server creates token in MySQL
      → Token returned to client
    → setToken() stores in SESSION + DISK FILE (NOT in SQLite personal_access_tokens)
```

**Path B: API Login** (`Api/AuthController::login()`)
```
User submits POST /api/v1/login (to LOCAL embedded Laravel)
→ LoginAction::execute()
  → $user->createToken('auth_token')
    → INSERT INTO personal_access_tokens (SQLite) ✅

OR

User submits POST /api/v1/login (to PRODUCTION server)
→ LoginAction::execute()
  → $user->createToken('auth_token')
    → INSERT INTO personal_access_tokens (MySQL) ✅
```

---

## 7. Logout Investigation

### Web Logout (`AuthController::logout()`)

**File**: `app/Http/Controllers/AuthController.php` (lines 104-115)
```php
// 1. Delete the Sanctum session-remember token
$encrypted = session('session_remember_token');
if ($encrypted) {
    $token = decrypt($encrypted);
    \Laravel\Sanctum\PersonalAccessToken::findToken($token)?->delete();
}
```

`findToken()` queries LOCAL SQLite. If found → `delete()` removes from SQLite.

### API Logout (`Api/AuthController::logout()`)

**File**: `app/Http/Controllers/Api/AuthController.php` (lines 41-47)
```php
$user = $request->user();
if ($user) {
    $user->currentAccessToken()->delete();
}
```

`currentAccessToken()` uses the token resolved during authentication. `delete()` removes from the database that `findToken()` queried — which is the DEFAULT connection.

**If logout happens on LOCAL → deletes from SQLite**
**If logout happens on PRODUCTION → deletes from MySQL**

---

## 8. MobileApiAuth Investigation

**File**: `app/Http/Middleware/MobileApiAuth.php`

| Property | Value |
|----------|-------|
| Model used | `\Laravel\Sanctum\PersonalAccessToken` |
| Connection used | **Default** (SQLite) — model has no `$connection` override |
| Table used | `personal_access_tokens` |
| Model overrides `$connection`? | **NO** |
| Uses default DB connection? | **YES** |

**Verified**: No custom PersonalAccessToken model exists in the project:
```
glob('app/**/*PersonalAccessToken*') → []
grep 'Sanctum::usePersonalAccessTokenModel' → 0 results
grep 'class PersonalAccessToken extends' → 0 results
```

---

## 9. Sanctum Investigation

| Property | Value | Source |
|----------|-------|--------|
| PersonalAccessToken model | `Laravel\Sanctum\PersonalAccessToken` | Default |
| Connection | `null` (uses default) | Model file |
| Table | `personal_access_tokens` | Model convention |
| Guard | `sanctum` | Registered dynamically by `SanctumServiceProvider` |
| Provider | `null` | SanctumServiceProvider `register()` |
| Custom model override? | **NO** | No `usePersonalAccessTokenModel()` call in app code |
| Token prefix | (empty) | `config/sanctum.php` + `.env` |
| Expiration | `null` (never) | `config/sanctum.php` |

---

## 10. Compare Login vs Mobile Request

| Step | Database | Connection | Evidence |
|------|----------|------------|----------|
| Login creates `session-remember` token | SQLite | `default` | `createToken()` on local Laravel |
| Login creates `auth_token` (loginToRemote) | **MySQL** (production) | Production's `default` | `loginToRemote()` calls production API |
| Login stores `auth_token` locally | **Session + Disk File** | N/A | `ApiService::setToken()` — NOT the DB |
| MobileApiAuth reads token | **SQLite** | `default` | `PersonalAccessToken::findToken()` |
| Logout deletes `session-remember` | SQLite | `default` | `PersonalAccessToken::findToken()?->delete()` |
| Logout deletes `auth_token` (API) | Depends where logout runs | `default` | `$user->currentAccessToken()->delete()` |

### The critical disconnect:

```
Login creates token in:          SQLite (for session-remember)
                                 MySQL (for auth_token, via production)
                                 Session+Disk (for auth_token, locally)

MobileApiAuth reads token from:  SQLite personal_access_tokens
                                 → which has 0 records because:
                                   - session-remember was deleted on logout
                                   - auth_token was never stored in SQLite
```

**File responsible for the disconnect**: `app/Http/Middleware/MobileApiAuth.php` (lines 70-76)
- Returns 401 when `findToken()` returns null
- Does NOT pass through to `auth:sanctum` for a second attempt
- The `auth_token` (from production) is stored by `ApiService::setToken()` in session + disk, but NEVER in the `personal_access_tokens` table

---

## 11. Final Evidence

### 1. Does the token exist?
**YES**, the Bearer token exists — the frontend obtained it from a previous login via `ApiService::loginToRemote()`.

### 2. If yes, where?
The `auth_token` exists in:
- **Production MySQL** `personal_access_tokens` table (created by `LoginAction::createToken()` on the production server)
- **Local session** (encrypted via `ApiService::setToken()`)
- **Local disk file** `storage/app/.api_sync_token` (encrypted via `ApiService::setToken()`)

The `auth_token` does **NOT** exist in the local SQLite `personal_access_tokens` table.

### 3. Which database is queried?
**Local SQLite** at `storage/data/medical_plus.sqlite`, table `personal_access_tokens`.

`PersonalAccessToken` model has **no `$connection` property** → uses default connection (`sqlite`).

### 4. Which database contains the token?
**Production MySQL** — the token was created by `LoginAction::execute()` when `ApiService::loginToRemote()` called `POST https://prof-hosam-fekry.online/api/v1/login`.

### 5. Are login and MobileApiAuth using different databases?
**YES** — for the `auth_token`:
- Token creation: **Production MySQL** (via `loginToRemote()`)
- Token lookup: **Local SQLite** (via `MobileApiAuth`)

For the `session-remember` token:
- Token creation: **Local SQLite** ✅
- But it was **deleted on logout** → 0 records remain

### 6. Does the request reach Sanctum?
**NO.** The `auth:sanctum` middleware NEVER executes. `MobileApiAuth` returns `401` at line 70-76 before `auth:sanctum` even starts.

### 7. Which exact file and line returns 401?
**`app/Http/Middleware/MobileApiAuth.php`, line 71-76**:
```php
return response()->json(['message' => 'Unauthenticated.'], 401);
```

### 8. What is the single verified root cause?

**`MobileApiAuth` middleware returns 401 because `PersonalAccessToken::findToken()` queries the local SQLite database, which has zero personal_access_tokens. The `auth_token` that the frontend sends as a Bearer token was created on the production MySQL server and was never stored in the local SQLite `personal_access_tokens` table. The `session-remember` token that WAS created locally was deleted on logout.**

The `MobileApiAuth` middleware then **short-circuits the request** — `auth:sanctum` never runs, so Sanctum's own Bearer resolution and session-based authentication never get a chance to authenticate the request.

---

## Appendix: Evidence Chain

| Evidence | File/Command | Result |
|----------|-------------|--------|
| Default DB is sqlite | `config/app.php` + `.env` | `DB_CONNECTION=sqlite` |
| SQLite database file | `.env` | `DB_DATABASE=storage/data/medical_plus.sqlite` |
| PersonalAccessToken has no custom connection | `vendor/laravel/sanctum/src/PersonalAccessToken.php` | No `$connection` property |
| No custom PersonalAccessToken model | `glob('app/**/*PersonalAccessToken*')` | 0 files |
| SQLite personal_access_tokens is empty | `sqlite3 ... SELECT COUNT(*)` | **0 records** |
| SQLite has users table with 1 user | `sqlite3 ... SELECT COUNT(*) FROM users` | 1 user: test@doctor.com |
| `loginToRemote()` calls production | `app/Services/Mobile/ApiService.php` line 293 | `$loginUrl = "https://prof-hosam-fekry.online/api/v1/login"` |
| `setToken()` stores in session+disk, NOT in personal_access_tokens | `app/Services/Mobile/ApiService.php` lines 69-70 | `session(['api_token' => encrypt($token)])` + `writeTokenToFile()` |
| MobileApiAuth returns 401 without passing to Sanctum | `app/Http/Middleware/MobileApiAuth.php` lines 70-76 | `return response()->json(['message' => 'Unauthenticated.'], 401);` |
| Middleware stacking: `mobile.auth` runs FIRST | `routes/api.php` line 46 | `['mobile.auth', 'auth:sanctum']` |
| auth:sanctum never runs | Execution flow | See section 2 flow chart |
