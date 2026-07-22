# 10 — Technical Debt

> **Purpose**: Catalog remaining code quality issues, patterns needing improvement, and future refactoring opportunities.
> **Priority**: P1 (Critical) / P2 (High) / P3 (Medium) / P4 (Low)

---

## P1 — Critical

### TD-001: Mobile Controllers Still Have Duplicate Validation
**Affected**: `app/Http/Controllers/Api/Mobile/PatientController.php`
**Issue**: The `store()` method validates the request TWICE — once for API path, once for fallback path. 46 lines of duplicated validation.
**Effort**: 1h
**Fix**: Extract validation rules into a shared method or FormRequest class.

```php
// Current: duplicated validation arrays
if (NativePhp::isRunning() && isOnline()) {
    $validated = $request->validate([...46 lines...]); // Copy 1
    // ... API call ...
}
// Fallback:
$validated = $request->validate([...46 lines...]); // Copy 2 (identical)
```

### TD-002: Mobile Controllers — API-First + Fallback Anti-Pattern Remains
**Affected**: All 4 `app/Http/Controllers/Api/Mobile/*.php` controllers
**Issue**: These controllers still follow the old "API-first with offline fallback" pattern. Every method checks `NativePhp::isRunning() && isOnline()` → tries API → catches exception → falls back to local.
**Effort**: 4h
**Fix**: Apply the same T004 transformation — always read/write locally; let Hybrid repos handle API sync.

### TD-003: Duplicate Patient Code Generation Logic
**Affected**: `app/Http/Controllers/WorkspaceController.php` and `app/Http/Controllers/Api/Mobile/PatientController.php`
**Issue**: Both controllers have the same `do { random_int() } while (exists)` code generation loop wrapped in `DB::transaction()`. Duplicated.
**Effort**: 30min
**Fix**: Extract into `PatientService::generateUniqueCode()`.

---

## P2 — High

### TD-004: Magic Numbers in Pagination
**Affected**: `app/Services/FullSyncService.php`, `app/Services/Sync/IncrementalSyncService.php`
**Issue**: `$perPage = 100` hardcoded in multiple methods. If API changes its pagination limit, all these need updating.
**Effort**: 30min
**Fix**: Define `const PER_PAGE = 100` in FullSyncService or a shared config.

### TD-005: Sync Queue Lock TTL Constant Access
**Affected**: `app/Services/FullSyncService.php`
**Issue**: `SyncQueueService::LOCK_TTL` accessed as public constant. Should be encapsulated behind a method.
**Effort**: 15min
**Fix**: Add `SyncQueueService::getLockTtl(): int` method.

### TD-006: NetworkStatusService Static Method Abuse
**Affected**: `app/Services/NetworkStatusService.php`
**Issue**: All methods are static. This makes testing difficult and prevents injection. `isOnline()` is called via Facade-like static calls across 43+ files.
**Effort**: 8h
**Fix**: Convert to injectable service. Replace `NetworkStatusService::isOnline()` with injected instance.

### TD-007: PatientFileRepository::upload() Inconsistent Signature
**Affected**: `app/Contracts/Repositories/PatientFileRepositoryInterface.php`
**Issue**: `upload(string $patientUuid, array $file, array $data = [])` — Eloquent implementation ignores `$file` param, API implementation uses it. Inconsistent contract.
**Effort**: 1h
**Fix**: Clarify contract: merge `$file` into `$data` before passing, or use separate methods.

---

## P3 — Medium

### TD-008: `syncPatientsLocally()` Removed — But Pattern Exists in Mobile Controllers
**Affected**: `app/Http/Controllers/Api/Mobile/PatientController.php`
**Issue**: T004 removed `syncPatientsLocally()` from WorkspaceController, but Mobile PatientController still has `cachePatientsLocally()` method that persists API data to SQLite. This is dead code if mobile controllers should also be offline-first.
**Effort**: 1h
**Fix**: Remove or deprecate once TD-002 is addressed.

### TD-009: Multiple `withoutGlobalScopes()` Calls in Observers
**Affected**: `app/Observers/PatientObserver.php`, `PatientFileObserver.php`, `PatientNoteObserver.php`
**Issue**: Each observer calls `withoutGlobalScopes()` individually. If a new observer is added, this pattern must be replicated.
**Effort**: 30min
**Fix**: Create a base observer class that handles scope bypass and sync enqueue dedup.

### TD-010: Inconsistent Error Response Formats
**Affected**: Multiple controllers
**Issue**: Some endpoints return `{ error: "..." }`, others return `{ message: "..." }`, others use `{ errors: {...} }`. Frontend must handle multiple formats.
**Effort**: 2h
**Fix**: Standardize error response format across all endpoints.

### TD-011: DoctorIsolationScope Duplicated
**Affected**: `app/Domains/Patients/Models/Scopes/DoctorIsolationScope.php`
**Issue**: Applied to Patient model via global scope. Also manually applied in some queries with `where('primary_doctor_id', ...)`.
**Effort**: 1h
**Fix**: Ensure scope handles all filtering; remove manual WHERE clauses.

### TD-012: Log Level Inconsistency
**Affected**: All PHP files
**Issue**: Critical errors use `Log::error()`, some use `Log::warning()`, others use `Log::info()`. No consistent severity guidelines.
**Effort**: 2h
**Fix**: Audit log levels and standardize.

---

## P4 — Low

### TD-013: Docblock Comments Outdated
**Affected**: Multiple files
**Issue**: Comments reference old architecture (e.g., "API-First" comments in useWorkspace.js still describe the old pattern).
**Effort**: 1h
**Fix**: Update docblocks to reflect current offline-first architecture.

### TD-014: `useWorkspace.js` — Module-Level State
**Affected**: `resources/js/Composables/useWorkspace.js`
**Issue**: All state is module-level (singleton). Multiple components importing `useWorkspace()` share state. This is intentional but fragile.
**Effort**: 4h
**Fix**: Consider Pinia store for explicit state management.

### TD-015: No TypeScript Definitions
**Affected**: All JavaScript files
**Issue**: The entire frontend is plain JavaScript. No type safety, no IDE autocompletion for API responses.
**Effort**: 40h
**Fix**: Migrate to TypeScript incrementally.

### TD-016: Test Coverage Gaps
**Affected**: `tests/`
**Issue**: 34 tests for 26 tasks is low coverage. Many code paths (Observers, ConflictResolver, IncrementalSync) have limited unit test coverage.
**Effort**: 20h
**Fix**: Add unit tests for each service class.

---

## Summary

| Priority | Count | Estimated Effort |
|----------|-------|-----------------|
| P1 — Critical | 3 | 5.5h |
| P2 — High | 4 | 9.75h |
| P3 — Medium | 5 | 6.5h |
| P4 — Low | 4 | 65h |
| **Total** | **16** | **~87h** |
