# Medical Plus V3 — Coding Rules & Conventions

> **Status:** Active — applies to all contributors and AI agents working on this project.

---

## 1. Project Philosophy

### 1.1 Production is the Single Source of Truth

- Production Website: https://prof-hosam-fekry.online/
- The Laravel backend is connected to the **production MySQL database**.
- The Vue + Inertia frontend is **fully functional and deployed**.
- The mobile app (NativePHP) is an **additional client** of the existing backend.
- **Never** redesign, replace, or duplicate backend logic on mobile.
- **Never** access MySQL directly from the mobile app.

### 1.2 Backend Ownership

The Laravel backend owns:

- Authentication (session + Sanctum bearer tokens)
- Authorization (spatie roles, Gate policies, Eloquent scopes)
- Validation (inline in controllers — no Form Request classes)
- Business Logic (clinical rules, sharing rules, upload rules)
- Database (production MySQL is the only authoritative data store)
- Security (CSRF, rate limiting, signed URLs)
- Permissions (granular per-patient access via PatientShare)
- Reports (admin statistics, exports)
- Medical rules (file categorization, visit tracking, clinical fields)
- API responses (JSON via Resources)

The mobile app **never reimplements** any of these. It consumes the REST API only.

### 1.3 Feature Parity Rule

Every mobile feature must match the production website exactly:

- Same validations
- Same permissions
- Same business rules
- Same workflows
- Same API behavior

The **only** difference is the presentation layer (native UI vs web UI).

---

## 2. Language & Framework Rules

### 2.1 PHP

- **PHP 8.4+** syntax (typed properties, constructor promotion, `readonly`, `fn()` arrow functions)
- **No `strict_types` declaration** is used in this project (follow existing convention)
- **4-space indentation** — never tabs
- **Opening brace on same line** (K&R style): `class Foo {`, `if ($x) {`, `function bar() {`
- **No empty line** between `<?php` and `namespace`, or between `namespace` and `use` statements
- **Short array syntax `[]`** — never `array()`
- **No trailing commas** in single-element arrays; **trailing commas required** in multi-element `$fillable`, `$casts`, `$hidden` arrays and multi-parameter method calls
- **Closures:** `fn()` for one-liners, `function()` for multi-line
- **String interpolation:** double-quoted strings for variable embedding
- **No closing `?>` tag** at end of files
- **One class per file** — class name matches file name exactly (PSR-4)

### 2.2 Laravel Patterns

- **Query Builder** — Eloquent's default state management tracking (`$fillable`, `$guarded`, `$casts`, `$with`, `booted()` closures) is the built‑in contract. You do **not** need an explicit "State Manager" class to handle `fill()` / `isDirty()` checks; just use Eloquent's API (`Model::create($validated)`, `$model->update($validated)`, `$model->isDirty()`, `$model->wasChanged()`).
- **Controllers** are thin. They handle HTTP concerns (request/response, auth gates, redirects). Put validation, business rules, and orchestration into services, policies, or model helpers — not controllers.
- **Validation** is inline: `$request->validate([...])` directly in controller methods. No Form Request classes.
- **Constructors** use PHP 8 property promotion: one line, no body:
  ```php
  public function __construct(private readonly PatientRepositoryInterface $repo) {}
  ```
- **No service locator** pattern in controllers — all dependencies injected via constructor.
- **Repository pattern** (see Section 4 for full rules).
- **Global scopes** for cross-cutting data filtering (e.g., doctor isolation). Registered in model `booted()`. Must check `app()->runningInConsole()` to avoid breaking artisan.
- **Soft deletes:** Models use `SoftDeletes` trait; override `resolveRouteBinding()` to include trashed records: `$this->withTrashed()->where(...)->firstOrFail()`.
- **UUIDs as route keys:** Resource routes override parameter binding to `uuid` instead of auto-increment `id`. URL uses `{patient:uuid}`.
- **`unguard()/reguard()`** required when syncing remote data (mass-assignment on API responses). Always wrap in try/catch with `reguard()` in both.
- **Logging:** PSR-3 structured logs with context arrays. Tag messages with `[ClassName]` prefix. No `dump()` / `var_dump()` / `ray()` — use `Log::debug()` or `Log::channel('single')->info()`.
- **No default `Model` usage** for domain entities — all domain models live under `app/Domains/{Domain}/Models/`.

---

## 3. Naming Conventions

### 3.1 PHP

| Element | Convention | Example |
|---------|-----------|---------|
| Classes | PascalCase, matches filename | `PatientService`, `ChunkUploadService` |
| Methods | **verb-first** camelCase (describe action) | `storePatient()`, `mergeChunks()`, `validateChecksum()` |
| Variables | camelCase | `$patientUuid`, `$chunkIndex`, `$totalSize` |
| Constants | UPPER_SNAKE_CASE | `MAX_FILE_SIZE`, `MERGE_BUFFER` |
| Properties | camelCase | `$patientRepo`, `$apiResult`, `$cleanData` |
| Table names | snake_case plural | `patients`, `patient_visits`, `sync_queue` |
| Column names | snake_case | `primary_doctor_id`, `client_updated_at`, `medical_record_number` |
| Enums / string literals | **SCREAMING_SNAKE_CASE** in code; **snake_case** in DB | Role: `'super-admin'`, Status: `'uploading'`, Access: `'read_write'` |
| Route names | snake_case with dot grouping | `patients.shared`, `admin.doctors.index`, `api.files.stream` |
| Route params | camelCase or explicit binding | `:uuid`, `patientUuid`, `doctor` |
| Interfaces | PascalCase + `Interface` suffix | `PatientRepositoryInterface` |
| Repositories | `{Strategy}{Domain}Repository` | `HybridPatientRepository`, `EloquentPatientFileRepository` |
| Scopes | PascalCase + `Scope` suffix | `DoctorIsolationScope` |
| Resources | PascalCase + `Resource` suffix | `PatientResource`, `FileResource` |
| Jobs | PascalCase + `Job` suffix | `FullSyncJob`, `ProcessUploadedFileJob` |
| Commands | PascalCase + `Command` suffix | (standard Laravel) |

### 3.2 Vue / JavaScript

| Element | Convention | Example |
|---------|-----------|---------|
| Components | PascalCase | `PatientListSidebar`, `BaseCard`, `CategoryBlock` |
| Composables | `use{CamelCase}.js` | `useWorkspace.js`, `useUploads.js` |
| Variables / refs | camelCase | `selectedPatientId`, `isReadOnly`, `hasError` |
| Legend: Major sections | `!`-prefixed key (never a file) | `// !src/components/Form.tsx` |
| Sub-patterns | `##`-prefixed key | `// ## Two-factor pattern` |
| Code talks | `// ` + sentence | `// If user is unconfirmed, ring the alarm bell` |
| Boolean refs | `is`/`has` prefix | `isMobile`, `isRtl`, `hasRole`, `canEdit` |
| Computed properties | Noun form | `selectedPatient`, `filteredFiles`, `allNotes` |
| Event handlers | Verb-first | `selectPatient()`, `openPreview()`, `toggleSidebar()` |
| Constants | UPPER_SNAKE_CASE | `STORAGE_KEY`, `CHUNK_SIZE` |

### 3.3 Database

| Element | Convention |
|---------|-----------|
| Table names | snake_case, plural |
| Column names | snake_case |
| Foreign keys | `{singular_table_name}_id` |
| Index names | `{table}_{column}_index`, `{table}_{col1}_{col2}_index` |
| Migration files | `{YYYY_MM_DD_HHMMSS}_{description}.php` |

---

## 4. Architecture Rules

### 4.1 Domain-Driven Folder Structure

All domain logic lives under `app/Domains/{Domain}/{Layer}/`. The layer order is always:

```
app/Domains/{Domain}/
├── Models/       # Eloquent models
├── Services/     # Domain business logic
├── Resources/    # API JSON resources (JsonResource)
├── Scopes/       # Global Eloquent scopes (if applicable)
├── Repositories/ # (only if domain-specific, otherwise use App\Repositories)
├── Jobs/         # (if applicable)
└── Policies/     # (if applicable)
```

Infrastructure concerns live at the root level:
- `App\Repositories\*` — repository implementations (Eloquent, Api, Hybrid)
- `App\Repositories\Contracts\*` — repository interfaces
- `App\Services\*` — cross-cutting services (sync, upload, network)
- `App\Http\Controllers\*` — all HTTP controllers
- `App\Http\Middleware\*` — middleware
- `App\Jobs\*` — jobs
- `App\Providers\*` — service providers
- `App\Policies\*` — Gate policies
- `App\Observers\*` — model observers
- `App\Models\*` — root-level models (PendingOperation, SyncQueueItem)

### 4.2 Repository Interface + Three-Implementation Pattern

Every domain entity that needs data access MUST have:

1. **Interface** in `app/Contracts/Repositories/` — defines the contract
2. **Eloquent implementation** — local SQLite/MySQL access
3. **Api implementation** — remote REST API access (for mobile)
4. **Hybrid implementation** — orchestrator with network-aware fallback

```
PatientRepositoryInterface
├── EloquentPatientRepository
├── ApiPatientRepository
└── HybridPatientRepository  ← production default for mobile
```

Controllers **always** type-hint against the interface, never the concrete class. The binding is resolved in `RepositoryServiceProvider`.

### 4.3 Hybrid Repository Pattern (Critical)

Every method in a Hybrid repository follows this exact pattern:

```php
public function method($id): array
{
    // 1. ONLINE: Try API, sync locally, return data
    if (NetworkStatusService::isOnline()) {
        try {
            $result = $this->apiRepo->method($id);
            $this->localRepo->syncFromApi(...);  // mirror to local
            return $result;
        } catch (\Throwable $e) {
            NetworkStatusService::setOffline();  // flip to offline
        }
    }

    // 2. OFFLINE: Return local data
    return $this->localRepo->method($id);
}
```

For **writes** (create/update/delete):

```
Online path: execute locally → try API → enqueue failure in SyncQueueService
Offline path: execute locally → enqueue in SyncQueueService → return success
```

### 4.4 Dual Offline-Queue System (Technical Debt)

Two parallel queue systems exist:

| System | Table | Used By | Processor |
|--------|-------|---------|-----------|
| **SyncQueue** (modern) | `sync_queue` | PatientRepo, PatientFileRepo, PatientNoteRepo | FullSyncService |
| **PendingOperations** (legacy) | `pending_operations` | PatientVisitRepo. HybridUserRepo | SyncPendingOperationsJob |

**Rule:** New features MUST use `SyncQueue` (modern system). `PendingOperations` exists only for historical compatibility with Visits and Users. Do NOT extend it.

### 4.5 Global Scope (Doctor Isolation)

Row-level access is an Eloquent **global scope**, never a controller query filter.

- Scope must check `app()->runningInConsole()` — `true` for artisan (full access), `false` for requests (filtered)
- `super-admin` / `admin`: scope is **skipped entirely** — all data visible
- `doctor`: scope filters to `primary_doctor_id = auth.id OR active share exists`
- On child tables (visits, notes, files): scope uses `whereIn` with accessible patient IDs (avoids nested subqueries on SQLite)
- Scopes can be bypassed via `withoutGlobalScope()` for admin-only endpoints

### 4.6 UUID as Primary Route Key

- All route model binding for domain entities uses `uuid`, not auto-increment `id`
- Configured via `Route::resource('patients', ...)->parameters(['patients' => 'uuid'])`
- Controller methods accept `string $patientUuid` parameters
- UUIDs auto-generated in model `creating` event: `Str::uuid()->toString()`

### 4.7 Controller Output Convention

| Controller Type | Output | Example |
|----------------|--------|---------|
| API endpoint (api/v1 prefix) | `response()->json([...])` | JSON data, meta, message |
| Page render (web) | `Inertia::render('PageName', [...])` | Vue page + props |
| Error (API) | `response()->json(['error' => '...'], $code)` | Standard HTTP status |
| Error (web) | Return validation error or redirect back | Standard Laravel |

No mixed output in a single route. API endpoints never return HTML. Page routes never return raw JSON (except AJAX helpers).

### 4.8 Authorization: Frontend Receives Permissions Object

Controllers **never** pass raw `Gate` / `Policy` results to the frontend. Instead, compute a `permissions` object:

```php
'permissions' => [
    'can_edit'      => $user->can('update', $patientModel),
    'can_delete'    => $user->can('delete', $patientModel),
    'can_share'     => $user->can('share', $patientModel),
    'is_primary'    => $patientModel->primary_doctor_id === $user->id,
    'is_shared'     => $share !== null,
    'access_level'  => $share?->access_level ?? null,
    'shared_by_name' => $share?->sharedBy->name ?? null,
]
```

**The frontend uses this object exclusively.** No frontend authorization logic.

### 4.9 Three-Tier Role System

| Role | Can See All Patients | Can Manage Doctors | Full CRUD on Own Patients |
|------|---------------------|-------------------|---------------------------|
| `super-admin` | Yes (bypasses scope) | Yes | Yes |
| `admin` | Yes (no scope bypass) | Yes | Yes |
| `doctor` | No (scoped) | No | Yes |

Roles are checked via `$user->hasRole('...')` or route middleware `role:super-admin`.

---

## 5. Coding Standards

### 5.1 Blank Lines & Formatting

- **One blank line** between method definitions
- **No blank lines** within short method bodies (< 10 lines)
- **Group imports** by concern, not strictly alphabetically — facades group together, domain imports together, third-party together
- **PHPDoc blocks** required on public methods of service classes with `@param`, `@return`, typed descriptions
- **Inline `//` comments** explain **WHY**, not WHAT. Write comments for non-obvious logic, fallback paths, and edge cases.

### 5.2 Variable & Property Naming

- No Hungarian notation — types expressed via type hints
- Private properties: `$patientRepo`, `$syncQueue`, `$apiResult`
- Loop variables: `$patient`, `$file`, `$visit` (noun describing the element)
- Boolean prefixes: `is`, `has`, `can`, `should`

### 5.3 API Response Envelope

```php
// Success
response()->json([
    'data' => $records,
    'meta' => ['current_page' => 1, 'total' => 100],
    'message' => 'Success',
]);

// Error
response()->json(['error' => 'Validation failed', 'details' => [...]], 422);
```

No `{ success: true, data: ... }` wrapping for success responses.

### 5.4 Logging Convention

```php
Log::warning('[WorkspaceController] Session expired', [
    'uuid'       => $uuid,
    'user_id'    => auth()->id(),
    'elapsed_ms' => $elapsed,
]);

// Performance / profiling
Log::channel('single')->info('[WorkspaceController] API call completed', [
    'url'        => $url,
    'duration_ms' => $elapsed,
    'memory_mb'   => round(memory_get_peak_usage(true) / 1024 / 1024, 2),
]);
```

All log messages use `[ClassName]` prefix. No `dump()`, `var_dump()`, `ray()` in any file. Use `Log::debug()` for dev diagnostics with `import.meta.env.DEV` / `APP_DEBUG` guard.

### 5.5 Vue / Frontend Conventions

- **Vue 3 Composition API** with `<script setup>` for all `.vue` components
- **Plain JavaScript** — no TypeScript
- **Single quotes** for all JS strings
- **Tailwind CSS only** — no `<style scoped>` blocks in components
- **Dark mode:** `dark:` prefix throughout
- **RTL:** `:dir="isRtl ? 'rtl' : 'ltr'"` on root element
- **No Pinia / no Vuex** — use composables with module-level state (singleton pattern)
- **i18n:** `$t('workspace.key') || 'English fallback'` — English fallback required for ALL keys
- **Axios:** Used for HTTP — `@inertiajs/vue3` for page navigation
- **`typeof window !== 'undefined'`** guards on all DOM / storage access in composables
- **`import.meta.env.DEV`** guards on all `console.log` calls
- **No inline SVGs with hardcoded class chains** extracted across 2+ components — extract into `.vue` icon components

### 5.6 Composable Architecture

All composables follow the **module-level singleton** pattern:

```javascript
// Module-level: shared state across all imports
const someState = ref(null)
const anotherRef = ref(false)

export function useComposable() {
    // Mutate module-level state
    return {
        someState,
        anotherRef,
        doSomething: () => { /* ... */ },
    }
}
```

Calling `useComposable()` from any component returns the **same reactive references**. No class-based stores, no Pinia.

### 5.7 Asset Organization

- Vue components: `resources/js/Components/{Category}/{ComponentName}.vue`
- Composables: `resources/js/Composables/use{CamelCase}.js`
- Pages: `resources/js/Pages/{Category}/{PageName}.vue`
- Layouts: `resources/js/Layouts/{Name}.vue`
- Locales: `resources/js/Locales/{en,ar}.json` (601 lines each, must stay in sync)

---

## 6. Medical & Upload Business Rules

### 6.1 File Type Classification

Derived from MIME type at `ChunkMergeService::typeFromMime()`:

| MIME Prefix | File Type |
|------------|-----------|
| `image/*` | `image` |
| `video/*` | `video` |
| `audio/*` | `audio` |
| `application/pdf` | `pdf` |
| `text/*` | `text` |
| Everything else | `document` |

### 6.2 Upload Constraints

| Constraint | Value |
|-----------|-------|
| Max file size | **5 GB** (5,368,709,120 bytes) |
| Chunk size range | **1 MB – 50 MB** |
| Default chunk size | **5 MB** |
| Parallel chunk pool | **4 concurrent** requests |
| Session expiry | **6 hours** |
| Retry attempts | **3**, exponential backoff (500ms → 1s → 2s → 4s cap) |
| Checksum algorithm | **SHA-256** (per-chunk + final-file) |
| Merge buffer | **4 MB** |
| Read buffer (streaming) | **1 MB** |

### 6.3 Upload Session State Machine

```
pending → uploading → completed
           ↓
         failed (merge failure)
         cancelled (user-initiated)
         expired (cleanup job, expires_at reached)
```

- Session transitions `pending → uploading` use `lockForUpdate()` (once per session)
- Chunk receipts tracked via `upload_chunk_receipts` (idempotent INSERT OR IGNORE)
- `received_chunk_indexes` JSON column is **legacy** — not the authoritative source

### 6.4 Thumbnail Generation

1. Check DB `thumbnail_path` — if exists, serve directly (cached 24h)
2. If `video type` + ffmpeg: extract JPEG frame at 1s, scale max 300px, quality 5, save as `_thumb.jpg`
3. If `image type` + GD available: resize max 300px dimension, JPEG quality 70
4. If image already < 300px: return original (no resize)
5. Fallback: return original without resize

### 6.5 Media Streaming Rules

- Full **HTTP Range (206)** support with `Accept-Ranges: bytes`
- `ETag`: MD5 of `file_path + size + filemtime`
- `Cache-Control: private, no-transform, max-age=3600` (1h)
- `max_execution_time` set to **3600s** (1h) for stream responses
- Read buffer: **1 MB**

### 6.6 Signed URL Validity

- Signed URLs expire after **6 hours**
- Within expiry window, signed URLs **bypass auth checks** (global scopes)
- Only file access path without authentication

---

## 7. Data Isolation & Access Rules

### 7.1 Patient Share Access Levels

| Access Level | Permissions |
|-------------|-------------|
| `read` | View patient + files + visits + notes |
| `read_write` | View + update patient + files + visits + notes |
| `full` | View + update (equivalent to `read_write` in current policy) |

### 7.2 Share Expiry

```php
// A share is ACTIVE when:
($share->expires_at === null) || ($share->expires_at > now())
// A share is EXPIRED when:
($share->expires_at !== null) && ($share->expires_at <= now())
```

Expired shares are silently excluded from all permission checks — no error thrown.

### 7.3 File Category System

Six default categories from `config/categories.php`:

| Slug | Name (EN) | Name (AR) | Color |
|------|-----------|-----------|-------|
| `medical_history` | Medical History | التاريخ الطبي | `#0d9488` (teal) |
| `pre_op_radiology` | Pre-op Radiology | أشعة قبل العملية | `#f59e0b` (amber) |
| `post_op_radiology` | Post-op Radiology | أشعة بعد العملية | `#8b5cf6` (purple) |
| `operation_sheet` | Operation Sheet | ورقة العملية | `#ef4444` (red) |
| `medications` | Medications | الأدوية | `#3b82f6` (blue) |
| `notes` | Other Notes | ملاحظات أخرى | `#6b7280` (gray) |

Users can add custom categories (stored in `preferences.custom_categories`). Super-admin categories are global; doctor categories are per-user.

---

## 8. Mobile / NativePHP Rules

### 8.1 Offline-First Communication

- Mobile app **never** accesses MySQL directly
- Mobile app calls **only** the REST API under `/api/v1/` and `/api/native/`
- Admin web routes (`/admin/*`) are **not** available to mobile
- Hybrid repositories provide network-aware data access (API → local fallback)
- Write operations save locally first, then attempt API sync

### 8.2 Sync Rules

| Watermark Clock | Field | When Used |
|----------------|-------|-----------|
| **Client watermark** | `client_updated_at` (on all clinical entities) | On mobile response: store server timestamp. On next sync: fetch only `WHERE client_updated_at > lastWatermark`. Enables **delta-sync**. |

**Critical:** `client_updated_at` is **not** Laravel's `updated_at`. It is explicitly for sync delta timing.

### 8.3 NativePHP Binding

- `NATIVEPHP_RUNNING=true` in `.env.native` triggers Hybrid repository binding
- `NATIVEPHP_RUNNING` not set → ELO repository binding (web, direct MySQL)
- Startup sync: `FullSyncService::syncAll()` runs once per PHP process boot

### 8.4 API Resource Layer for Mobile

- `app/Domains/Mobile/Resources/` — **currently empty, must be created**
- Mobile API resources must extend `JsonResource` like web resources
- Must return **exactly** the same camelCase keys as web API resources
- Mobile never receives `views` or `Inertia` responses — only JSON

---

## 9. Security Rules

### 9.1 Never Do

- Hardcode secrets, API keys, database credentials — use `.env`
- Expose stack traces in production — `APP_DEBUG=false` on production
- Return permission or role names in error messages (see Spatie config: `display_permission_in_exception=false`, `display_role_in_exception=false`)
- Embed website in WebView — only native UI + API data
- Bypass Laravel validation or auth on any endpoint
- Trust client-supplied `type` or `category` fields — derive `type` from MIME on server
- Allow untrusted file extensions — derive from MIME, not filename
- Log sensitive data (passwords, tokens, full payloads with PII in production)

### 9.2 Always Do

- Validate `file_size` against `MAX_FILE_SIZE` (5 GB)
- Use `hash_equals()` for checksum comparison (timing-attack safe)
- Use `lockForUpdate()` for session state transitions
- Verify `Auth::check()` before allowing file modifications
- Apply `DoctorIsolationScope` to all patient-related queries
- Check `ownedByUser()` on upload session operations
- Validate `chunk_size` between 1 MB and 50 MB
- Set `expires_at` on upload sessions (6h) and signed URLs (6h)

---

## 10. i18n & Localization Rules

- **Primary languages:** Arabic (ar) + English (en)
- **Full RTL support** via `:dir` binding and Tailwind RTL utilities
- **Arabic is the primary UI language** — templates may contain Arabic text
- **English fallback** required for all i18n keys: `$t('workspace.key') || 'English fallback'`
- **Fonts:** Cairo (Arabic) + Inter (English/latin)
- **Locale files** must stay in sync: `en.json` and `ar.json` both contain exactly 601 lines
- **No hardcoded user-facing strings** in Vue templates — all must use `$t()` or have Arabic+English

---

## 11. Testing Rules

- **PHPUnit 12** framework, `phpunit.xml` configured with SQLite `:memory:`
- **Tests** located in `tests/` directory
- **Feature tests** for API endpoints and web routes
- **Unit tests** for services, repositories, and upload logic
- **No tests** may connect to the production MySQL database — always use SQLite in-memory
- **xdebug/pcov** required for code coverage reporting
- Target: meaningful coverage of auth flow, patient CRUD, upload pipeline, and sync engine

---

## 12. Git & Branching Rules

- **Main branch:** `main` (production)
- **Working branch:** `ui-redesign` (current)
- **Commit style:** `feat: description`, `fix: description`, `refactor: description`
- **Never** commit `.env`, `.env.native`, `.env.*-backup` files
- **Never** commit `storage/data/medical_plus.sqlite` (contains production data)
- `.env.backup*` files must never appear in git history — remove with `git filter-branch` if present
- `storage/logs/` content must never be committed

---

## 13. Build & Deployment Rules

### 13.1 NativePHP Android Build

- **minSdk:** 33 (Android 13+); consider 28 for wider compatibility
- **Target SDK:** 36 (Android 15)
- **Compile SDK:** 36
- **ABI:** arm64-v8a only
- **ProGuard/R8** enabled for release builds
- Production build: `./native-build-production.sh android`
- Build APK target: **< 25 MB preferred, < 30 MB acceptable**

### 13.2 Web Build

- Vite production build: `npm run build`
- Minification: **Terser** with `compress.drop_console: true`
- Vendor code splitting: `vendor` chunk (vue, vue-i18n, axios, @inertiajs/vue3) + `media` chunk (video.js, cropperjs, highlight.js)
- Dynamic page imports for code splitting (not eager `import.meta.glob`)
- `storage/app/mobile-cache/` excluded from NativePHP bundle

### 13.3 Environment Checks Before Build

- Verify no `.env.backup*` files in project root
- Verify no `app_debug.log` in project root (should be `storage/logs/`)
- Verify `APP_DEBUG=false` in production `.env`
- Verify all `console.log` guarded by `import.meta.env.DEV`
- Zero TODOs/FIXMEs/HACKs in production code paths

---

## 14. Forbidden Patterns

| Pattern | Reason | Correct Approach |
|---------|--------|------------------|
| WebView / iframe | Violates native-only policy | NativePHP components + API |
| Direct MySQL from mobile | Bypasses auth, validation, business rules | REST API only |
| Duplicating business logic | Creates maintenance burden, drift | Use existing Laravel services/endpoints |
| `dd()` / `dump()` / `ray()` | Production noise | `Log::debug()` with `APP_DEBUG` guard |
| `storage/logs/*.log` in git | Security (PII in logs) | `.gitignore` already configured |
| `.env*.backup` in root | Security risk | Remove immediately, never commit |
| Hardcoded URLs in JS | Breaks env switching | Use `window.Laravel.baseUrl` or route helpers |
| `Auth::user()` in repositories | Violates separation of concerns | User passed from controller |
| `withoutGlobalScope(DoctorIsolationScope::class)` without explanation | Data leakage risk | Only in admin-only endpoints, with comment |

---

## 15. Documentation Rules

- **ARCHITECTURE.md** — the authoritative architecture reference (auto-generated from codebase analysis)
- **NATIVE_ANDROID_STABILIZATION.md** — Android build-specific fixes and deployment checklist
- **All markdown** in English. Arabic reserved for UI translations (`ar.json`).
- **Inline code comments** must use `//` with trailing space. No `/* */` block comments.
- **PHPDoc** blocks: `/**` on their own line, ` * ` continuation, ` */` alone. Standard Laravel style.
- **Section headers** in markdown: `##` for major, `###` for subsections. No `# H1` in body files (reserved for filename in context).
