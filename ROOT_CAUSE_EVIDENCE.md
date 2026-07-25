# Root Cause Evidence: Why `findToken()` Returns Null

> Status: Evidence collected. No code modified.
> Date: 2026-07-25

---

## 1. Database Connection Used by `findToken()`

**File**: `vendor/laravel/sanctum/src/PersonalAccessToken.php` (line 3-48)
```php
class PersonalAccessToken extends Model implements HasAbilities
{
    // No $connection property → uses DEFAULT connection
    protected $casts = [...];
    protected $fillable = [...];
    protected $hidden = [...];
}
```

**Verified via tinker:**
```
PersonalAccessToken model $connection property = null
Default DB connection:     sqlite
Current DB connection:     sqlite
Database name:             storage/data/medical_plus.sqlite
```

**Conclusion**: `PersonalAccessToken::findToken()` queries the **local SQLite** database at `storage/data/medical_plus.sqlite`.

---

## 2. Token Lookup Result

```
sqlite3> SELECT COUNT(*) FROM personal_access_tokens;
→ 0

sqlite3> SELECT * FROM personal_access_tokens;
→ (empty)
```

**The `personal_access_tokens` table in the local SQLite database is EMPTY.** `findToken()` has zero records to search.

---

## 3. Users and Sessions

```
Users table:
  ID=1, name="Test Doctor", email="test@doctor.com", role="doctor"

Sessions table:
  4 sessions, 0 with a user_id (all guest sessions)
```

No user is currently authenticated via session. No Sanctum tokens exist.

---

## 4. Where Tokens ARE Created vs Where They're Sought

### Where the production API token is created:

**File**: `vendor/laravel/sanctum/src/HasApiTokens.php` (line 44-52)
```php
public function createToken(string $name, array $abilities = ['*'], ...)
{
    $plainTextToken = $this->generateTokenString();
    $token = $this->tokens()->create([
        'token' => hash('sha256', $plainTextToken),
        ...
    ]);
    return new NewAccessToken($token, $token->getKey().'|'.$plainTextToken);
}
```

**Called by**: `LoginAction::execute()` on the **PRODUCTION** server via `ApiService::loginToRemote()`:

**File**: `app/Services/Mobile/ApiService.php` (line 293-300)
```php
public static function loginToRemote(string $email, string $password): array
{
    $loginUrl = str_replace('/mobile', '', config('app.mobile_api_url')) . '/login';
    // = "https://prof-hosam-fekry.online/api/v1/login"
    $response = Http::timeout(30)->post($loginUrl, [...]);
    // Token created in PRODUCTION MySQL, returned to client
}
```

**The token from `loginToRemote()` is stored via `setToken()` → encrypted in SESSION + DISK FILE:**

**File**: `app/Services/Mobile/ApiService.php` (line 68-70)
```php
public function setToken(?string $token): void
{
    $this->token = $token;
    session(['api_token' => encrypt($token)]);    // session
    $this->writeTokenToFile($token);               // disk file
}
```

**It is NOT stored in the `personal_access_tokens` table.**

---

## 5. The Disconnect

| Operation | Database | Token Exists? |
|-----------|----------|---------------|
| `createToken()` called by LoginAction on production | MySQL => production `personal_access_tokens` | ✅ |
| `ApiService::setToken()` stores token | SESSION + DISK FILE (NOT the DB) | ✅ |
| `MobileApiAuth` calls `findToken()` on LOCAL request | SQLite => `personal_access_tokens` table | **❌ EMPTY** |

**The production API token is never stored in the local SQLite `personal_access_tokens` table.**
**The local SQLite has zero tokens.**
**`findToken()` returns null on every local request.**

---

## 6. Two Request Paths

### Path A: SyncEngine → Production (works ✅)
```
Frontend → _native/api/sync/engine → SyncEngineService → ApiService::post()
  → HTTP request to https://prof-hosam-fekry.online/api/v1/mobile/patients
  → Production server receives it
  → MobileApiAuth runs on PRODUCTION (MySQL)
  → findToken() queries production MySQL personal_access_tokens
  → Token EXISTS in production MySQL (created during loginToRemote)
  → ✅ 200
```

### Path B: Frontend → Local Embedded Laravel (fails ❌)
```
Vue/axios → GET/POST /api/v1/mobile/patients
  → Local embedded Laravel receives it
  → MobileApiAuth runs on LOCAL (SQLite)
  → findToken() queries local SQLite personal_access_tokens
  → Token DOES NOT EXIST in local SQLite (0 records)
  → ❌ 401 returned at MobileApiAuth.php:70-76
```

---

## 7. Why the Local SQLite Has No Tokens

The local `session-remember` token (created during web login) **is** created in the local SQLite. Evidence flow:

1. `AuthController::login()` calls `$request->user()->createToken('session-remember')`
   → Creates token in SQLite `personal_access_tokens`
2. `AuthController::logout()` deletes it:
   ```php
   \Laravel\Sanctum\PersonalAccessToken::findToken($token)?->delete();
   ```
   → Token deleted from SQLite

Re-run the cycle: **token created → user logs out → token deleted → 0 tokens remain.**

The `auth_token` (production API token) is only created on the **production MySQL** server — never in local SQLite.

---

## 8. Final Conclusion

| Question | Answer | Evidence |
|----------|--------|----------|
| Which database contains the token? | **Production MySQL** | Token created by `LoginAction::execute()` when `ApiService::loginToRemote()` hits production's `POST /api/v1/login` |
| Which database is queried by `findToken()`? | **Local SQLite** (`storage/data/medical_plus.sqlite`) | PersonalAccessToken model has no `$connection` override, uses default `sqlite` connection |
| Are they the same? | **NO** | Production MySQL vs Local SQLite |
| Code responsible for the disconnect? | **`MobileApiAuth::handle()`** (lines 70-76) | Immediately returns 401 when `findToken()` returns null, without giving `auth:sanctum` a chance to try session-based auth or its own Bearer resolution |

**The root cause is that `MobileApiAuth` unconditionally returns 401 when `findToken()` fails on a local request**, but the token was never meant to exist in the local SQLite — it exists on the production server where the request should be routed, or the request should fall back to session-based auth through `auth:sanctum`.

---

## Appendix: Commands Used to Verify

```bash
# Check database config
php artisan tinker --execute="echo config('database.default')"

# Check PersonalAccessToken connection
php artisan tinker --execute="echo (new Laravel\Sanctum\PersonalAccessToken)->getConnectionName() ?? 'default'"

# Count tokens
sqlite3 storage/data/medical_plus.sqlite "SELECT COUNT(*) FROM personal_access_tokens"

# Check environment
php artisan tinker --execute="echo env('DB_CONNECTION') . ' ' . env('DB_DATABASE')"
```
