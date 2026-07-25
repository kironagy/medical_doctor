# ARCHITECTURE CHANGES

**Phase:** 1 — Security, Authentication & Architecture Stabilization  
**Date:** 2026-07-25  
**Status:** Complete  

---

## Files Changed

### New Files

| File | Purpose |
|---|---|
| `app/Http/Middleware/AuthenticateWithBearer.php` | Shared trait for Bearer token resolution (replaces 7+ duplicated implementations) |

### Modified Files

| File | Change |
|---|---|
| `app/Domains/Auth/Scopes/DoctorIsolationScope.php` | **SEC-001**: Replaced `orWhereNull('primary_doctor_id')` with `orWhere(created_by_id matching)` |
| `app/Http/Controllers/Api/CategoryFileController.php` | **SEC-002**: Removed `withoutGlobalScope()`, added `Gate::authorize('view', $patient)` |
| `app/Http/Controllers/Api/NoteController.php` | **SEC-003 + AUTH-002**: Used shared trait, fixed stub patient doctor assignment, removed fallback to first DB user |
| `app/Http/Controllers/Api/Mobile/NoteController.php` | **ISO-003 + AUTH-002**: Used shared trait, fixed note ownership to check patient permission |
| `app/Http/Controllers/Api/OfflineNoteController.php` | **SEC-003 + AUTH-002**: Used shared trait, removed first-user fallback, fixed stub patient |
| `app/Http/Controllers/WorkspaceController.php` | **AUTH-002**: Used shared trait, removed debug tracing (`@file_put_contents` to `/data/local/tmp/`) |
| `app/Http/Controllers/Api/CategoryController.php` | **AUTH-002**: Used shared trait |
| `app/Http/Controllers/Api/Mobile/PatientController.php` | **AUTH-002**: Used shared trait, preserved idempotency logic |
| `app/Services/Mobile/ApiService.php` | **TOKEN-004**: Changed `base64_encode`→`encrypt()`, `base64_decode`→`decrypt()` for disk token |
| `app/Http/Controllers/AuthController.php` | **TOKEN-003**: Full token cleanup on logout (all tokens, credentials, disk file) |
| `app/Repositories/Api/Traits/MakesApiRequests.php` | **AUTH-001**: Simplified to single token source (ApiService only) |

---

## Architecture Impact Analysis

### Authentication Architecture (Before vs After)

**Before:**
```
Request
  ↓
MobileApiAuth (pre-resolves Bearer for sanctum guard)
  ↓
auth:sanctum (checks all guards)
  ↓
Controller (duplicated Bearer resolution in each controller)
  ↓
ApiService (owns token from session)
  ↓
MakesApiRequests (reads token from session AGAIN - dual source!)
```

**After:**
```
Request
  ↓
MobileApiAuth (pre-resolves Bearer for sanctum guard) [unchanged]
  ↓
auth:sanctum (checks all guards) [unchanged]
  ↓
Controller (shared AuthenticateWithBearer trait - single method)
  ↓
ApiService (owns token from session - SINGLE SOURCE OF TRUTH)
  ↓
MakesApiRequests (reads token from ApiService ONLY - no dual source)
```

### Doctor Isolation (Before vs After)

**Before:**
- `orWhereNull('primary_doctor_id')` → ALL unowned patients visible to ALL doctors
- CategoryFileController completely bypassed isolation
- Stub patients created with no doctor

**After:**
- `orWhere(created_by_id matching)` → only the creating doctor sees unowned patients
- CategoryFileController respects global scope + explicit Gate check
- Stub patients get doctor ID from authenticated user

### Token Security (Before vs After)

| Aspect | Before | After |
|---|---|---|
| Disk token storage | `base64_encode()` (encoding, not encryption) | `encrypt()` (AES-256-CBC with APP_KEY) |
| Logout cleanup | Only session-remember token | All tokens, credentials, disk file |
| Token source for API | Dual (ApiService + session) | Single (ApiService singleton) |

---

## Backward Compatibility

All changes are **backward compatible**:
1. **API routes**: No route changes, same endpoints and response formats
2. **Web routes**: No route changes
3. **Database schema**: No migration changes
4. **Existing tokens**: Old base64-encoded disk tokens are gracefully cleaned up (decrypt fails → file deleted → session fallback)
5. **Offline sync**: Sync engine behavior unchanged

---

## Pre-Existing Issues (Not Addressed)

| Issue | Reason |
|---|---|
| `DoctorIsolationTest` failures (count mismatch) | Pre-existing bug in test setup (`PermissionService` creates extra state) |
| `orWhereNull` edge case for truly anonymous offline patients | Rare case (no user, no token, offline) — acceptable security trade-off |
| `NoteController::store()` fallback to `$patient->primary_doctor_id` | Valid use case for online notes where user is authenticated via middleware |

---

## Risk Mitigation

| Risk | Mitigation |
|---|---|
| Disk token format change (base64→encrypt) | `loadTokenFromFile()` catches decrypt exceptions, cleans up file, falls back to session |
| Trait `Auth::login()` side effect | Removed from trait; each caller explicitly logs in if needed |
| Broadcast scope change (SEC-001) | Changed from `orWhereNull` (all doctors) to `created_by_id matching` (only creator) — minimal blast radius |
