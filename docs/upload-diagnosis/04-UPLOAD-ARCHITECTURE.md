# Diagnosis 04 — Upload Architecture, API Surface & Structural Debt

**Project:** Medical_Plus_v4 (Laravel 13 + Vue 3 + Inertia + NativePHP Mobile 3.3 / Android)
**Branch:** `pro-version`
**Scope of this document:** the structural design of the upload subsystem — how many implementations exist, how endpoints are exposed, where responsibilities sit, what the authorization and identity model actually is, and what the target architecture should be.
**Out of scope (covered by sibling documents):** the temp-directory environment defect (01), error semantics and data integrity (02), memory and throughput (03).

This document is self-contained. Everything needed to implement and verify the changes is here.

> **Why this document matters even though it contains no P0 crash:** the defects in Diagnoses 01–03 are each present in *multiple* implementations. A fix applied to one uploader or one controller leaves the same bug live in two or three other code paths that the app selects at runtime, non-deterministically. **The structural duplication described here is the reason bugs in this subsystem keep coming back.** Read §2 before implementing any fix from any sibling document.

---

## 1. Executive statement

There are **four independent upload implementations** and **two near-identical server controllers**, exposing the **same five endpoints at four different URL prefixes**, with no shared configuration, no shared retry policy, no shared error handling, and no shared validation.

| Layer | Count | Consequence |
|---|---|---|
| Client uploaders | 4 | A fix must be applied 4× or it does not hold |
| Server controllers implementing chunked upload | 2 | `ChunkUploadController` and `UploadsController` are twins with divergent behaviour |
| Route registrations of the chunk endpoints | 4 | Different middleware stacks on the same handler |
| Single-shot upload endpoints | 3 | Different validation rules, different max sizes, different MIME policy |
| `FormRequest` classes | **0** | All validation inline, duplicated, and inconsistent |
| Upload feature tests | 1 | Covers only `init` returning 200 for an unknown patient |

The runtime selects between these paths using `navigator.onLine` and a filename regex — neither of which is reliable. **Which code path handles a given upload is effectively non-deterministic from the developer's point of view.**

---

## 2. Finding A-1 — Four client uploaders (P1)

| # | Implementation | Selected when | Chunked | Parallel | Retries | Timeout | Resume |
|---|---|---|---|---|---|---|---|
| 1 | `useUploads.js` → `uploadDirectly()` | online **and not** video | No | n/a | **0** | **none** | No |
| 2 | `useUploads.js` → `startUpload()` / `runPool()` | online **and** video | 5 MB | 4 | 3 + backoff | 300 s/chunk | Yes (localStorage + `/status`) |
| 3 | `useOfflineUploads.js` → `saveFileChunkedOffline()` | offline **and** (video **or** > 8 MB) | 5 MB | **1** | **0** | 120 s/chunk | **No** |
| 3b | `useOfflineUploads.js` → `saveFileOffline()` | offline **and** small non-video | No | n/a | **0** | 120 s | No |
| 4 | `FileSyncService::uploadLargeFileResumable()` (PHP) | later sync to production | 5 MB | 1 | Guzzle `retry(2, 500)` | 300 s/chunk | Intended, **broken** |

### 2.1 Selection logic

`resources/js/Components/workspace/CategoryBlock.vue:464-484`
```js
const uploadFile = (file, patientId, options) => {
  const isOnline = syncOnline.value;
  trace('[TRACE_F1] CategoryBlock.uploadFile() ENTERED - fileName: ' + file?.name + ' patientId: ' + patientId + ' isOnline: ' + isOnline)
  if (!isOnline && typeof offlineUploadFile === 'function') {
    trace('[TRACE_F2] OFFLINE - calling offlineUploadFile() from useOfflineUploads')
    const patientUuid = selectedPatient.value?.uuid || patientId
    return offlineUploadFile(file, patientUuid, options);
  }
  if (typeof onlineUploadFile === 'function') {
    trace('[TRACE_F2b] ONLINE - calling onlineUploadFile() from useUploads')
    return onlineUploadFile(file, patientId, options);
  }
  trace('[TRACE_F2c] NEITHER available - returning null!')
  return null;
}
```

Then within the online path:

`resources/js/Composables/useUploads.js:359-364`
```js
async function startUpload(job, debug = null) {
    const isVideo = job.file?.type?.startsWith("video/") || /\.(mp4|mov|avi|mkv|webm)$/i.test(job.file?.name || "");
    if (!isVideo) {
        return uploadDirectly(job, d);
    }
```

And within the offline path, a different rule:

`resources/js/Composables/useOfflineUploads.js:223-236`
```js
const LARGE_FILE_BYTES = 8 * 1024 * 1024
const name = (file.name || '').toLowerCase()
const looksLikeVideo = /\.(mp4|mov|avi|mkv|webm|3gp|wmv|flv|m4v)$/.test(name)
const isVideo = !!(file.type && file.type.startsWith('video/'))
  || looksLikeVideo
  || (file.size || 0) > LARGE_FILE_BYTES
```

**The two "is this a video" tests do not agree.** The offline regex covers `3gp|wmv|flv|m4v`; the online one does not. The offline path additionally treats anything over 8 MB as chunk-worthy; the online path does not. Therefore:

- A 50 MB **JPEG** uploaded **online** goes through `uploadDirectly` — one POST, no chunking, no retry, no timeout, no resume, no cancel.
- The same file uploaded **offline** is chunked with resume-less sequential chunking.

Neither is correct, and which one runs depends on `navigator.onLine`.

### 2.2 The selector is unreliable

`resources/js/Composables/useSyncEngine.js:23, 63-66`
```js
const isOnline = ref(typeof navigator !== 'undefined' ? navigator.onLine : true)
// ...
window.addEventListener('online',  () => { isOnline.value = true })
window.addEventListener('offline', () => { isOnline.value = false })
```

`navigator.onLine` in an Android WebView reports whether a network interface exists — not whether the internet is reachable. Wi-Fi with no upstream, a captive portal, or a stalled mobile connection all report `true`.

The codebase already knows this. `useOfflineUploads.js:53-59` and `AddRecordModal.vue:195-199` both carry comments about the unreliability of this signal — yet it remains the switch that selects the uploader.

A native connectivity bridge exists in the Android layer (`ConnectivityManager` usage referenced in the `useSyncEngine.js` header comment) but is not wired to this ref.

### 2.3 Second entry point with a third rule

`resources/js/Components/workspace/AddRecordModal.vue:252-276`
```js
const online = syncIsOnline.value
const targetPatientUuid = props.patient?.uuid || props.patient?.id || props.patient
for (const file of selectedFiles.value) {
  if (online && typeof onlineUploadFile === 'function') {
    onlineUploadFile(file, targetPatientUuid, { category: props.categorySlug, desc: notes.value })
  } else {
    offlineUploadFile(file, targetPatientUuid, {
      category: props.categorySlug,
      desc: notes.value
    }).catch(e => console.error('[AddRecordModal] Offline upload failed:', e))
  }
}
```

Note `AddRecordModal` passes `uuid || id`, while `CategoryBlock.handleNativeFileResult` passes `selectedPatient.value?.id` (`:1070`) and `CategoryBlock.handleFiles` passes `selectedPatient.value?.uuid || selectedPatient.value?.id` (`:1174`). **Three call sites, three identifier conventions.**

This causes a visible bug: the inline per-category progress list filters on `.id`:

`CategoryBlock.vue:807-816`
```js
const activeUploads = computed(() => {
  const pid = selectedPatient.value?.id
  if (!pid) return []
  return uploads.value.filter(j =>
    j.patientId === pid &&
    j.metadata?.category === props.slug &&
    j.status !== 'completed' &&
    j.status !== 'cancelled'
  )
})
```

so uploads started via drag/drop or `<input type=file>` (which pass a UUID) **never appear in the inline list**. They render only in `UploadManager.vue`, which does not filter by patient. The user sees the same upload in one place or the other depending on how they started it.

### 2.4 Impact on every other diagnosis

| Fix from another document | Must be applied to |
|---|---|
| Retry / resume (Diagnosis 02, FIX-REL-2) | Uploaders 1, 3, 3b, 4 |
| Timeouts (Diagnosis 03, FIX-PERF-5) | Uploaders 1, 2 (control requests), 3, 3b |
| Chunk size / pool size (Diagnosis 03, FIX-PERF-2/4) | Uploaders 2, 3, 4 — each has its own constant |
| Status-aware retry (Diagnosis 03, FIX-PERF-7) | Uploaders 2, 3, 4 |
| Error message extraction (Diagnosis 02, §2.6) | Uploaders 1 and 3 discard the server message; 2 does not |

**This table is the argument for consolidation.** Four uploaders means every reliability fix is a four-way change with three chances to miss one.

### 2.5 Required change — FIX-ARCH-1

**Phase 1 (immediate, low risk):** extract the shared constants and the chunk-transfer loop into one module that all three JS uploaders call. Keep the online/offline decision, but make both branches use the same transfer primitive with the same retry, timeout, and resume behaviour. `configureUploads()` (`useUploads.js:31-35`) already exists as the configuration entry point.

**Phase 2:** delete `uploadDirectly`. Route every file — image, PDF, video, any size — through the chunked path. A 100 KB image is one chunk; the overhead is one extra `init` and one `complete` round trip, which is negligible compared to having a second implementation with no retry, no timeout, and no cancel. This removes uploader 1 and 3b entirely.

**Phase 3:** replace `navigator.onLine` with the native connectivity signal, and treat "offline" as a queueing decision rather than a different implementation — the same uploader, writing to a local session that syncs later.

---

## 3. Finding A-2 — Two near-identical server controllers (P1)

`app/Http/Controllers/Api/ChunkUploadController.php` (478 lines) and `app/Http/Controllers/Api/UploadsController.php` (425 lines) both implement resumable chunked upload against the same services and the same tables.

| Aspect | `ChunkUploadController` | `UploadsController` |
|---|---|---|
| Methods | `init`, `chunk`, `complete`, `cancel`, `status` | `start`, `chunk`, `status`, `resume`, `finish`, `destroy` |
| Exposed at | `/api/v1/chunk/*` (three registrations) and `/api/v1/mobile/chunk/*` | `/api/v1/mobile/uploads/*` |
| Validation rules | Identical | Identical |
| `resolvePatient` | `Patient::withoutGlobalScopes()`, matches `uuid` only | Drops only `DoctorIsolationScope`, matches `uuid` **or** `remote_uuid` |
| Creates stub patients | Yes | Yes |
| Creates stub users | Yes (`doctor@local.test`) | Yes (`resolveCurrentUserId()`, `:393-424`) |
| `BackgroundSync` calls | Yes (`start`, `progress`, `stop`) | No |
| Error handling | Catch-all → 500 | Catch-all → 500 |
| Used by | All three JS uploaders + `FileSyncService` | **Nothing in the repository** |

**`UploadsController` has no caller.** No JS file references `/api/v1/mobile/uploads/`; `FileSyncService` calls `/chunk/*`. It is dead code that nonetheless exposes a live, unauthenticated-by-default API surface on device.

### 3.1 The `remote_uuid` divergence is a real bug

`ChunkUploadController::resolvePatient()`
```php
$patient = is_numeric($patientId)
    ? Patient::withoutGlobalScopes()->find((int) $patientId)
    : Patient::withoutGlobalScopes()->where('uuid', $patientId)->first();

if ($patient) {
    return $patient;
}

// ... creates a stub ...
$patient = Patient::withoutGlobalScopes()->firstOrCreate(['uuid' => $uuid], $stubData);
```

`UploadsController::resolvePatient()` (`:353-383`) additionally matches `remote_uuid`.

**Consequence:** a patient that was synced from the production server carries both a local `uuid` and a `remote_uuid`. If the client addresses that patient by its *remote* UUID — which happens after a sync round trip — `ChunkUploadController` fails to find it and **creates a duplicate stub patient**, then attaches the uploaded file to the duplicate. The file becomes invisible under the real patient record.

Note the extensive `SYNC FIX` comments in `ChunkUploadController::resolvePatient()` documenting two prior incidents in this exact function (overwriting `sync_status`, and the global-scope lookup failure). This function has been patched twice and still has a third divergence.

### 3.2 Required change — FIX-ARCH-2

Delete `UploadsController` and its route group (`routes/api.php:110-116`) after confirming no external client depends on it, **or** — if it must be retained — reduce both controllers to thin wrappers over one shared service so the `resolvePatient` logic exists once. Add `remote_uuid` matching to the surviving implementation.

---

## 4. Finding A-3 — The same endpoints registered four times (P1)

### 4.1 The chunk endpoints

| # | Prefix | File:line | Middleware |
|---|---|---|---|
| 1 | `/api/v1/chunk/*` | `routes/web.php:197-201` | `web` group, minus `PreventRequestForgery` (`:186-189`) — **this is what the frontend actually calls** |
| 2 | `/api/v1/chunk/*` | `routes/api.php:119-123` | `api` group; `auth:sanctum` unless SQLite (`routes/api.php:49-53`) |
| 3 | `/api/v1/mobile/chunk/*` | `routes/web.php:318-322` | Inside `if (config('database.default') === 'sqlite')` (`:276`), minus `PreventRequestForgery` |
| 4 | `/api/v1/mobile/uploads/*` | `routes/api.php:111-116` | Different controller (`UploadsController`), same tables |

Routes 1 and 2 have the **same URI**. Laravel resolves by registration order — `web.php` is registered before `api.php` in `bootstrap/app.php`'s `withRouting()`, so **route 1 wins and route 2 is unreachable**. The `auth:sanctum` middleware on route 2 therefore never applies to `/api/v1/chunk/*` on any platform.

This is not a theoretical concern: it means the authentication posture of the chunk endpoints is determined by which file happens to register first, not by an explicit decision.

### 4.2 The single-shot upload endpoints

| # | Route | Controller | Max size | MIME policy |
|---|---|---|---|---|
| 1 | `POST /api/v1/patients/{patientUuid}/files` (`web.php:204`) | `UploadController@store` | 500 MB | **none** |
| 2 | `POST /api/v1/mobile/patients/{uuid}/files` (`web.php:289`, `api.php:86`) | `Mobile\FileController@store` | 500 MB | **none** |
| 3 | `POST /_native/api/offline/uploads` (`web.php:338`) | `Mobile\FileController@store` | 500 MB | **none** |

Compare the chunked path, which enforces `UploadValidationService::ALLOWED_MIMES`. **The single-shot endpoints accept any MIME type and any extension.** See §6.

### 4.3 A dead endpoint and a stub endpoint

- `resources/js/Composables/useOfflineUploads.js:398` calls `POST /_native/api/offline/uploads/{uuid}/retry`. **No such route exists.** It 404s silently.
- `routes/web.php:207` → `UploadController@progress` is a hardcoded stub that always returns 100%.

### 4.4 Required change — FIX-ARCH-3

Register each upload endpoint exactly once, in one file, with an explicit middleware decision. Add a route-count assertion to the test suite so a future duplicate registration fails CI:

```php
$this->assertCount(1, collect(Route::getRoutes())->filter(
    fn ($r) => $r->uri() === 'api/v1/chunk/chunk'
));
```

Remove the dead `/retry` call and either implement or remove `/uploads/progress`.

---

## 5. Finding A-4 — No `FormRequest` classes; validation duplicated and inconsistent (P1)

`app/Http/Requests` does not exist. `grep -rl "FormRequest" app/` returns nothing. Every rule is inline in a controller.

### 5.1 The same concept validated four different ways

| Concept | `ChunkUploadController::init` | `UploadsController::start` | `UploadController::store` | `Mobile\FileController::store` |
|---|---|---|---|---|
| Max size | `file_size ... max:5368709120` (5 GB) | same | `file ... max:512000` (500 MB) | `file ... max:512000` (500 MB) |
| MIME check | Via `UploadValidationService` allowlist | Via the same service | **none** | **none** |
| Title | `max:255` | `max:255` | `max:255` | `max:255` |
| Category | `max:100` | `max:100` | `max:100` | `max:100` |
| Auth | Optional (`if ($request->user() && ...)`) | Optional | `auth()->id()` may be null | Optional |

### 5.2 Two disagreeing allowlists

`app/Services/Upload/UploadValidationService.php` — `ALLOWED_MIMES` (33 entries, MIME-based).
`app/Domains/Media/Services/UploadService.php:15` — `SAFE_EXTENSIONS` (extension-based):

```php
private const SAFE_EXTENSIONS = ['mp4','mov','avi','mkv','webm','m4v','3gp','wmv','flv',
    'jpg','jpeg','png','gif','webp','bmp','heic','tif','tiff','pdf','doc','docx','xls','xlsx',
    'ppt','pptx','txt','csv','rtf','zip','rar','7z','mp3','wav','aac','flac','ogg','m4a','dcm','dicom'];
```

`SAFE_EXTENSIONS` permits `m4v`, `3gp`, `wmv`, `flv` — **all of which `ALLOWED_MIMES` rejects**. A `.3gp` file therefore passes one gate and fails the other depending on which upload path handles it. (Diagnosis 02, §2.4 covers the user-visible consequence: a 422 rendered as a 500.)

### 5.3 A third, implicit policy: no extension check at all

`app/Http/Controllers/Api/Mobile/FileController.php:119-162`
```php
$uploadedFile = $request->file('file');
$fileUuid  = (string) \Illuminate\Support\Str::uuid();
$extension = $uploadedFile->getClientOriginalExtension();     // ← raw client extension
$mimeType  = $uploadedFile->getMimeType();
$size      = $uploadedFile->getSize();
// ...
$path = $uploadedFile->storeAs(
    "patients/{$uuid}",                                        // ← raw client-supplied patient uuid
    "{$fileUuid}.{$extension}",
    'local'
);
```

No allowlist of either kind. `$fileUuid.php` is a writable filename. See §6.

### 5.4 Required change — FIX-ARCH-4

Create `app/Http/Requests/` with `InitChunkUploadRequest`, `StoreChunkRequest`, `CompleteChunkUploadRequest`, and `StoreFileRequest`. Move every inline rule into them. Define the allowlist **once** — as a pair of MIME and extension sets in a single config file or a single service — and have both `UploadValidationService` and `UploadService` read from it.

This also fixes a Diagnosis 02 defect for free: a `FormRequest` throws `ValidationException` before the controller's `try` block is entered, so validation failures can no longer be swallowed by a catch-all.

---

## 6. Finding A-5 — Identity, authorization, and path-safety (P1)

### 6.1 Authorization is effectively optional

`ChunkUploadController::init`
```php
if ($request->user() && $request->user()->cannot('view', $patient)) {
    // deny
}
```

**With no authenticated user there is no gate at all.** On device this is by design — `routes/api.php:49-53` explicitly disables `auth:sanctum` when the database is SQLite:

```php
$isEmbeddedLaravel = config('database.default') === 'sqlite';
$mobileMiddleware  = $isEmbeddedLaravel ? [] : ['auth:sanctum'];
```

and `ParseMobileMultipartMiddleware` auto-logs-in the first user:

```php
if (config('database.default') === 'sqlite' && \Illuminate\Support\Facades\Auth::guest()) {
    $localUser = \App\Domains\Users\Models\User::first();
    if ($localUser) {
        \Illuminate\Support\Facades\Auth::login($localUser);
    }
}
```

For a single-user embedded app this is a defensible decision. **The problem is that the same controllers are also mounted on the production server**, where the `web.php:197-201` registration (which wins over the `api.php` one, §4.1) carries no `auth` middleware. Combined with the CSRF exemptions:

`bootstrap/app.php`
```php
$middleware->validateCsrfTokens(except: [
    '/api/session/restore',
    '/api/v1/*',
    '/_native/*',
    '/chunk/*',
    '/uploads/*',
]);
```

and no rate limiting anywhere (`grep -rn "throttle" routes/` returns nothing), the production chunk endpoints are **unauthenticated, CSRF-exempt, and unthrottled**, accepting files up to 5 GB.

### 6.2 Side-effecting writes on upload

Both controllers create rows as a side effect of an upload:

- **Stub patients** — `ChunkUploadController::resolvePatient()` `firstOrCreate` a `Patient` with `name: 'Patient <uuid>'` and `sync_status: 'pending_create'`.
- **Stub users** — the same method creates `doctor@local.test` with `password: bcrypt('password')` if no user exists. `UploadsController::resolveCurrentUserId()` (`:393-424`) does the same.

On device this is initialisation logic. On an unauthenticated production endpoint it is **a way for an anonymous caller to create patient records and a user account with a known password**.

The stub-patient behaviour is also load-bearing for offline use and is covered by the only existing test (`tests/Feature/ChunkUploadInitTest.php`), so it cannot simply be deleted — it must be gated on the embedded runtime.

### 6.3 Unsanitised path construction

Three sites interpolate a client-supplied identifier directly into a filesystem path:

| File:line | Code |
|---|---|
| `app/Services/Upload/UploadSessionService.php:33-35` | `$finalPath = $patientUuid ? "patients/{$patientUuid}/%s.{$extension}" : null;` |
| `app/Http/Controllers/Api/Mobile/FileController.php:158-162` | `storeAs("patients/{$uuid}", "{$fileUuid}.{$extension}", 'local')` |
| `app/Domains/Media/Services/ChunkMergeService.php:55` | `"patients/{$locked->patient->uuid}/{$fileUuid}.{$extension}"` |

In sites 1 and 3 the UUID comes from a `Patient` row, so it is a real UUID — safe. **Site 2 uses the raw route parameter `$uuid` with no validation.** `Mobile\FileController::store` does not verify that `$uuid` matches a UUID pattern before using it as a directory name.

Similarly, `$extension` at `UploadSessionService.php:24-25` comes from the client filename with only `strtolower(trim(...))` — no allowlist, no length cap (which also causes the `VARCHAR(20)` overflow described in Diagnosis 02, §9).

Laravel's `storeAs` normalises some traversal, and the `local` disk root confines writes to `storage/app/private` (outside `public/`), so this is **hardening rather than a confirmed exploit** — but the combination of an unauthenticated endpoint, an arbitrary extension, and an unvalidated directory component is not a posture to leave in a medical application.

### 6.4 Debug detail in error responses

Covered in Diagnosis 02 §2.7, restated here because it is an architectural decision: the `bootstrap/app.php` render closure returns `file`, `line`, `trace`, `sqlstate`, and `sqlite_error` in the JSON body for every `api/*`, `_native/*`, `*chunk*`, and `*upload*` route, unconditionally — including production with `APP_DEBUG=false`.

### 6.5 Required change — FIX-ARCH-5

1. Split the route registration so the **production** chunk endpoints carry `auth:sanctum` and `throttle`, while the **embedded** ones do not. Do not rely on registration order to determine authentication.
2. Gate stub-patient and stub-user creation on `config('database.default') === 'sqlite'` explicitly, and return 404 for an unknown patient on production.
3. Validate `$uuid` in `Mobile\FileController::store` against a UUID pattern before path use; validate and cap `$extension` against the shared allowlist.
4. Gate debug detail behind `app()->hasDebugModeEnabled()`.
5. Add rate limiting to the upload endpoints.

---

## 7. Finding A-6 — API contract inconsistencies (P2)

### 7.1 One key name, two types

`app/Services/Upload/ChunkUploadService.php:82` — returned from `storeChunk` (the `POST /chunk/chunk` response):
```php
'received_chunks'  => $receivedCount,      // integer
```

`app/Services/Upload/ChunkUploadService.php:219` — returned from `getStatus` (the `GET /chunk/{id}/status` response):
```php
'received_chunks'    => $received,          // array of indexes
```

A consumer that does not track which endpoint it called will break. `useUploads.js:388` correctly reads the array form from `/status`; `FileSyncService.php:193` reads a **third** name, `uploaded_chunks`, which does not exist — the resume bug documented in Diagnosis 02, R-5.

### 7.2 Response shape mismatch in `uploadDirectly`

`useUploads.js:340` reads `res.data.uuid`, but `UploadController@store` returns `{ success, message, file: { ... } }`. The client reads `undefined`.

### 7.3 Hardcoded URLs bypassing the URL helper

`resources/js/Utils/api.js` exports an `apiUrl()` helper, but `useUploads.js:309` hardcodes `/api/v1/mobile/patients/${job.patientId}/files` and `useOfflineUploads.js` hardcodes `/api/v1/chunk/*` and `/_native/api/offline/uploads`. Any future prefix change must be made in several places.

### 7.4 Bearer token snapshotted at module load

`useUploads.js:70-75`
```js
const _uploadToken = typeof localStorage !== 'undefined' ? localStorage.getItem('np_api_token') : null;
if (_uploadToken) {
    uploadHttp.defaults.headers.common['Authorization'] = 'Bearer ' + _uploadToken;
}
```

Read **once, at module import**. A user who logs in after the module loads uploads unauthenticated for the rest of the session. `useOfflineUploads.js` reads the token per call (`:83`, `:114`) — correct, and inconsistent with `useUploads.js`.

### 7.5 Required change — FIX-ARCH-6

Rename one of the two `received_chunks` fields (e.g. `received_count` on the chunk response). Fix `FileSyncService` to read the correct key. Route all client URLs through `apiUrl()`. Replace the module-load token snapshot with an axios request interceptor that reads the token per request.

---

## 8. Finding A-7 — Layering and dead code (P2)

### 8.1 Two service namespaces for one concern

| Namespace | Classes |
|---|---|
| `App\Services\Upload\` | `UploadSessionService`, `ChunkUploadService`, `UploadValidationService`, `UploadCleanupService`, `UploadChecksumService` |
| `App\Domains\Media\Services\` | `UploadService`, `ChunkMergeService` |

`ChunkMergeService` and `ChunkUploadService` are two halves of one workflow living in different namespaces with different conventions. `ChunkUploadController` injects five services from both namespaces.

Models are similarly split: `App\Domains\Media\Models\PatientFile` and `UploadSession` live in `Domains`, while the services that write them live partly in `Services`.

### 8.2 Dead code inventory

| Item | File | Status |
|---|---|---|
| `UploadsController` (425 lines) | `app/Http/Controllers/Api/UploadsController.php` | No caller |
| `ProcessUploadedFileJob` | `app/Jobs/ProcessUploadedFileJob.php` | Dispatched nowhere |
| `OptimizeVideoForStreaming` | `app/Domains/Media/Jobs/` | Not referenced from the upload path |
| Per-chunk checksum verification | `ChunkUploadService.php:36-42` | Complete server-side; **no client sends `checksum`** |
| `final_checksum` column | `upload_sessions` | Never populated on the primary path |
| `RESUMABLE_THRESHOLD` | `FileSyncService.php:16` | Unused since commit `51df61b` unified all uploads through the resumable path |
| `NATIVEPHP_TEMPDIR` | `LaravelEnvironment.kt:840` | Written, never read (see Diagnosis 01) |
| `/_native/api/offline/uploads/{uuid}/retry` | Called at `useOfflineUploads.js:398` | Route does not exist |
| `UploadController@progress` | `routes/web.php:207` | Hardcoded stub, always 100% |
| Legacy chunk-merge branch | `ChunkMergeService` `else` branch | Only reachable when `final_path` is null |

Dead code here is not merely untidy — `UploadsController` exposes a live API surface, and the dormant checksum verification is exactly the integrity mechanism Diagnosis 02 says is missing.

### 8.3 Required change — FIX-ARCH-7

Consolidate the upload services into one namespace. Delete or wire up each item in §8.2 — specifically, **activating the existing per-chunk checksum path is cheaper than building integrity verification from scratch** (Diagnosis 02, FIX-REL-3, item 6).

---

## 9. Target architecture

```
┌─────────────────────────────────────────────────────────────────┐
│ UI                                                              │
│   CategoryBlock.vue · AddRecordModal.vue                        │
│     └── one call: uploadFile(file, patientUuid, metadata)       │
│         (always patientUuid — never id)                         │
└───────────────────────────┬─────────────────────────────────────┘
                            │
┌───────────────────────────▼─────────────────────────────────────┐
│ useUploader.js   — ONE composable                               │
│   · one queue, one job model, one progress model                │
│   · platform config via configureUploads()                      │
│       device:  chunkSize 1–2 MB, poolSize 1                     │
│       browser: chunkSize 5 MB,   poolSize 4                     │
│   · every file chunked (a 100 KB image is one chunk)            │
│   · retry with backoff, status-aware (no 4xx retries)           │
│   · resume via localStorage + GET /chunk/{id}/status            │
│   · finite timeout on every request                             │
│   · online/offline is a QUEUEING decision, not a code path      │
└───────────────────────────┬─────────────────────────────────────┘
                            │  POST /api/v1/chunk/{init,chunk,complete}
                            │  GET  /api/v1/chunk/{id}/status
                            │  registered ONCE
┌───────────────────────────▼─────────────────────────────────────┐
│ ChunkUploadController — thin                                    │
│   · FormRequest validation (throws before the try block)        │
│   · truthful HTTP statuses: 422 / 401 / 403 / 410 / 507 / 503   │
│   · no business logic, no stub creation                         │
└───────────────────────────┬─────────────────────────────────────┘
                            │
┌───────────────────────────▼─────────────────────────────────────┐
│ App\Services\Upload\  — ONE namespace                           │
│   PatientResolver      · uuid OR remote_uuid; stubs only on     │
│                          the embedded runtime                   │
│   UploadSessionService · session lifecycle                      │
│   ChunkUploadService   · exact-size chunks, offset writes,      │
│                          fsync, idempotent receipts             │
│   ChunkMergeService    · size assertion vs total_size,          │
│                          SHA-256, idempotent completion         │
│   UploadCleanupService · removes final_path AND receipts        │
│   TempFileService      · storage-backed, never sys_get_temp_dir │
└───────────────────────────┬─────────────────────────────────────┘
                            │
┌───────────────────────────▼─────────────────────────────────────┐
│ Storage (local disk, throw => true)                             │
│   patients/{patientUuid}/{fileUuid}.{ext}                       │
│   tmp/uploads/{unique}          ← swept by the purge command    │
└─────────────────────────────────────────────────────────────────┘

FileSyncService reuses the SAME chunk client contract as the JS uploader,
reading `received_chunks` so it genuinely resumes.
```

### 9.1 Invariants the target architecture must hold

1. **One transfer implementation.** Every byte uploaded from any surface goes through the same chunk loop.
2. **One configuration source.** Chunk size, pool size, retry policy, and timeouts are defined once and set per-platform at bootstrap.
3. **One endpoint registration** per URI, with an explicit middleware decision.
4. **One allowlist**, shared by MIME and extension checks.
5. **One identifier convention** — patient UUID everywhere in the upload path.
6. **Truthful HTTP statuses.** Controllers never convert a typed exception into a generic 500.
7. **No side-effecting record creation** on the production API surface.
8. **Verified integrity.** Size and checksum asserted before a file is marked `ready`.

---

## 10. Affected files

| File | Lines | Findings |
|---|---|---|
| `routes/web.php` | 186-189, 197-207, 276-295, 318-322, 333-347, 414-422 | A-3 (duplicate registrations, dead stub) |
| `routes/api.php` | 49-53, 86, 110-123 | A-3 (shadowed registrations), A-5 (conditional auth) |
| `app/Http/Controllers/Api/ChunkUploadController.php` | 27-198, 200-276, 278-352, 406-477 | A-2, A-4, A-5 (stub creation, optional auth) |
| `app/Http/Controllers/Api/UploadsController.php` | whole file | A-2 (dead twin), A-8 |
| `app/Http/Controllers/Api/UploadController.php` | 19-33, 96 | A-4 (no MIME policy), A-3 (stub `progress`) |
| `app/Http/Controllers/Api/Mobile/FileController.php` | 89-204 | A-4 (no allowlist), A-5 (unvalidated path component) |
| `app/Services/Upload/UploadValidationService.php` | 13-48 | A-4 (allowlist #1) |
| `app/Domains/Media/Services/UploadService.php` | 15 | A-4 (allowlist #2, disagrees with #1) |
| `app/Services/Upload/UploadSessionService.php` | 21-35 | A-5 (unvalidated extension in path) |
| `app/Services/Upload/ChunkUploadService.php` | 82, 219 | A-6 (same key, two types) |
| `app/Services/Sync/FileSyncService.php` | 16, 190-196 | A-6 (wrong key), A-8 (dead constant) |
| `resources/js/Composables/useUploads.js` | 7-45, 70-75, 297-357, 359-364 | A-1, A-6 (token snapshot, hardcoded URLs) |
| `resources/js/Composables/useOfflineUploads.js` | 82-101, 112-187, 223-236, 398 | A-1, A-3 (dead endpoint) |
| `resources/js/Composables/useSyncEngine.js` | 23, 63-66 | A-1 (unreliable selector) |
| `resources/js/Components/workspace/CategoryBlock.vue` | 464-484, 807-816, 1070, 1174 | A-1 (identifier inconsistency, filter mismatch) |
| `resources/js/Components/workspace/AddRecordModal.vue` | 252-276 | A-1 (third selection site) |
| `bootstrap/app.php` | `validateCsrfTokens`, `withExceptions` | A-5 (CSRF exemptions, debug leak) |
| `app/Jobs/ProcessUploadedFileJob.php` | whole file | A-8 (dead) |
| `app/Http/Requests/` | **does not exist** | A-4 |

---

## 11. Risks and edge cases

| # | Risk / edge case | Notes |
|---|---|---|
| 1 | Consolidating uploaders changes behaviour for every upload at once | Stage it: shared constants → shared transfer loop → delete `uploadDirectly`. Do not do all three in one change |
| 2 | Routing images through the chunked path adds two round trips per small file | On the embedded runtime each round trip is a full Laravel boot. Measure; consider a single-request fast path *within* the same implementation (one chunk, `init`+`chunk`+`complete` combined) rather than a separate uploader |
| 3 | Deleting `UploadsController` | Confirm no external consumer (a second app, a script, a partner integration) calls `/api/v1/mobile/uploads/*` before deleting |
| 4 | Removing duplicate route registrations | The frontend currently reaches route 1 (`web.php`). Removing the wrong duplicate breaks all uploads. Verify with `php artisan route:list --path=chunk` before and after |
| 5 | Adding `auth:sanctum` to production chunk routes | The device must not be affected. Verify the SQLite branch still has no auth requirement, and that the token-refresh path works |
| 6 | Gating stub-patient creation | `tests/Feature/ChunkUploadInitTest.php` asserts stub creation and will fail if it is removed unconditionally. Gate on the embedded runtime and update the test to assert the production behaviour separately |
| 7 | Unifying the allowlist | Widening it (to include `3gp`, `heif`, `m4v`) is required for correctness; narrowing it anywhere would reject files that currently upload | 
| 8 | Standardising on patient UUID | Some call sites pass numeric IDs. `resolvePatient` handles both, so the transition is safe, but the `CategoryBlock` filter fix must land with it |
| 9 | Renaming `received_chunks` | `useUploads.js:388` reads the array form. Update client and server together |
| 10 | Token interceptor instead of module-load snapshot | Verify the interceptor does not attach a token to non-API requests |

---

## 12. Acceptance criteria

- [ ] **AC-A1** Exactly one client transfer implementation; `uploadDirectly` deleted or reduced to a configuration of the chunked path
- [ ] **AC-A2** Chunk size, pool size, retry policy, and timeouts are defined in one place and set per-platform at bootstrap
- [ ] **AC-A3** `php artisan route:list --path=chunk` shows each URI exactly once
- [ ] **AC-A4** `php artisan route:list --path=upload` shows no duplicate URIs
- [ ] **AC-A5** `app/Http/Requests/` exists and contains the upload request classes; no inline `$request->validate()` remains in the upload controllers
- [ ] **AC-A6** One allowlist definition; `UploadValidationService` and `UploadService` both read it; `.3gp`, `.heif`, `.m4v`, `.wmv`, `.flv` are accepted by both
- [ ] **AC-A7** Every upload call site passes a patient **UUID**; the inline progress list in `CategoryBlock.vue` shows uploads started by drag/drop, file picker, and camera alike
- [ ] **AC-A8** Production chunk endpoints require authentication and are rate-limited; embedded endpoints are unaffected
- [ ] **AC-A9** No stub `Patient` or `User` is created by an unauthenticated production request
- [ ] **AC-A10** `Mobile\FileController::store` rejects a non-UUID `{uuid}` route parameter
- [ ] **AC-A11** Error responses contain no `trace`/`file`/`line` when `APP_DEBUG=false`
- [ ] **AC-A12** `received_chunks` has one meaning; `FileSyncService` resumes correctly
- [ ] **AC-A13** Every item in the §8.2 dead-code inventory is removed or wired up, with the decision recorded
- [ ] **AC-A14** A token acquired after page load is used by subsequent uploads

---

## 13. Regression risks

| # | Change | Regression risk | Detection |
|---|---|---|---|
| 1 | Deleting `uploadDirectly` | Small-file uploads take a different, less-tested path | Feature test per file type and size band; measure round-trip count |
| 2 | Removing a duplicate route | Uploads 404 if the surviving registration has different middleware | `route:list` diff before/after; smoke test every upload surface |
| 3 | Adding auth to production routes | Device build breaks if the SQLite branch is caught by the change | Test on device **and** production in the same release |
| 4 | Gating stub creation | Offline first-upload-for-a-new-patient breaks if gated too broadly | `ChunkUploadInitTest` covers this; extend it for the production case |
| 5 | Unifying allowlists | A file type accepted by one path is now rejected by the unified one | Enumerate both lists, take the union, review each entry explicitly |
| 6 | Standardising on UUID | A call site passing a numeric ID now creates a stub with a generated UUID | `resolvePatient` handles numerics; verify no new stub patients appear after the change |
| 7 | Moving validation to `FormRequest` | Rules subtly change during transcription | Copy rules verbatim first, then change them in a separate commit |
| 8 | Namespace consolidation | Wide import churn; container bindings may break | Run the full suite; check `RepositoryBindingTest` |
| 9 | Deleting `UploadsController` | An unknown consumer breaks | Log requests to `/api/v1/mobile/uploads/*` for one release before deleting |
| 10 | Token interceptor | Token leaks to third-party requests if the interceptor is attached to the global axios instance | Attach to `uploadHttp` only, or filter by URL prefix |

---

## 14. Testing plan

### 14.1 Structural assertions (new tests — cheap and high-value)

| Test | Assertion |
|---|---|
| `chunk_routes_registered_once` | For each of `api/v1/chunk/{init,chunk,complete}`, exactly one matching route in `Route::getRoutes()` |
| `upload_routes_registered_once` | Same for the single-shot upload URIs |
| `upload_controllers_use_form_requests` | Reflection: no `->validate(` call inside the upload controllers |
| `allowlist_is_single_source` | `UploadValidationService` and `UploadService` resolve their lists from the same constant/config |
| `allowlist_mime_and_extension_agree` | Every extension in the extension list maps to a MIME in the MIME list, and vice versa |
| `no_dead_upload_routes` | Every URL referenced in `resources/js` under `/chunk`, `/uploads`, `/_native/api/offline` resolves to a registered route |

The last one would have caught the dead `/retry` endpoint (§4.3).

### 14.2 Behavioural parity matrix

Run the identical assertions across every entry point × network state × file type. **Currently these produce different behaviour; after FIX-ARCH-1 they must produce identical behaviour.**

| Entry point | Network | File | Must hold |
|---|---|---|---|
| `CategoryBlock` drag/drop | online | 100 KB JPEG | Chunked; retryable; cancellable; has a timeout |
| `CategoryBlock` drag/drop | offline | 100 KB JPEG | Same |
| `CategoryBlock` file picker | online | 17.4 MB MP4 | Same |
| `CategoryBlock` native camera | online | 17.4 MB MP4 | Same |
| `CategoryBlock` native camera | offline | 17.4 MB MP4 | Same |
| `AddRecordModal` | online | 50 MB JPEG | Same |
| `AddRecordModal` | offline | 50 MB JPEG | Same |
| Any | online | 8.5 MB PDF | Same |

For every row assert: the job appears in **both** `UploadManager.vue` and the inline `CategoryBlock` list; retry/pause/cancel are present; the resulting `patient_files` row has correct `size` and `sha256`.

### 14.3 Authorization tests

| Test | Assertion |
|---|---|
| `production_chunk_init_requires_auth` | With MySQL config, unauthenticated `POST /api/v1/chunk/init` → 401 |
| `embedded_chunk_init_allows_unauthenticated` | With SQLite config → 200 |
| `production_init_does_not_create_stub_patient` | Unknown UUID on MySQL → 404; no new `patients` row |
| `embedded_init_creates_stub_patient` | Existing behaviour preserved (extends `ChunkUploadInitTest`) |
| `production_init_does_not_create_stub_user` | No `doctor@local.test` row created |
| `upload_endpoints_are_rate_limited` | N+1 rapid requests → 429 |
| `store_rejects_non_uuid_patient_parameter` | `POST /api/v1/mobile/patients/../../etc/files` → 422 |
| `error_body_has_no_trace_in_production` | `config(['app.debug' => false])` → no `trace` key |

### 14.4 Contract tests

| Test | Assertion |
|---|---|
| `status_endpoint_returns_array_of_indexes` | `GET /chunk/{id}/status` → `received_chunks` is an array |
| `chunk_endpoint_returns_count_under_a_distinct_key` | `POST /chunk/chunk` → no key collision with the status response |
| `sync_service_reads_the_status_contract` | `FileSyncService` resume skips already-received chunks (mock the HTTP client, assert call count) |
| `patient_resolves_by_remote_uuid` | A patient with `remote_uuid` set resolves by that UUID; **no** duplicate stub created |

### 14.5 Manual verification

```bash
php artisan route:list --path=chunk
php artisan route:list --path=upload
php artisan route:list --path=files
```

Confirm each URI appears once, with the intended middleware. Capture the output before and after the change and diff it.

```bash
grep -rn "'/api/v1/chunk\|/_native/api/offline\|/api/v1/mobile/patients" resources/js/
```

Cross-reference every hit against `route:list`. Any URL without a matching route is dead (§4.3).

---

## 15. Recommended sequencing

This document's changes are structural and should be sequenced **around** the fixes in the sibling documents, not before them.

| Phase | Work | Rationale |
|---|---|---|
| 0 | Diagnosis 01 (temp dir), Diagnosis 03 FIX-PERF-2/4 (pool + chunk size) | Uploads must work at all, and must not OOM |
| 1 | Diagnosis 02 FIX-REL-1 (truthful statuses) | Prerequisite for validating everything else |
| 2 | **FIX-ARCH-4** (`FormRequest` classes) + **FIX-ARCH-6** (allowlist unification) | Removes a class of 500s and is a precondition for consolidation |
| 3 | Diagnosis 02 FIX-REL-2 (offline retry/resume) applied to the **shared** transfer loop from **FIX-ARCH-1 Phase 1** | Do the extraction first so the fix lands once, not three times |
| 4 | **FIX-ARCH-3** (route deduplication) + **FIX-ARCH-5** (auth/rate limiting) | Security posture |
| 5 | Diagnosis 02 FIX-REL-3/4 (integrity, idempotency) | Now testable against truthful statuses |
| 6 | **FIX-ARCH-1 Phase 2** (delete `uploadDirectly`) | Behaviour change; ship alone |
| 7 | **FIX-ARCH-2** (delete `UploadsController`), **FIX-ARCH-7** (dead code, namespaces) | Cleanup |
| 8 | **FIX-ARCH-1 Phase 3** (native connectivity signal) | Removes the last non-deterministic selector |
| 9 | Diagnosis 03 FIX-PERF-1 (replace the string bridge) | Largest architectural win; requires everything above to be stable |

**The single most important sequencing point:** perform **FIX-ARCH-1 Phase 1** (extract the shared transfer loop) *before* implementing the offline retry/resume fix from Diagnosis 02. Otherwise that fix is written three times and drifts again.

---

## 16. Definition of done

- [ ] One shared transfer module; all client uploaders call it
- [ ] `configureUploads()` is the single configuration entry point, called at bootstrap with platform values
- [ ] `uploadDirectly` deleted or reduced to a one-chunk configuration
- [ ] Each upload URI registered exactly once, with an explicit middleware decision; route-count test in CI
- [ ] `app/Http/Requests/` created; no inline `validate()` in upload controllers
- [ ] One allowlist, read by both `UploadValidationService` and `UploadService`, covering `3gp`, `heif`, `m4v`, `wmv`, `flv`
- [ ] Patient UUID used consistently at every call site; `CategoryBlock` inline list shows all uploads
- [ ] Production endpoints authenticated and rate-limited; embedded unaffected
- [ ] Stub patient/user creation gated on the embedded runtime
- [ ] `{uuid}` route parameter validated before path use; extension validated and capped
- [ ] Debug detail gated behind `hasDebugModeEnabled()`
- [ ] `received_chunks` disambiguated; `FileSyncService` resumes
- [ ] Token read per request, not at module load
- [ ] Every §8.2 dead-code item removed or wired up, decision recorded
- [ ] AC-A1 through AC-A14 pass
- [ ] `route:list` before/after diff attached to the change record
