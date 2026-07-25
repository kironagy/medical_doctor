# AUTHENTICATION AND ARCHITECTURE AUDIT

**Project:** Medical Plus (prof hosam fekry ortho team)  
**Audit Date:** 2026-07-25  
**Auditor:** Buffy (DeepSeek V4 Flash)  
**Mode:** Deep Architecture Investigation (No Code Changes)  

---

## Table of Contents

1. [Executive Summary](#1-executive-summary)
2. [System Architecture](#2-system-architecture)
3. [Authentication Architecture](#3-authentication-architecture)
4. [Token Lifecycle](#4-token-lifecycle)
5. [Web Authentication Flow](#5-web-authentication-flow)
6. [Mobile Authentication Flow](#6-mobile-authentication-flow)
7. [API Authentication Flow](#7-api-authentication-flow)
8. [Doctor Isolation Architecture](#8-doctor-isolation-architecture)
9. [Patient Architecture](#9-patient-architecture)
10. [Repository Architecture](#10-repository-architecture)
11. [Offline/Sync Architecture](#11-offlinesync-architecture)
12. [Runtime Flow Diagrams](#12-runtime-flow-diagrams)
13. [All Authentication Issues](#13-all-authentication-issues)
14. [All Token Issues](#14-all-token-issues)
15. [All Security Issues](#15-all-security-issues)
16. [All Doctor Isolation Issues](#16-all-doctor-isolation-issues)
17. [All Offline Issues](#17-all-offline-issues)
18. [All Sync Issues](#18-all-sync-issues)
19. [Hidden Bugs](#19-hidden-bugs)
20. [Technical Debt](#20-technical-debt)
21. [Risk Assessment](#21-risk-assessment)
22. [Prioritized Fix Roadmap](#22-prioritized-fix-roadmap)
23. [Appendix](#23-appendix)

---

## 1. Executive Summary

This is a **Laravel + Inertia + Vue SPA** medical application for orthopedic doctors. It runs in a **hybrid topology**: a production MySQL-backed web server (`prof-hosam-fekry.online`) and an **embedded Laravel runtime** inside a **NativePHP Android app** that uses a **local SQLite database** for offline operation.

The authentication system is a **multi-layered, multi-path architecture** that has evolved through 8+ phases of development. There are **three independent authentication mechanisms** operating simultaneously:

1. **Laravel Session** — Traditional session cookie auth for the web SPA
2. **Sanctum Bearer Tokens** — API tokens for the mobile/API layer
3. **Production API Token** — A secondary token (`api_token`) stored in session for authenticating *outbound requests* to the production server

The architecture has **critical design flaws**:
- **Duplicate authentication paths** (ApiService vs MakesApiRequests) that can desync
- **Manual Bearer token resolution** in 7+ different places (code duplication)
- **Intermittent 401 errors** caused by Sanctum's token resolution failing for GuzzleHttp requests
- **A custom middleware (`MobileApiAuth`)** that works around a Sanctum bug by pre-resolving Bearer tokens
- **Multiple note controllers** (3) with duplicate patient resolution logic
- **Potential doctor data leakage** via `orWhereNull('primary_doctor_id')` in the DoctorIsolationScope

**Risk Level: HIGH**  
The system works but has fragile interdependencies, duplicated authentication paths, and several security edge cases that could cause data leakage or service disruption.

---

## 2. System Architecture

### 2.1 Deployment Topology

```
┌─────────────────────────────────────────────────────────────────┐
│                    PRODUCTION SERVER                            │
│  prof-hosam-fekry.online                                       │
│                                                                 │
│  ┌──────────┐  ┌──────────────┐  ┌──────────────┐              │
│  │ MySQL DB │  │ Laravel App  │  │ Vue SPA      │              │
│  │          │  │ (API + Web)  │  │ (Inertia)    │              │
│  └──────────┘  └──────────────┘  └──────────────┘              │
│                      │                                         │
│                      │ HTTPS / Bearer Token                     │
└──────────────────────┼──────────────────────────────────────────┘
                       │
                       ▼
┌─────────────────────────────────────────────────────────────────┐
│                    ANDROID DEVICE (NativePHP)                    │
│                                                                 │
│  ┌──────────────────────────────────────────────────────────┐   │
│  │              Android WebView                             │   │
│  │  ┌──────────────────────────────────────┐                │   │
│  │  │  Vue SPA (Same as production)        │                │   │
│  │  │  - Inertia pages                     │                │   │
│  │  │  - localStorage for token storage    │                │   │
│  │  └──────────────────────────────────────┘                │   │
│  └──────────────────────────────────────────────────────────┘   │
│                       │                                          │
│                       ▼ HTTP (localhost-style)                    │
│  ┌──────────────────────────────────────────────────────────┐   │
│  │         Embedded Laravel Runtime                          │   │
│  │                                                            │   │
│  │  ┌──────────────┐  ┌──────────────┐  ┌──────────────┐   │   │
│  │  │ SQLite DB    │  │ SyncEngine   │  │ ApiService   │   │   │
│  │  │ (local SOT)  │  │ (orchestrator)│  │ (HTTP client) │   │   │
│  │  └──────────────┘  └──────────────┘  └──────┬───────┘   │   │
│  │                                              │            │   │
│  │  ┌──────────────┐  ┌──────────────┐         │            │   │
│  │  │ PatientRepo  │  │ OfflineFile  │         │            │   │
│  │  │ (orchestrator)│  │ Repo         │         │            │   │
│  │  └──────────────┘  └──────────────┘         │            │   │
│  └──────────────────────────────────────────────┼────────────┘   │
│                                                 │                │
│                                  HTTPS / Bearer Token             │
└──────────────────────────────────────────────────────────────────┘
```

### 2.2 Key Architecture Decisions

| Decision | Rationale | File(s) |
|---|---|---|
| Embedded Laravel in NativePHP | Allows offline operation with same codebase | `bootstrap/app.php`, `NativeServiceProvider` |
| SQLite as local SOT | Survives app restarts, provides offline CRUD | `storage/data/medical_plus.sqlite` |
| Hybrid repository pattern | Local-first reads, API writes with fallback | `app/Repositories/PatientRepository.php` |
| sync_status column | Tracks offline changes without separate queue table | `patients.sync_status`, `patient_notes.sync_status` |
| CSRF exemption for /api/* | Offline embedded Laravel has no valid CSRF token | `bootstrap/app.php` lines 37-39 |
| Auth middleware exemption for _native/* | Controllers handle null user gracefully | `routes/web.php` lines 119-125 |

---

## 3. Authentication Architecture

### 3.1 Authentication Methods Used

| # | Method | Location | Status | Used By |
|---|---|---|---|---|
| 1 | **Sanctum Bearer Token** | `api.php` route `auth:sanctum` | ✅ Active | Mobile API, SyncEngine |
| 2 | **Laravel Session** | `web.php` route `auth` | ✅ Active | Web SPA (Inertia) |
| 3 | **MobileApiAuth middleware** | `app/Http/Middleware/MobileApiAuth.php` | ✅ Active | Mobile API group (pre-resolves Bearer token) |
| 4 | **Manual Bearer resolution** | Scattered across 7+ controllers | ⚠️ Active | Offline controllers, note controllers |
| 5 | **Session restore endpoint** | `POST /api/session/restore` | ✅ Active | App restart WebView session recovery |
| 6 | **Production ApiToken (api_token)** | Stored in session, managed by ApiService | ✅ Active | Outbound production API calls |
| 7 | **Credentials-based auto-refresh** | Encrypted email/password in session | ⚠️ Partial | SyncEngine 401 recovery |

### 3.2 Authentication Methods NOT Used (But Exist)

| # | Method | File(s) | Status |
|---|---|---|---|
| 1 | `RefreshToken` model | `app/Models/RefreshToken.php` | ❌ Dead code — never instantiated |
| 2 | `ApiToken` model | `app/Models/ApiToken.php` | ❌ Dead code — never instantiated |
| 3 | `PasswordResetToken` model | `app/Models/PasswordResetToken.php` | ❌ Dead code — not used in routes |
| 4 | `VerificationToken` model | `app/Models/VerificationToken.php` | ❌ Dead code — not used in routes |
| 5 | `AuthToken` model | `app/Models/AuthToken.php` | ❌ Dead code — not used in routes |
| 6 | `TokenLog` model | `app/Models/TokenLog.php` | ❌ Dead code — not used in routes |

### 3.3 Guard Configuration

**`config/auth.php`:**
- Default guard: `web` (session-based)
- Only guard defined: `web` with `session` driver
- User provider: `eloquent` with `User` model
- No `sanctum` guard is explicitly defined (Sanctum auto-registers its guard)

**`config/sanctum.php`:**
- Stateful domains: localhost + current app URL
- Guard: `['web']` (Sanctum checks the web guard first)
- Expiration: `null` (tokens never expire)
- Token prefix: empty

### 3.4 Middleware Stack

**Web routes (`routes/web.php`):**
```
no middleware (public endpoints: /login, /api/session/restore)
                       ↓
auth (session-based)
                       ↓
role:super-admin (admin routes)
```

**API routes (`routes/api.php`):**
```
POST /v1/login → no middleware
                       ↓
/v1/* → auth:sanctum
                       ↓
/v1/mobile/* → mobile.auth + auth:sanctum (stacked)
```

**Native routes (`routes/web.php`):**
```
/api/v1/*, /_native/* → NO auth middleware, NO CSRF middleware
   Auth is handled at the CONTROLLER level
```

### 3.5 CSRF Configuration

**`bootstrap/app.php` lines 32-41:**
```php
$middleware->validateCsrfTokens(except: [
    '/api/session/restore',
    '/api/v1/*',
    '/_native/*',
]);
```

**Impact:** All native API routes and the session restore endpoint have CSRF protection **disabled**. This is intentional because:
1. When offline, the embedded Laravel has no production session → no valid CSRF token
2. These are JSON API routes (axios calls), not browser form submissions
3. Web form routes (/login, /workspace, /settings, /admin) remain CSRF-protected

**Risk:** If an attacker can inject JavaScript into the WebView context, they can make authenticated API calls without a CSRF token.

---

## 4. Token Lifecycle

### 4.1 Types of Tokens

| Token Type | Format | Created By | Stored In | Purpose |
|---|---|---|---|---|
| **Sanctum Auth Token** | `ID|hash` (plainTextToken) | `LoginAction::execute()` → `$user->createToken('auth_token')` | `personal_access_tokens` table | Mobile API authentication |
| **Session Remember Token** | `ID|hash` (plainTextToken) | `AuthController::login()` → `$user->createToken('session-remember')` | Session (encrypted) + localStorage | WebView session recovery |
| **Production API Token** | Same as Auth Token | `ApiService::loginToRemote()` → response from production server | Session (encrypted) + localStorage + disk file | Outbound API calls from embedded Laravel |

### 4.2 Token Creation Flow

```
User submits login form
       ↓
AuthController::login() [app/Http/Controllers/AuthController.php:28]
       ↓
Auth::attempt() + session()->regenerate()
       ↓
createToken('session-remember') → stored in session as 'session_remember_token'
       ↓
ApiService::loginToRemote(email, password) → POST to production /login
       ↓
Response contains { user, token }
       ↓
ApiService::setToken(token) → encrypt → store in session('api_token')
       ↓
Also stores encrypted credentials for auto-refresh:
  session('auth_credentials') = encrypt(json_encode([email, password]))
       ↓
Frontend receives tokens via Inertia shared props (HandleInertiaRequests)
       ↓
Stored in localStorage: 'np_auth_token', 'np_api_token', 'np_persist_login'
```

### 4.3 Token Storage Locations

| Location | Token | Format | Persistence |
|---|---|---|---|
| `personal_access_tokens` table (MySQL) | Auth Token | Hashed (`hash` field, not plaintext) | Permanent (no expiration) |
| Session (PHP) | `session_remember_token` | Encrypted plainTextToken | Until logout |
| Session (PHP) | `api_token` | Encrypted plainTextToken | Until logout |
| Session (PHP) | `auth_credentials` | Encrypted JSON (email+password) | Until logout |
| `storage/app/.api_sync_token` | API Token | Base64-encoded plainTextToken | Until logout |
| localStorage (browser) | `np_auth_token` | PlainTextToken | Until logout/clear |
| localStorage (browser) | `np_api_token` | PlainTextToken | Until logout/clear |
| localStorage (browser) | `np_persist_login` | '1' or null | Until logout/clear |

### 4.4 Token Resolution During Requests

There are **three independent paths** that resolve tokens:

**Path A: Sanctum's auth:sanctum middleware**  
- Scans `Authorization: Bearer` header
- Uses `PersonalAccessToken::findToken()` internally
- Resolves to User model via `tokenable` relationship
- **Known issue**: Intermittently fails for GuzzleHttp requests (SyncEngine)

**Path B: MobileApiAuth middleware**  
- Runs BEFORE `auth:sanctum` on mobile routes
- Manually calls `PersonalAccessToken::findToken()`
- Sets user on sanctum guard with `Auth::guard('sanctum')->setUser()`
- This pre-resolves the token so Sanctum's middleware immediately returns

**Path C: Manual resolution in controllers**  
- 7+ controllers manually call `PersonalAccessToken::findToken($bearerToken)` + `Auth::login()`
- Used by: `WorkspaceController`, `NoteController`, `CategoryController`, `OfflineNoteController`, `Mobile/PatientController`, `Mobile/NoteController`

### 4.5 Token Expiration

- `sanctum.expiration` is set to `null` → **tokens never expire**
- No token rotation mechanism exists
- Tokens are only invalidated on explicit logout (`currentAccessToken()->delete()`)

### 4.6 Token Cleanup

- **Logout (API)**: `AuthController::logout()` → `$user->currentAccessToken()->delete()` (deletes only the current token)
- **Logout (Web)**: `AuthController::logout()` → decrypts remember token → `PersonalAccessToken::findToken($token)?->delete()` + session invalidation
- **No stale token cleanup**: Stale `personal_access_tokens` rows are never purged

### 4.7 Token Race Conditions

**Issue: Token desync between ApiService and MakesApiRequests**

`MakesApiRequests` trait has two token sources:
1. `app(ApiService::class)->getToken()` (preferred)
2. `session('api_token')` (fallback)

These can desync because `ApiService::setToken()` encrypts and stores in session, but if `ApiService` is not properly registered as a singleton (it IS registered in `AppServiceProvider`), duplicated instances could have different token states.

**Issue: Token cleared during sync failure**

The code has explicit guards against clearing the token on 401:
```php
// app/Repositories/Api/Traits/MakesApiRequests.php:153-157
// ── CRITICAL: Do NOT clear the token or session on 401 ─────
// The sync engine has its own retry logic and will re-attempt.
```

But if `AuthenticationException` is thrown and not caught at the right level, the session could be invalidated.

### 4.8 Multi-Device Token Sharing

- Tokens are stored in `personal_access_tokens` (central MySQL production DB)
- Multiple devices can hold the same user's tokens simultaneously
- No device tracking or token binding to device ID
- Logout on one device does NOT invalidate tokens on other devices
- **However**: Logout deletes only the **current** token (`currentAccessToken()->delete()`), so the user must have the SAME token on multiple devices for cross-device logout to work

---

## 5. Web Authentication Flow

### 5.1 Normal Login Flow

```
Browser → GET /login
       ↓
AuthController::showLogin() [app/Http/Controllers/AuthController.php:13]
       ↓
Renders Auth/Login Inertia page
       ↓
User submits email + password
       ↓
POST /login
       ↓
AuthController::login() [app/Http/Controllers/AuthController.php:28]
       ↓
Auth::attempt() → validates credentials
       ↓
session()->regenerate() → new session ID (prevents session fixation)
       ↓
createToken('session-remember') → for WebView session restore
       ↓
ApiService::loginToRemote() → obtains production API token
       ↓
setToken() → stores encrypted api_token in session
       ↓
session('auth_credentials') = encrypt(email+password) → for auto-refresh
       ↓
Redirect to /dashboard or /admin/doctors
```

### 5.2 Session Restore Flow (App Restart)

```
WebView loads embedded Laravel URL
       ↓
localStorage.getItem('np_auth_token') → found!
       ↓
If on /login or /:
       ↓
fetch('POST /api/session/restore', Authorization: Bearer <token>)
       ↓
Route in routes/web.php:14-49
       ↓
bearerToken() = findToken() → resolve User → Auth::login()
       ↓
session()->regenerate()
       ↓
Restore api_token from request body (localStorage 'np_api_token')
       ↓
Redirect to /dashboard
```

### 5.3 Logout Flow

```
POST /logout
       ↓
AuthController::logout() [app/Http/Controllers/AuthController.php:73]
       ↓
Decrypt session('session_remember_token')
       ↓
PersonalAccessToken::findToken($token)?->delete()
       ↓
session()->forget('session_remember_token')
       ↓
Auth::logout() + session()->invalidate() + session()->regenerateToken()
       ↓
Redirect to /login
```

**Critical gap**: Logout does NOT clean up:
- `session('api_token')` — the production API token remains
- `session('auth_credentials')` — encrypted credentials remain
- localStorage tokens (`np_auth_token`, `np_api_token`)
- The disk token file (`storage/app/.api_sync_token`)

---

## 6. Mobile Authentication Flow

### 6.1 Mobile API Login (Native App → Production API)

```
Mobile App → POST /api/v1/login
       ↓
Api\AuthController::login() [app/Http/Controllers/Api/AuthController.php:20]
       ↓
LoginAction::execute() [app/Domains/Auth/Actions/LoginAction.php:15]
       ↓
User::where('email', $email)->first()
       ↓
Hash::check($password, $user->password)
       ↓
$user->createToken('auth_token')->plainTextToken
       ↓
Returns { user, token }
       ↓
Mobile app stores token for subsequent requests
```

### 6.2 Mobile API Request Flow

```
Mobile App → GET /api/v1/mobile/patients (Authorization: Bearer <token>)
       ↓
Route: web.php? No — api.php: Route::prefix('mobile')->middleware(['mobile.auth', 'auth:sanctum'])
       ↓
Middleware 1: MobileApiAuth [app/Http/Middleware/MobileApiAuth.php]
       ↓
500:  $bearerToken = $request->bearerToken()
       ↓
512:  if (!$bearerToken) → pass through (SPA requests with session cookie)
       ↓
518:  $accessToken = PersonalAccessToken::findToken($bearerToken)
       ↓
522:  Auth::guard('sanctum')->setUser($accessToken->tokenable)
       ↓
Middleware 2: auth:sanctum
       ↓
Sees user already set on sanctum guard → returns immediately
       ↓
Controller executes with authenticated user
```

---

## 7. API Authentication Flow

### 7.1 SyncEngine → Production API (Outbound)

```
SyncEngineService::syncAll()
       ↓
Check $this->api->getToken()
       ↓
If no token: skip sync (logged as 'auth_pending')
       ↓
If token exists:
  ApiService::post() / ApiService::upload() / etc.
       ↓
  client(): PendingRequest with withToken($this->token)
       ↓
  Sends HTTP request to production API with Bearer header
       ↓
  If 401: SyncEngine::refreshToken() → ApiService::loginToRemote()
       ↓
  Retry with new token
```

### 7.2 ApiPatientRepository → Production API (Outbound)

```
PatientRepository::paginated()
       ↓
ApiPatientRepository::paginated()
       ↓
MakesApiRequests::apiCall('GET', '/patients', params)
       ↓
Get token from Source 1: ApiService::getToken()
       ↓
If empty: Source 2: session('api_token')
       ↓
Prepare HTTP client with withToken($token)
       ↓
Send request
       ↓
If 401: throw AuthenticationException ('Session expired. Please login again.')
       ↓
PatientRepository catches and falls back to local data
```

---

## 8. Doctor Isolation Architecture

### 8.1 DoctorIsolationScope (Global Scope)

**File:** `app/Domains/Auth/Scopes/DoctorIsolationScope.php`

Applied to these models via `booted()`:
- `Patient` → `static::addGlobalScope(new DoctorIsolationScope)`
- `PatientVisit` → `static::addGlobalScope(new DoctorIsolationScope)`
- `PatientNote` → `static::addGlobalScope(new DoctorIsolationScope)`
- `PatientFile` → `static::addGlobalScope(new DoctorIsolationScope)`

**Scope Logic for `patients` table:**
```php
$q->where('primary_doctor_id', $user->id)
  ->orWhereNull('primary_doctor_id')            // ← SECURITY CONCERN
  ->orWhereIn('id', function ($query) use ($user) {
      $query->select('patient_id')
            ->from('patient_shares')
            ->where('doctor_id', $user->id)
            ->where(function($q2) {
                $q2->whereNull('expires_at')
                    ->orWhere('expires_at', '>', now());
            });
  });
```

**Scope Logic for related tables (files, visits, notes):**
```php
$q->whereHas('patient', function ($q) use ($user) {
    $q->where('primary_doctor_id', $user->id)
      ->orWhereNull('primary_doctor_id')        // ← SECURITY CONCERN
      ->orWhereIn('id', ...shares...);
});
```

### 8.2 CRITICAL: `orWhereNull('primary_doctor_id')` Security Issue

**Evidence:** `DoctorIsolationScope.php` lines 33-38 and 53-57

The scope includes patients with `primary_doctor_id = NULL` in EVERY doctor's view. This means:

- **Any patient created without a `primary_doctor_id` is visible to ALL doctors**
- Patients synced from offline mode where the user wasn't authenticated become visible to everyone
- If a patient's `primary_doctor_id` is ever set to NULL (e.g., through a bug), they become public

The code comment explains this was intentional:
```
// ── NEW: Include patients with null primary_doctor_id
// Patients created via the sync engine without a valid
// Bearer token may have primary_doctor_id = NULL. Without
// this fallback, those patients are invisible to all doctors
// and can never be claimed or edited.
```

**This is a deliberate design compromise that creates a data leakage vulnerability.**

### 8.3 PatientPolicy

**File:** `app/Policies/PatientPolicy.php`

| Method | Logic |
|---|---|
| `view()` | Super-admin/admin → true, primary_doctor → true, shares with valid access_level + not expired → true |
| `create()` | Super-admin/admin/doctor → true |
| `update()` | Super-admin/admin → true, primary_doctor → true, shares with `read_write`/`full` + not expired → true |
| `delete()` | Super-admin/admin → true, primary_doctor → true |
| `share()` | Super-admin/admin → true, primary_doctor → true |

**Gap:** The Policy checks **user ID matches**, while the Global Scope checks **doctor isolation**. If a super-admin disables the global scope (which they can via `withoutGlobalScope()`), the Policy still restricts them. However, the Global Scope applies to ALL queries unless explicitly bypassed.

### 8.4 Tables with `doctor_id` / `primary_doctor_id`

| Table | Column | Isolation Mechanism |
|---|---|---|
| `patients` | `primary_doctor_id` | DoctorIsolationScope + PatientPolicy |
| `patient_visits` | Via `patient_id` → `patients.primary_doctor_id` | DoctorIsolationScope (via `whereHas('patient')`) |
| `patient_notes` | `author_id` (references user, not primary doctor) | DoctorIsolationScope (via `whereHas('patient')`) |
| `patient_files` | Via `patient_id` → `patients.primary_doctor_id` | DoctorIsolationScope (via `whereHas('patient')`) |
| `patient_shares` | `doctor_id`, `shared_by_id` | No Global Scope (queried manually in policies) |
| `users` | N/A | No doctor isolation (all doctors visible) |

### 8.5 Tables WITHOUT Doctor Isolation

The following tables have **no doctor isolation**:
- `personal_access_tokens` — Sanctum tokens table (no scope, but only accessible via Sanctum)
- `cached_categories` — Cached category preferences (per-user but no DB-level isolation)
- `offline_files` — Offline pending uploads (referenced by `patient_uuid` but no isolation scope)
- `file_cache` — Cached file metadata (no isolation scope, but accessed via Gate checks)
- `upload_sessions` — Upload session tracking (no isolation)

### 8.6 Controller-Level Isolation Bypasses

The `Admin\\DoctorController` explicitly disables the DoctorIsolationScope:
```php
$q->withoutGlobalScope(DoctorIsolationScope::class)
    ->where('primary_doctor_id', $doctor->id);
```

This is correct — admin controllers should have full access. But the following controllers also disable the scope:

```php
// app/Http/Controllers/Api/CategoryFileController.php
$patient = Patient::withoutGlobalScope(DoctorIsolationScope::class)->where('uuid', $patientUuid)->first();
$fileQuery = PatientFile::withoutGlobalScope(DoctorIsolationScope::class);
$noteQuery = PatientNote::withoutGlobalScope(DoctorIsolationScope::class);
```

**Evidence:** `app/Http/Controllers/Api/CategoryFileController.php` lines 18, 33, 101

**Impact:** The CategoryFileController deliberately bypasses doctor isolation to access files and notes. If this endpoint is exposed to non-admin users without proper Gate checks, it could leak data.

---

## 9. Patient Architecture

### 9.1 Patient Lifecycle

```
CREATE (Online):
  WorkspaceController::storePatient()
    → PatientRepository::create()
      → ApiPatientRepository::create() (API call)
        → on success: syncSingleToLocal() (cache locally)
        → on failure (offline): save to SQLite with sync_status='pending_create'

CREATE (Offline):
  WorkspaceController::storePatient()
    → PatientRepository::create()
      → ApiPatientRepository::create() fails (ConnectionException)
      → Save to local SQLite with sync_status='pending_create'

UPDATE (Online):
  PatientRepository::update()
    → ApiPatientRepository::update() (API call)
    → syncSingleToLocal() (cache locally)

UPDATE (Offline):
  PatientRepository::update()
    → Api call fails
    → Save locally with sync_status='pending_update'

DELETE:
  PatientRepository::delete()
    → If pending_create: forceDelete() (never sent to server, no remote delete needed)
    → Otherwise: soft delete locally + sync_status='pending_delete'
    → Try API delete (may fail offline, that's OK)

SYNC (Engine):
  SyncEngineService::syncPendingPatients()
    → Atomic claim (pending_create → syncing)
    → Create/Update on remote
    → On success: sync_status='synced'
    → On failure: revert to pending_create
```

### 9.2 Patient Model

**File:** `app/Domains/Patients/Models/Patient.php`

- Uses `SoftDeletes`
- Has `DoctorIsolationScope` global scope
- Auto-generates UUID on creation
- `resolveRouteBinding()` uses `withTrashed()` — allows accessing deleted patients via route binding
- `$fillable` includes `sync_status` — this is set directly by repositories

### 9.3 Patient Repository Layer

```
PatientRepositoryInterface
    ↑
PatientRepository (orchestrator) ←— ApiPatientRepository (API calls)
                                   ←— EloquentPatientRepository (local SQLite)
```

**`PatientRepository::create()` flow:**
1. Try API first (`ApiPatientRepository::create()`)
2. On `ConnectionException` (offline): save locally with `sync_status='pending_create'`
3. On other exceptions: also save locally (fallback)

**NOTE:** `ConnectionException` is caught specifically for offline detection. Other exceptions (e.g., 422 validation) are NOT caught — they bubble up. But `catch (\\Throwable $e)` also catches everything as fallback.

---

## 10. Repository Architecture

### 10.1 Repository Bindings

**File:** `app/Providers/RepositoryServiceProvider.php`

| Interface | Implementation | Phase |
|---|---|---|
| `PatientRepositoryInterface` | `PatientRepository` | Phase 5 |
| `UserRepositoryInterface` | `EloquentUserRepository` | Phase 5 |
| `PatientFileRepositoryInterface` | `EloquentPatientFileRepository` | Phase 5 |
| `PatientNoteRepositoryInterface` | `EloquentPatientNoteRepository` | Phase 5 |
| `PatientVisitRepositoryInterface` | `EloquentPatientVisitRepository` | Phase 5 |
| `FileCacheRepositoryInterface` | `FileCacheRepository` | Phase 6 |
| `OfflineFileRepositoryInterface` | `OfflineFileRepository` | Phase 7 |
| `CategoryRepositoryInterface` | `CategoryRepository` | Phase 8 |

### 10.2 Repository Issues

**`EloquentPatientRepository` still exists but is not bound to an interface.** It's only used directly by `PatientRepository`. This is dead code waiting to be refactored.

**`ApiPatientRepository` is NOT bound to an interface.** It's instantiated directly inside `PatientRepository`. This creates a tight coupling.

**Multiple `Api*` repositories exist for patients, notes, users, and categories but only `ApiPatientRepository` and `ApiUserRepository` are actually used.** `ApiPatientNoteRepository` exists on disk but isn't used by any controller or service.

### 10.3 Dead Repository Files

| File | Status |
|---|---|
| `app/Repositories/Api/ApiPatientNoteRepository.php` | ❌ Exists but never imported/used |
| `app/Repositories/Eloquent/EloquentPatientRepository.php` | ⚠️ Used only by `PatientRepository` constructor, not bound to interface |
| `app/Repositories/Eloquent/EloquentCategoryRepository.php` | ⚠️ Exists but `CategoryRepository` orchestrator is used instead |

---

## 11. Offline/Sync Architecture

### 11.1 Sync Status Values

| Entity | Status Values | Meaning |
|---|---|---|
| Patients | `synced` | Matches production server |
| | `pending_create` | Created locally, needs upload |
| | `pending_update` | Updated locally, needs upload |
| | `pending_delete` | Deleted locally, needs remote deletion |
| | `syncing` | Atomic claim (mid-sync, prevents double-upload) |
| Patient Notes | `synced` | Matches production server |
| | `pending_create` | Created offline, needs upload |
| | `pending_delete` | Deleted offline, needs remote deletion |
| Offline Files | `pending_upload` | Saved locally, needs upload |
| | `uploading` | Atomic claim (mid-upload) |
| | `failed` | Upload failed, retry available |
| | `synced` | Uploaded successfully |

### 11.2 Sync Engine Architecture

**Sync Order (strict):**
```
1. Patients (pending_create, pending_update)
2. Files (pending_upload, failed)
3. Notes (pending_create, pending_delete)
4. Deletes (pending_delete)
```

**Atomic Claim Pattern:**
```php
// Phase 1: Atomically claim by changing status
DB::table('patients')
    ->where('uuid', $patient->uuid)
    ->whereIn('sync_status', ['pending_create', 'pending_update'])
    ->update(['sync_status' => 'syncing']);

// Phase 2: Make API call
$apiData = $this->patientRepo->createOnRemote($data);

// Phase 3a: On success → mark synced
// Phase 3b: On failure → revert to pending_create
```

**Recovery:**
- Stuck `syncing` records (>30 min) are recovered to `pending_create`
- Stuck `uploading` records (>30 min) are recovered to `pending_upload`
- Files with `retry_count >= 10` are skipped

### 11.3 Offline File Upload Flow

```
1. User takes photo/selects file while offline
       ↓
2. POST /_native/api/offline/uploads
       ↓
3. OfflineUploadController::store()
       ↓
4. OfflineUploadService::saveLocally() → saves file to storage
       ↓
5. OfflineFileRepository::create() → INSERT into offline_files (sync_status='pending_upload')
       ↓
6. SyncEngine picks it up when online → uploads to production
```

---

## 12. Runtime Flow Diagrams

### 12.1 Web Login Sequence

```
Browser                  Laravel                    Production API
  │                        │                            │
  │─ POST /login ──────────▶│                            │
  │                        │                            │
  │                        │─ Auth::attempt() ──────────▶│ (if using same DB)
  │                        │◀── success ────────────────│
  │                        │                            │
  │                        │─ createToken('session-remember')
  │                        │─ session()->regenerate()
  │                        │                            │
  │                        │─ POST /login (to production)─▶
  │                        │◀── { token } ──────────────│
  │                        │                            │
  │                        │─ setToken(token)
  │                        │─ encrypt credentials
  │                        │                            │
  │◀── Inertia redirect ───│                            │
  │                        │                            │
  │─ Store tokens in ─────▶│                            │
  │  localStorage          │                            │
```

### 12.2 App Restart Session Restore

```
WebView                  Embedded Laravel              Production API
  │                            │                            │
  │─ Load /login (no session)─▶│                            │
  │                            │                            │
  │─ JS: check localStorage ──▶│                            │
  │─ found np_auth_token       │                            │
  │                            │                            │
  │─ POST /api/session/restore─▶│                            │
  │  Authorization: Bearer     │                            │
  │                            │                            │
  │                            │─ PersonalAccessToken       │
  │                            │  ::findToken()             │
  │                            │                            │
  │                            │─ Auth::login($user)        │
  │                            │─ session()->regenerate()   │
  │                            │                            │
  │                            │─ setToken(api_token)       │
  │                            │                            │
  │◀── { success, user } ─────│                            │
  │                            │                            │
  │─ window.location =         │                            │
  │  /dashboard                │                            │
```

### 12.3 Offline Patient Creation

```
Vue Component            Embedded Laravel (WebView)     SQLite
  │                            │                         │
  │─ POST /api/v1/workspace    │                         │
  │  /patients (via axios)     │                         │
  │  Authorization: Bearer     │                         │
  │                            │                         │
  │  ── NO auth middleware ──▶ │                         │
  │                            │                         │
  │  WorkspaceController::     │                         │
  │  storePatient()            │                         │
  │                            │                         │
  │  $request->user() = null   │                         │
  │  (no session, no auth)     │                         │
  │                            │                         │
  │  Try Bearer token:         │                         │
  │  findToken() → succeeds!   │                         │
  │  Auth::login($user)        │                         │
  │                            │                         │
  │  $validated = {...}        │                         │
  │  primary_doctor_id = $user->id                       │
  │                            │                         │
  │  PatientRepository::create()                         │
  │  → try API first (fails,                              │
  │    ConnectionException)    │                         │
  │                            │                         │
  │  → save locally            │──── INSERT ─────────────▶│
  │    sync_status =           │                         │
  │    'pending_create'        │                         │
  │                            │                         │
  │◀── { patient } ───────────│                         │
```

### 12.4 Sync Engine Cycle

```
Embedded Laravel                 Production API
      │                                │
      │  SyncEngineService::syncAll()  │
      │                                │
      │  ── Check API token ──────────▶│ (no request, just memory)
      │  Token available? Yes          │
      │                                │
      │  ── STEP 1: Patients ─────────▶│
      │  POST /patients (create)        │
      │  PUT /patients/{uuid} (update)  │
      │◀── { uuid, ... } ─────────────│
      │                                │
      │  Update local: synced          │
      │                                │
      │  ── STEP 2: Files ────────────▶│
      │  POST /patients/{uuid}/files   │
      │  (multipart upload)            │
      │◀── { uuid } ─────────────────│
      │                                │
      │  Update local: synced          │
      │  Delete local file             │
      │                                │
      │  ── STEP 3: Notes ────────────▶│
      │  POST /patients/{uuid}/notes   │
      │◀── { uuid } ─────────────────│
      │                                │
      │  ── STEP 4: Deletes ──────────▶│
      │  DELETE /patients/{uuid}       │
      │◀── 200 ──────────────────────│
      │                                │
      │  Force delete local record     │
```

---

## 13. All Authentication Issues

### 13.1 AUTH-001: Duplicate Authentication Paths Desync

**Severity:** HIGH  
**Area:** Token Management  
**Files:** `ApiService.php`, `MakesApiRequests.php`  

**Description:** There are two independent paths for obtaining the production API token:
1. `ApiService::getToken()` — singleton, reads `$this->token` (set in constructor from `session('api_token')`)
2. `MakesApiRequests::apiCall()` — reads `session('api_token')` directly as fallback

If these desync (e.g., one is set but not the other), API requests will intermittently fail with 401.

**Evidence:** `app/Repositories/Api/Traits/MakesApiRequests.php` lines 48-92 show the dual-source token resolution.

### 13.2 AUTH-002: Manual Bearer Token Resolution in 7+ Locations

**Severity:** MEDIUM  
**Area:** Code Duplication  
**Files:** `WorkspaceController.php`, `NoteController.php` (2x), `OfflineNoteController.php`, `Mobile/PatientController.php`, `Mobile/NoteController.php`, `CategoryController.php`

**Description:** The same pattern (check Bearer token → `PersonalAccessToken::findToken()` → `Auth::login()`) is duplicated in at least 7 controllers. This is fragile — any change to the flow must be made in all locations.

**Evidence:** Each file has nearly identical Bearer resolution code.

### 13.3 AUTH-003: MobileApiAuth Workaround for Sanctum Bug

**Severity:** MEDIUM  
**Area:** Authentication Middleware  
**File:** `app/Http/Middleware/MobileApiAuth.php`

**Description:** The `MobileApiAuth` middleware exists to work around an intermittent Sanctum bug where `auth:sanctum` fails to resolve Bearer tokens sent via GuzzleHttp. This middleware pre-resolves the token by manually calling `PersonalAccessToken::findToken()` and setting the user on the sanctum guard.

**Evidence:** The file's docblock (lines 13-32) explicitly describes this as a workaround for a Sanctum bug.

### 13.4 AUTH-004: CSRF Exemption for All API Routes

**Severity:** MEDIUM  
**Area:** Security  
**File:** `bootstrap/app.php`

**Description:** All `/api/v1/*` and `/_native/*` routes have CSRF protection disabled. While this is necessary for offline operation, it means there's no CSRF protection on any API endpoint if an attacker gains JavaScript execution in the WebView.

**Evidence:** `bootstrap/app.php` lines 32-41.

### 13.5 AUTH-005: Inconsistent 401 Handling

**Severity:** MEDIUM  
**Area:** Error Handling  
**Files:** `MakesApiRequests.php`, `ApiService.php`, `SyncEngineService.php`

**Description:** Different parts of the code handle 401 differently:
- `MakesApiRequests`: throws `AuthenticationException`
- `ApiService.send()`: throws `RuntimeException('Session expired.')`
- `ApiService.upload()`: throws `RuntimeException('Session expired.')`
- `SyncEngineService`: catches `AuthenticationException` and calls `refreshToken()`

This inconsistency makes error handling fragile and hard to trace.

### 13.6 AUTH-006: Credentials Stored in Session

**Severity:** HIGH  
**Area:** Security  
**File:** `app/Http/Controllers/AuthController.php`

**Description:** User credentials (email + password) are encrypted and stored in the session for auto-refresh:
```php
session(['auth_credentials' => encrypt(json_encode([
    'email' => $credentials['email'],
    'password' => $credentials['password'],
]))]);
```

While encrypted with APP_KEY, if the APP_KEY is compromised, all stored credentials can be decrypted.

---

## 14. All Token Issues

### 14.1 TOKEN-001: Tokens Never Expire

**Severity:** MEDIUM  
**Area:** Security  
**File:** `config/sanctum.php`

**Description:** `'expiration' => null` means Sanctum tokens never expire. Once a token is issued, it remains valid forever unless explicitly deleted. This means:
- A stolen token is valid indefinitely
- No forced re-authentication
- No automatic cleanup of old tokens

### 14.2 TOKEN-002: No Token Rotation

**Severity:** LOW  
**Area:** Security  
**Files:** `LoginAction.php`, `AuthController.php`

**Description:** Every new login creates a NEW token but does NOT delete old tokens. This means:
- Token accumulation over time
- Old tokens remain valid even after password change
- Multiple valid tokens can exist simultaneously

### 14.3 TOKEN-003: Token Not Cleaned on Logout

**Severity:** MEDIUM  
**Area:** Session Cleanup  
**File:** `app/Http/Controllers/AuthController.php`

**Description:** Web logout only deletes the `session-remember` token. It does NOT clean up:
- `session('api_token')` — the production API token
- `session('auth_credentials')` — encrypted credentials
- The disk token file

**Evidence:** `app/Http/Controllers/AuthController.php` lines 73-91. After logout, re-logging-in reuses old session data.

### 14.4 TOKEN-004: Token Written to Disk Unencrypted

**Severity:** HIGH  
**Area:** Security  
**File:** `app/Services/Mobile/ApiService.php`

**Description:** The production API token is written to `storage/app/.api_sync_token` as base64-encoded (NOT encrypted):
```php
file_put_contents($path, base64_encode($token), LOCK_EX);
```

Base64 is NOT encryption — it's encoding. Any app with filesystem access can read the token.

**Evidence:** `app/Services/Mobile/ApiService.php` lines 145-157.

### 14.5 TOKEN-005: Token Stored in localStorage Unencrypted

**Severity:** MEDIUM  
**Area:** Security  
**File:** `resources/views/app.blade.php`

**Description:** Both `np_auth_token` (Sanctum remember token) and `np_api_token` (production API token) are stored in plaintext in localStorage. Any JavaScript running in the WebView context can access them.

**Evidence:** `resources/views/app.blade.php` lines 36-37:
```javascript
var authToken = localStorage.getItem('np_auth_token');
var apiToken = localStorage.getItem('np_api_token');
```

---

## 15. All Security Issues

### 15.1 SEC-001: Doctor Data Leakage via orWhereNull

**Severity:** CRITICAL  
**Area:** Doctor Isolation  
**File:** `app/Domains/Auth/Scopes/DoctorIsolationScope.php`

**Description:** The DoctorIsolationScope includes `orWhereNull('primary_doctor_id')`, making ANY patient without a primary doctor visible to ALL doctors. This includes:
- Patients created offline without authentication
- Patients where `primary_doctor_id` was accidentally set to NULL
- Future patients that fail to assign a doctor

**Evidence:** `DoctorIsolationScope.php` lines 32-38.

### 15.2 SEC-002: CategoryFileController Bypasses Isolation

**Severity:** HIGH  
**Area:** Access Control  
**File:** `app/Http/Controllers/Api/CategoryFileController.php`

**Description:** The controller explicitly disables DoctorIsolationScope for loading patients, files, and notes. If this endpoint is reachable without proper Gate checks, it can leak data across doctors.

**Evidence:** Lines 18, 33, 101.

### 15.3 SEC-003: resolvePatient() Creates Patients Without Doctor

**Severity:** HIGH  
**Area:** Data Integrity  
**Files:** `NoteController.php`, `Mobile/NoteController.php`, `OfflineNoteController.php`

**Description:** The `resolvePatient()` method (duplicated in 3 controllers) creates a patient record if one doesn't exist locally, with `sync_status = 'pending_sync'` and `name = 'Patient (uuid)'`. These patients have NO `primary_doctor_id`, making them visible to ALL doctors via SEC-001.

**Evidence:** `app/Http/Controllers/Api/NoteController.php` lines 163-177, duplicated in `Mobile/NoteController.php` and `OfflineNoteController.php`.

### 15.4 SEC-004: Offline Controllers Skip Gate Checks

**Severity:** MEDIUM  
**Area:** Access Control  
**Files:** `OfflineUploadController.php`, `OfflineNoteController.php`

**Description:** When no authenticated user is available, offline controllers gracefully skip Gate authorization:
```php
try {
    Gate::authorize('update', $patient);
} catch (Throwable $e) {
    Log::warning('Gate authorization failed, continuing (local-only file)');
}
```

While this is necessary for offline operation, it means offline created data has no access control.

### 15.5 SEC-005: PersonalAccessToken Queried Directly

**Severity:** LOW  
**Area:** Best Practice  
**Files:** Multiple controllers

**Description:** `PersonalAccessToken::findToken()` is called directly instead of using Sanctum's built-in authentication. This bypasses Sanctum's internal checks (ability checks, expiration checks in future).

---

## 16. All Doctor Isolation Issues

### 16.1 ISO-001: PatientShare Has No Global Scope

**Severity:** MEDIUM  
**Area:** Data Isolation  
**File:** `app/Domains/Patients/Models/PatientShare.php`

**Description:** `PatientShare` model has no `DoctorIsolationScope`. While it's usually queried in context of a specific patient (which IS isolated), direct queries on `PatientShare::all()` would return ALL shares across all doctors.

### 16.2 ISO-002: Shared Patients Bypass Doctor Check

**Severity:** MEDIUM  
**Area:** Data Isolation  
**File:** `app/Domains/Auth/Scopes/DoctorIsolationScope.php`

**Description:** The scope includes shared patients via `patient_shares` table. While this is intentional, if a share is not properly cleaned up (e.g., `expires_at` not set), a doctor retains access indefinitely.

### 16.3 ISO-003: Note Ownership Checked Instead of Patient

**Severity:** MEDIUM  
**Area:** Access Control  
**Files:** `Mobile/NoteController.php`, `Api/NoteController.php`

**Description:** Note controllers check `$note->author_id !== $request->user()->id` for update/delete. This means:
- A doctor can edit a note on a shared patient ONLY if they authored it
- The primary doctor who OWNS the patient cannot edit another doctor's notes on their own patient

**Evidence:** `app/Http/Controllers/Api/Mobile/NoteController.php` lines 79, 101.

---

## 17. All Offline Issues

### 17.1 OFFLINE-001: No Conflict Resolution

**Severity:** HIGH  
**Area:** Data Consistency  
**Files:** `SyncEngineService.php`, `PatientRepository.php`

**Description:** When the same patient is modified both locally (offline) and remotely (by another doctor), there is no conflict resolution strategy. The sync engine simply overwrites local data with the server response.

**Evidence:** `syncSingleToLocal($data, force: true)` overwrites everything.

### 17.2 OFFLINE-002: Offline-Created Patients Visible to All

**Severity:** HIGH  
**Area:** Data Isolation  
**File:** `app/Http/Controllers/WorkspaceController.php`

**Description:** When creating a patient offline without authentication, `primary_doctor_id` is set to `null`. Combined with SEC-001, this patient becomes visible to ALL doctors until synced.

**Evidence:** `WorkspaceController.php` lines 184-188:
```php
$validated['primary_doctor_id'] = null;
$validated['created_by_id'] = null;
```

### 17.3 OFFLINE-003: Notes Created Offline Without User

**Severity:** MEDIUM  
**Area:** Data Integrity  
**File:** `app/Http/Controllers/Api/NoteController.php`

**Description:** When creating a note offline without a user, the controller falls back to `User::query()->first()` as the author — picking the FIRST user in the database, regardless of who actually created the note.

**Evidence:** `app/Http/Controllers/Api/NoteController.php` lines 78-83.

### 17.4 OFFLINE-004: Offline Uploads Not Garbage Collected

**Severity:** LOW  
**Area:** Storage  
**File:** `app/Repositories/OfflineFileRepository.php`

**Description:** Files that are saved offline but fail to upload (and exceed retry limits) remain on disk indefinitely. No cleanup mechanism exists for orphaned files.

---

## 18. All Sync Issues

### 18.1 SYNC-001: Sync Engine Requires Authenticated Session

**Severity:** MEDIUM  
**Area:** Sync Reliability  
**File:** `app/Services/SyncEngineService.php`

**Description:** The sync engine checks for API token before starting. If the session was restored but the API token wasn't (race condition), sync is skipped. This creates a catch-22 where the user must navigate to the workspace to trigger token restoration before sync can run.

**Evidence:** `SyncEngineService.php` lines 84-95.

### 18.2 SYNC-002: Remote UUID Mismatch Can Corrupt Relations

**Severity:** HIGH  
**Area:** Data Integrity  
**File:** `app/Services/SyncEngineService.php`

**Description:** When the remote API assigns a different UUID (lines 252-254), the engine updates the local UUID and then tries to update `offline_files.patient_uuid`. However, other tables that reference patients by UUID (like `file_cache`) are NOT updated.

**Evidence:** `SyncEngineService.php` lines 304-321: Only updates `offline_files`, not other UUID-referencing tables.

### 18.3 SYNC-003: Note Sync Doesn't Flag Duplicate Uploads

**Severity:** MEDIUM  
**Area:** Data Integrity  
**File:** `app/Services/SyncEngineService.php`

**Description:** The sync engine uploads notes without checking if they were already uploaded in a previous sync cycle. If the sync engine crashes before marking the note as `synced`, the same note could be uploaded again on the next cycle.

**Evidence:** `syncPendingNotes()` lines 438-466 — no idempotency check.

### 18.4 SYNC-004: Token Refresh Uses Stored Credentials

**Severity:** MEDIUM  
**Area:** Security  
**File:** `app/Services/SyncEngineService.php`

**Description:** The auto-refresh mechanism (lines 157-187) decrypts stored credentials and re-logs in to the production server. If:
1. The user changed their password on the production server
2. The stored credentials become invalid
3. The refresh fails silently

...the sync engine permanently fails until the user re-logs in from the app.

---

## 19. Hidden Bugs

### 19.1 BUG-001: PatientPolicy Ignores `viewAny` for Notes/Files

**Severity:** MEDIUM  
**Area:** Authorization  

**Description:** `PatientPolicy::viewAny()` only checks user roles. It does NOT check doctor isolation. Since `viewAny()` is used by `Gate::authorize('view', $patient)` internally (Laravel checks viewAny before view), this could allow access to patients that should be restricted.

### 19.2 BUG-002: `forceDelete()` on Pending Creates Bypasses Remote

**Severity:** LOW  
**Area:** Data Consistency  
**File:** `app/Repositories/PatientRepository.php`

**Description:** When deleting a `pending_create` patient (lines 227-229), the repository calls `forceDelete()` directly without first checking if the patient was somehow already created remotely. If the patient was created remotely between the offline save and the delete, the remote record becomes an orphan.

### 19.3 BUG-003: OfflineNoteController Creates Duplicate Notes on Resync

**Severity:** MEDIUM  
**Area:** Data Integrity  
**File:** `app/Http/Controllers/Api/OfflineNoteController.php`

**Description:** The OfflineNoteController creates notes with `sync_status = 'pending_create'`. The SyncEngineService then uploads these notes via the API. However, there's no mechanism to prevent the SAME note from being created on the production server MULTIPLE times if the sync engine is triggered multiple times before marking as synced.

### 19.4 BUG-004: `resolvePatient()` Race Condition

**Severity:** MEDIUM  
**Area:** Data Integrity  
**Files:** `NoteController.php`, `Mobile/NoteController.php`, `OfflineNoteController.php`

**Description:** `resolvePatient()` first checks local DB, then tries API, then creates a stub. If two concurrent requests hit this for the same UUID, they could both try to create the stub — one would succeed, the other would fail with a duplicate key error.

**Evidence:** The `updateOrCreate` pattern helps but there's no locking.

### 19.5 BUG-005: SyncEngine UUID Update Skips `file_cache` Table

**Severity:** HIGH  
**Area:** Data Integrity  
**File:** `app/Services/SyncEngineService.php`

**Description:** When the remote API assigns a different UUID, the sync engine updates the `patients` table and `offline_files` table but NOT the `file_cache` table. This means cached files reference a UUID that no longer matches the patient record.

**Evidence:** Lines 304-323: Only updates `patients` + `offline_files`.

---

## 20. Technical Debt

### 20.1 Dead Code

| File | Reason |
|---|---|
| `app/Models/RefreshToken.php` | Never instantiated |
| `app/Models/ApiToken.php` | Never instantiated |
| `app/Models/PasswordResetToken.php` | Never instantiated |
| `app/Models/VerificationToken.php` | Never instantiated |
| `app/Models/AuthToken.php` | Never instantiated |
| `app/Models/TokenLog.php` | Never instantiated |
| `app/Repositories/Api/ApiPatientNoteRepository.php` | Never imported |
| `app/Repositories/Eloquent/EloquentCategoryRepository.php` | Bound but orchestrator used instead |
| `app/Repositories/Eloquent/EloquentPatientRepository.php` | Not bound to interface, used directly |

### 20.2 Duplicate Code

| Pattern | Location Count |
|---|---|
| `PersonalAccessToken::findToken()` + `Auth::login()` | 7+ controllers |
| `resolvePatient()` method | 3 controllers (identical logic) |
| Token resolution from ApiService + session | 2 places (ApiService + MakesApiRequests) |
| `syncPending()` frontend sync trigger | 2 routes (POST /patients + POST /all) |

### 20.3 Debug Artifacts

| File | Issue |
|---|---|
| `WorkspaceController.php` | Heavy `@file_put_contents()` debug tracing (lines 110, 134, 144, 152, 174, 186, 190) |
| `OfflineUploadController.php` | Debug file tracing (`F5`, `F5f`, `F6c`, `F6d` markers) |
| `resources/views/welcome.blade.php` | Contains full SVG path data (101KB+ of SVGs) — should be minified |

### 20.4 Configuration Issues

| Issue | File |
|---|---|
| `APP_ENV=production` in `.env` | Hardcoded production — prevents dev-specific behavior |
| `APP_DEBUG=false` | No debug mode available |
| Frontend locale files have temp file: `en_temp.json` | Should be cleaned up |
| `.npmrc` exists but package.json is not checked | Possible mismatch |

---

## 21. Risk Assessment

### CRITICAL

| # | Issue | Area | Impact |
|---|---|---|---|
| SEC-001 | `orWhereNull('primary_doctor_id')` leaks unowned patients to all doctors | Security | Patient data visible to unauthorized doctors |
| SEC-002 | CategoryFileController disables DoctorIsolationScope | Security | Potential data leakage across doctors |
| SEC-003 | `resolvePatient()` creates patients without doctor | Security | All doctors can see these stub patients |

### HIGH

| # | Issue | Area | Impact |
|---|---|---|---|
| AUTH-001 | Duplicate authentication paths can desync | Auth | Intermittent 401 errors on API calls |
| AUTH-006 | Credentials stored encrypted in session | Security | Compromise if APP_KEY is leaked |
| TOKEN-004 | Token written to disk as base64 (not encrypted) | Security | Token exposed to filesystem access |
| OFFLINE-001 | No conflict resolution for offline edits | Data | Silently overwrites remote changes |
| OFFLINE-002 | Offline patients created without doctor | Isolation | Visible to all doctors until synced |
| SYNC-002 | UUID mismatch corrupts `file_cache` references | Data | File cache references wrong patient UUID |
| BUG-005 | SyncEngine skips `file_cache` on UUID update | Data | Orphaned file cache entries |

### MEDIUM

| # | Issue | Area | Impact |
|---|---|---|---|
| AUTH-002 | Manual Bearer resolution in 7+ places | Debt | Fragile, hard to maintain |
| AUTH-003 | MobileApiAuth works around Sanctum bug | Auth | Fragile workaround |
| AUTH-004 | CSRF exemption on all API routes | Security | No CSRF protection for API |
| AUTH-005 | Inconsistent 401 handling | Auth | Hard to debug auth failures |
| TOKEN-001 | Tokens never expire | Security | Stolen tokens valid indefinitely |
| TOKEN-003 | Token not cleaned on logout | Security | Stale tokens in session |
| TOKEN-005 | Token in localStorage unencrypted | Security | XSS can steal tokens |
| ISO-001 | PatientShare has no global scope | Isolation | Direct queries expose all shares |
| OFFLINE-003 | Notes use fallback author (first DB user) | Data | Wrong authorship attribution |
| SYNC-001 | Sync requires API token, skip-able race | Sync | Sync may silently not run |
| SYNC-003 | Note sync not idempotent | Sync | Duplicate note uploads possible |
| BUG-003 | Offline notes can be uploaded twice | Data | Duplicate notes on production |
| BUG-004 | `resolvePatient()` race condition | Data | Possible duplicate key errors |

### LOW

| # | Issue | Area | Impact |
|---|---|---|---|
| TOKEN-002 | No token rotation | Security | Token accumulation |
| SEC-005 | PersonalAccessToken queried directly | Best Practice | Bypasses Sanctum internals |
| OFFLINE-004 | Orphaned offline uploads not cleaned | Storage | Disk space waste |
| BUG-002 | Pending create delete orphans remote | Data | Orphaned remote records |

---

## 22. Prioritized Fix Roadmap

### Phase A — Critical Security (Do First)

1. **Fix `orWhereNull('primary_doctor_id')`** — Replace `orWhereNull` with a claim/assign mechanism for unowned patients. Option: show them only to super-admins, or assign them to the first doctor who accesses them with a confirmation dialog.

2. **Audit `CategoryFileController`** — Ensure all paths that disable `DoctorIsolationScope` are protected by proper `Gate::authorize()` checks.

3. **Fix `resolvePatient()` patient creation** — Require a `primary_doctor_id` when creating stub patients, or use a dedicated 'unclaimed' status that only super-admins can see.

### Phase B — Authentication Consolidation

4. **Unify token source** — Eliminate the dual-source pattern in `MakesApiRequests`. Use only `ApiService::getToken()` (singleton).

5. **Extract Bearer resolution helper** — Create a single `resolveBearerToken()` helper to replace the 7+ duplicated implementations.

6. **Fix token cleanup on logout** — Clear `session('api_token')`, `session('auth_credentials')`, and the disk token file during logout.

### Phase C — Token Security

7. **Encrypt disk token file** — Replace `base64_encode` with proper encryption using Laravel's `Crypt::encryptString()`.

8. **Add token expiration** — Set `sanctum.expiration` to a reasonable value (e.g., 30 days) and implement refresh logic.

9. **Add token rotation** — Delete old tokens on new login (preserve only the last N tokens per user).

### Phase D — Sync Engine Hardening

10. **Fix UUID sync gap** — Update `file_cache` table when remote UUID changes.

11. **Add sync idempotency** — Check for existing remote notes before uploading (use client-side UUID as idempotency key).

12. **Implement conflict resolution** — Compare `updated_at` timestamps and use last-writer-wins or three-way merge for offline conflicts.

### Phase E — Code Quality

13. **Remove dead code** — Delete unused models (RefreshToken, ApiToken, etc.) and repository files.

14. **Unify note controllers** — Merge `NoteController`, `Mobile/NoteController`, and `OfflineNoteController` into a single controller.

15. **Remove debug artifacts** — Clean up `@file_put_contents()` debug tracing, `file_put_contents` calls to `/data/local/tmp/`.

16. **Delete unused locale temp file** — Remove `resources/js/Locales/en_temp.json`.

---

## 23. Appendix

### 23.1 Key Files Referenced

| File | Purpose |
|---|---|
| `app/Http/Controllers/AuthController.php` | Web login/logout (session-based) |
| `app/Http/Controllers/Api/AuthController.php` | API login/logout (token-based) |
| `app/Domains/Auth/Actions/LoginAction.php` | Token creation logic |
| `app/Http/Middleware/MobileApiAuth.php` | Bearer token pre-resolution middleware |
| `app/Domains/Auth/Scopes/DoctorIsolationScope.php` | Global doctor isolation scope |
| `app/Policies/PatientPolicy.php` | Patient authorization policy |
| `app/Http/Controllers/WorkspaceController.php` | Main workspace (patient CRUD) |
| `app/Repositories/PatientRepository.php` | Patient orchestrator repo |
| `app/Repositories/Api/ApiPatientRepository.php` | Remote API patient repo |
| `app/Repositories/Api/Traits/MakesApiRequests.php` | HTTP client with dual token sources |
| `app/Services/Mobile/ApiService.php` | Production API HTTP client + token manager |
| `app/Services/SyncEngineService.php` | Offline sync orchestrator |
| `routes/api.php` | API route definitions |
| `routes/web.php` | Web + native route definitions |
| `bootstrap/app.php` | App bootstrap, middleware config, CSRF exemptions |
| `config/sanctum.php` | Sanctum configuration (expiration, domains) |
| `config/auth.php` | Auth guard configuration |
| `config/app.php` | App config (mobile_api_url) |

### 23.2 All Controllers with Manual Bearer Resolution

1. `app/Http/Controllers/WorkspaceController.php` — lines 136-147
2. `app/Http/Controllers/Api/NoteController.php` — lines 30-39
3. `app/Http/Controllers/Api/CategoryController.php` — lines 20-28
4. `app/Http/Controllers/Api/OfflineNoteController.php` — lines 55-64
5. `app/Http/Controllers/Api/Mobile/PatientController.php` — lines 162-192
6. `app/Http/Controllers/Api/Mobile/NoteController.php` — lines 42-44
7. `app/Http/Middleware/MobileApiAuth.php` — lines 42-80

### 23.3 All Sync Status Values and Locations

| Status | Used In |
|---|---|
| `synced` | `patients`, `patient_notes` |
| `pending_create` | `patients`, `patient_notes` |
| `pending_update` | `patients` |
| `pending_delete` | `patients`, `patient_notes` |
| `syncing` | `patients` (atomic claim) |
| `pending_upload` | `offline_files` |
| `uploading` | `offline_files` |
| `failed` | `offline_files` |

### 23.4 Route Groups Summary

| Route Prefix | Middleware | CSRF | Auth |
|---|---|---|---|
| `/login`, `/logout` | `web` | ✅ Protected | ❌ Public |
| `/dashboard`, `/workspace`, `/patients`, `/settings`, `/admin` | `auth` | ✅ Protected | ✅ Required |
| `/api/v1/*` (web.php) | None | ❌ Exempt | Controller-level |
| `/_native/*` (web.php) | None | ❌ Exempt | Controller-level |
| `/api/v1/login` (api.php) | `api` | N/A | ❌ Public |
| `/api/v1/*` (api.php, auth:sanctum) | `auth:sanctum` | N/A | ✅ Required |
| `/api/v1/mobile/*` (api.php) | `mobile.auth` + `auth:sanctum` | N/A | ✅ Required |

### 23.5 Database Tables

| Table | Engine | Purpose |
|---|---|---|
| `users` | MySQL/SQLite | User accounts |
| `personal_access_tokens` | MySQL | Sanctum tokens |
| `patients` | MySQL/SQLite | Patient records (sync_status) |
| `patient_files` | MySQL/SQLite | Patient files |
| `patient_notes` | MySQL/SQLite | Patient notes (sync_status) |
| `patient_visits` | MySQL/SQLite | Patient visits |
| `patient_shares` | MySQL/SQLite | Inter-doctor sharing |
| `offline_files` | SQLite only | Pending offline uploads |
| `file_cache` | SQLite only | Cached file metadata |
| `cached_categories` | SQLite only | Category cache |
| `upload_sessions` | MySQL/SQLite | Chunked upload sessions |
| `upload_chunk_receipts` | MySQL/SQLite | Upload chunk tracking |
| `sync_meta` | SQLite only | Legacy sync status (from Phase 4) |

---

## End of Report
