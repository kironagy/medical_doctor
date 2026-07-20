# Root Cause Analysis - Patient Display Failure

## ROOT CAUSE #1: SQLite Sync Fails Due to FK Constraint + DoctorIsolationScope
**Severity: CRITICAL - This is why 0 patients appear in the app**

### The Chain of Failures

1. NativePHP starts → AppServiceProvider::scheduleStartupSync() → FullSyncService::syncAll()
2. FullSyncService::syncAll() calls patient sync FIRST, then doctor sync LAST
3. Patients table has FK: `primary_doctor_id` references `users(id)` + `created_by_id` references `users(id)`
4. At sync time, users table is EMPTY (doctors() called LAST)
5. updateOrCreate fails → exception caught → ALL PATIENTS SKIPPED
6. Result: SQLite has 0 patients, 0 users, 0 everything

### The Code Path
`app/Services/FullSyncService.php:73` - `$patients = $this->apiPatientRepo->all()`  
`app/Services/FullSyncService.php:74` - `$this->syncLocalCache($patients, Patient::class)`  
`app/Services/FullSyncService.php:137` - `$this->userRepo->doctors()` ← called AFTER patients!

### FIX
- Move user sync BEFORE patient sync in FullSyncService::syncAll()
- Also need to disable FK constraints during the bulk sync, OR create users first in a separate step

---

## ROOT CAUSE #2: DoctorIsolationScope Filters API-pulled Data in Web Context
**Severity: HIGH - Even after fixing ordering, sync still won't work**

### The Problem
- `Patient::updateOrCreate()` is called inside FullSyncService
- But `Patient` model has `DoctorIsolationScope` as a GLOBAL SCOPE
- DoctorIsolationScope applies `where(primary_doctor_id = auth()->id())` to ALL queries
- When run in authenticated web context, this BIASES the updateOrCreate to only match patients for the current doctor
- In NativePHP context, if there IS a web session, auth()->id() returns a real user ID

### The Code Path
`app/Domains/Patients/Models/Patient.php:29` - `static::addGlobalScope(new DoctorIsolationScope)`  
`app/Domains/Auth/Scopes/DoctorIsolationScope.php:31-34` - Where clause filters ALL queries

### FIX
FullSyncService must use `Patient::withoutGlobalScopes()->updateOrCreate(...)` to prevent DoctorIsolationScope from filtering the sync operation.

---

## ROOT CAUSE #3: Mobile API Resources Missing (Phase 17 Blocker)
**Severity: MEDIUM - Causes inconsistent JSON responses**

`app/Domains/Mobile/Resources/` is EMPTY - no MobilePatientResource, MobilePatientFileResource, etc.
The mobile API controllers use web resources (`App\Domains\Patients\Resources\PatientResource`) instead of mobile-specific ones.
This means responses may have inconsistent format between web and mobile.

### FIX
Create mobile-specific resources in `app/Domains/Mobile/Resources/` mirroring web resource format.

---

## ROOT CAUSE #4: WebWorkspace Doesn't Use Hybrid Repositories
**Severity: MEDIUM - Online-only path on web server**

`WorkspaceController` directly uses `EloquentPatientRepository` and `ApiPatientRepository` — NOT the Hybrid repositories.
This means the web side has no offline fallback and doesn't write to SQLite cache.
The mobile (NativePHP) side uses Hybrid repos via RepositoryServiceProvider, but the data never reaches SQLite because the sync fails (Root Cause #1+2).

### FIX
- Fix the sync (Root Cause #1+2)
- Optionally migrate WorkspaceController to use Hybrid repos for consistency

---

## ROOT CAUSE #5: test_api.php Uses Wrong Password
**Severity: LOW - Diagnostic tool broken**

`test_api.php` uses hardcoded `'password'` which won't match any real doctor password.

---

## Verified Working Components
- AuthController login flow: solid (local first, remote fallback)
- ApiService singleton: solid (token persistence via session + DB)
- NetworkStatusService: solid (connectivity check with caching)
- EloquentPatientRepository: solid (pure local queries)
- HybridPatientRepository: solid (online→API, offline→local)
- Mobile routes (api.php): correct and complete
- Vue useWorkspace composable: solid (calls correct endpoints)
- DoctorWorkspace.vue: solid (onMounted calls refreshPatientList)
