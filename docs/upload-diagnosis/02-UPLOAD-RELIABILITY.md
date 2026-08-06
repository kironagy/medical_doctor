# Diagnosis 02 — Upload Reliability & Data Integrity

**Project:** Medical_Plus_v4 (Laravel 13 + Vue 3 + Inertia + NativePHP Mobile 3.3 / Android)
**Branch:** `pro-version`
**Scope of this document:** correctness of the upload once it is physically able to run — error semantics, HTTP status truthfulness, retry, resume, chunk-write correctness, merge validation, transaction safety, and orphan/leak handling.
**Out of scope (covered by sibling documents):** the temp-directory / PHP environment defect (01), memory and throughput (03), API surface and structural design (04).

This document is self-contained. Everything needed to implement and verify the fixes is here.

> **Context you need from outside this document, stated once so you do not have to read another file:** there is a separate P0 infrastructure defect in which `tempnam(sys_get_temp_dir(), ...)` at `app/Http/Middleware/ParseMobileMultipartMiddleware.php:188` fails on Android because `/tmp` does not exist, producing a PHP `E_NOTICE` that Laravel converts to `ErrorException` → HTTP 500. That defect is being fixed separately. **This document assumes it is fixed** and addresses the reliability defects that will remain — several of which currently *mask* that root cause and would mask the next one too.

---

## 1. Executive statement

This application is a medical records system. It handles patient imaging and video. Two properties are non-negotiable: **an upload that reports success must have stored the exact bytes**, and **an upload that fails must be recoverable**.

Neither property currently holds.

| Class | Finding | Severity |
|---|---|---|
| **Diagnosability** | Every validation error, auth error, and not-found error is delivered to the client as HTTP 500 | P0 |
| **Data loss** | Offline uploads have zero retries, zero resume, and the retry button is hidden in the UI | P0 |
| **Silent corruption** | The merge step never verifies the assembled file's size against the declared size | P0 |
| **Silent corruption** | An oversized chunk overwrites the next chunk's byte range, and nothing detects it | P1 |
| **Duplication** | `lockForUpdate()` is a no-op on SQLite, so concurrent completion creates duplicate records | P1 |
| **Never resumes** | Device→server sync reads a status key the server does not return | P1 |
| **Silent loss** | Filesystem write failures return `false` and are written into the database as success | P0 |
| **Leak** | Abandoned sessions leave full-size files on device storage forever | P2 |

---

## 2. Finding R-1 — Every error is HTTP 500 (P0)

### 2.1 Why this is the first thing to fix

The field reports show `Request failed with status code 500` for an 85.8 KB JPEG and a 144.8 KB JPEG. A 500 means "the server crashed unexpectedly". In this codebase it means nothing of the sort — it is the status returned for a missing form field, an unsupported MIME type, an expired session ID, and an unauthenticated request alike.

**Consequence:** no one — human or agent — can distinguish a client mistake from a server crash by looking at the response. Every future upload bug will present exactly as the current one does. Fixing this is a prerequisite for validating any other fix in this document.

### 2.2 Mechanism A — the global render closure

**File:** `bootstrap/app.php`, inside `->withExceptions(...)`

```php
$exceptions->render(function (\Throwable $e, Request $request) {
    $isUploadOrApi = $request->is('*upload*', '*chunk*', 'patients/*/files', '_native/*', 'api/*') ||
        $request->expectsJson() ||
        $request->wantsJson();

    if ($isUploadOrApi) {
        // ... logging ...

        $status = $e instanceof \Symfony\Component\HttpKernel\Exception\HttpExceptionInterface
            ? $e->getStatusCode()
            : 500;

        return response()->json([
            'error'        => true,
            'exception'    => get_class($e),
            'message'      => $e->getMessage(),
            'sqlstate'     => $sqlState,
            'sqlite_error' => $sqliteError,
            'file'         => $e->getFile(),
            'line'         => $e->getLine(),
            'trace'        => $e->getTraceAsString(),
        ], $status);
    }
    // ...
});
```

The comment above `$status` states the intent correctly — it was written to stop hardcoding 500. It does preserve the status of `HttpException`. **But it does not preserve the status of the three exception types Laravel converts *after* render callbacks run.**

`Illuminate\Validation\ValidationException` extends `\Exception`. It does **not** implement `HttpExceptionInterface`, does not implement `Responsable`, and has no `render()` method (`vendor/laravel/framework/src/Illuminate/Validation/ValidationException.php:9`).

Laravel 13's handler ordering (`vendor/laravel/framework/src/Illuminate/Foundation/Exceptions/Handler.php`):

```php
$e = $this->prepareException($e);                       // ValidationException passes through untouched

if ($response = $this->renderViaCallbacks($request, $e)) {   // ★ THIS CLOSURE FIRES HERE
    return $this->finalizeRenderedResponse($request, $response, $e);
}

return $this->finalizeRenderedResponse($request, match (true) {
    // ...
    $e instanceof ValidationException => $this->convertValidationExceptionToResponse($e, $request),
    // ← never reached, because the callback already returned
```

**Result — every one of these is delivered as 500:**

| Exception | Correct status | Delivered |
|---|---|---|
| `ValidationException` | 422 | **500** |
| `AuthenticationException` | 401 | **500** |
| `AuthorizationException` (no status) | 403 | **500** |
| `ErrorException` (any PHP notice/warning) | — | 500 (correct by accident) |

`ModelNotFoundException` **is** converted by `prepareException()` into a 404 `NotFoundHttpException` before the callbacks run, so it survives — *unless* a controller catches it first (Mechanism B).

### 2.3 Mechanism B — `validate()` inside the try block in `init`

**File:** `app/Http/Controllers/Api/ChunkUploadController.php`, `init()`

```php
Log::channel('upload')->info('chunk:init - ENTER Controller', ['payload' => $request->all()]);

try {
    $validated = $request->validate([              // ← INSIDE the try
        'file_name'  => 'required|string|max:255',
        'file_size'  => 'required|integer|min:1|max:5368709120',
        'mime_type'  => 'required|string|max:255',
        'patient_id' => 'required',
        'chunk_size' => 'sometimes|integer|min:1048576|max:52428800',
        'metadata'   => 'sometimes|array',
        'metadata.title'    => 'sometimes|nullable|string|max:255',
        'metadata.desc'     => 'sometimes|nullable|string|max:1000',
        'metadata.category' => 'sometimes|nullable|string|max:100',
        'metadata.date'     => 'sometimes|nullable|date',
    ]);
    Log::channel('upload')->info('chunk:init - Validation passed');

    $patient = $this->resolvePatient($request->patient_id);
    // ... session creation ...

} catch (\Throwable $e) {          // ← catches ValidationException AND HttpException(422)
    // ... builds a hand-made response ...
    return response()->json([
        'message'   => 'Failed to initialize upload: ' . $e->getMessage(),
        'error'     => $e->getMessage(),
        'exception' => get_class($e),
        // ...
    ], 500);                        // ← unconditional 500
}
```

Because the catch is `\Throwable` and the return is a hardcoded `500`, this swallows:

- `ValidationException` from the rules above;
- **`HttpException(422)` thrown by `UploadValidationService::validateInit()`** — including every unsupported-MIME rejection;
- `ModelNotFoundException`;
- `PDOException`.

### 2.4 The MIME allowlist consequence

**File:** `app/Services/Upload/UploadValidationService.php`

```php
private const ALLOWED_MIMES = [
    'image/jpeg', 'image/png', 'image/gif', 'image/webp', 'image/bmp', 'image/tiff', 'image/heic',
    'video/mp4', 'video/mpeg', 'video/quicktime', 'video/x-msvideo', 'video/webm', 'video/x-matroska',
    'application/pdf',
    'application/msword', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
    'application/vnd.ms-excel', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
    'application/vnd.ms-powerpoint', 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
    'text/plain', 'text/csv', 'text/rtf',
    'application/zip', 'application/x-rar-compressed', 'application/x-7z-compressed',
    'audio/mpeg', 'audio/wav', 'audio/aac', 'audio/flac', 'audio/ogg', 'audio/mp4',
    'application/dicom',
];

public function validateInit(array $data): void
{
    // ...
    // ── BUG-017 FIX: Actually check against ALLOWED_MIMES ────────────
    // The array existed but was never used — any MIME type was accepted.
    if (!in_array($data['mime_type'], self::ALLOWED_MIMES, true)) {
        throw new HttpException(422, 'Unsupported file type: ' . $data['mime_type']);
    }
    // ...
}
```

**Missing from the list, and all realistically produced by Android devices:**

| MIME | Source |
|---|---|
| `video/3gpp` | Low-end camera video, WhatsApp voice/video |
| `video/3gpp2` | Same |
| `image/heif` | iOS-originated images shared into the app; `image/heic` is present but `image/heif` is not |
| `video/x-ms-wmv`, `video/x-flv` | Present in `UploadService::SAFE_EXTENSIONS` but **not** in `ALLOWED_MIMES` — the two lists disagree |
| `application/octet-stream` | Android file pickers routinely return an empty or generic type |

`ChunkUploadController::init()` has a partial mitigation at lines 34–56 that repairs `application/octet-stream` **only when a recognised file extension is present**:

```php
$mimeType = $request->input('mime_type');
$fileName = $request->input('file_name');
if ($fileName && ($mimeType === 'application/octet-stream' || empty($mimeType))) {
    $ext = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
    $correctedMime = match($ext) {
        'jpg','jpeg' => 'image/jpeg', 'png' => 'image/png', 'gif' => 'image/gif',
        'webp' => 'image/webp', 'heic' => 'image/heic',
        'mp4' => 'video/mp4', 'mov' => 'video/quicktime', 'avi' => 'video/x-msvideo',
        'mkv' => 'video/x-matroska', 'webm' => 'video/webm', 'pdf' => 'application/pdf',
        default => null,        // ← .3gp, .heif, .m4v, .wmv, .flv all fall through
    };
    if ($correctedMime) { $request->merge(['mime_type' => $correctedMime]); }
}
```

`.3gp`, `.heif`, `.m4v`, `.wmv`, `.flv` are not in the `match` and not in `ALLOWED_MIMES` → `HttpException(422)` → caught by the `\Throwable` handler → **HTTP 500 "Failed to initialize upload: Unsupported file type: ..."**.

**Note:** this failure occurs at `init`, so `totalChunks` stays `0` and the UI shows no `N/M` counter. The observed `0/4` is therefore **not** this bug — but it is a second, independent source of mystery 500s that will surface as soon as a user picks a `.3gp` file.

### 2.5 Mechanism C — `findOrFail` inside the try in `chunk()` and `complete()`

**File:** `app/Http/Controllers/Api/ChunkUploadController.php`, `chunk()`

```php
public function chunk(Request $request)
{
    if ($request->hasSession()) {
        $request->session()->save();
    }
    $start = microtime(true);

    $validated = $request->validate([          // ← OUTSIDE the try (escapes to the global closure → still 500)
        'upload_id'   => 'required|string|size:36',
        'chunk_index' => 'required|integer|min:0',
        'chunk'       => 'required|file|max:51200',
        'checksum'    => 'sometimes|string|size:64',
    ]);

    try {
        $session = $this->sessionService->findOrFail($validated['upload_id']);   // ← INSIDE
        if ($request->user() && !$this->sessionService->ownedByUser($session, $request->user()->id)) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $result = $this->chunkService->storeChunk(
            $session, $request->file('chunk'), (int) $validated['chunk_index'],
            $validated['checksum'] ?? null
        );

        if ($session->total_chunks > 0) {
            $percent = (int) round((((int) $validated['chunk_index'] + 1) / $session->total_chunks) * 100);
            BackgroundSync::progress($session->original_name ?? '', min($percent, 100));
        }

        return response()->json($result)->header('X-Server-Time', round((microtime(true) - $start) * 1000, 2));

    } catch (HttpException $e) {               // ← correct: preserves 400/422 from the services
        return response()->json([
            'message'     => $e->getMessage(),
            'chunk_index' => $validated['chunk_index'] ?? null,
            'upload_id'   => $validated['upload_id'] ?? null,
        ])->setStatusCode($e->getStatusCode());

    } catch (\Throwable $e) {                  // ← swallows ModelNotFoundException → 500
        // ...
        return response()->json([ /* ... */ ], 500);
    }
}
```

`chunk()` does one thing right (`HttpException` keeps its status) and two things wrong:

1. `validate()` is outside the try, so it escapes to the global closure — which converts it to 500 anyway (§2.2). Moving it outside did not help.
2. `findOrFail()` is inside the try, so an **expired, cancelled, or unknown `upload_id`** produces `500 "Chunk upload failed: No query results for model [App\Domains\Media\Models\UploadSession]"` instead of `404`/`410`.

Sessions expire after 6 hours (`UploadSessionService.php:53`), and `uploads:purge-expired --hours=6` runs hourly (`bootstrap/app.php` schedule). **A user who resumes an upload the next morning gets a 500.** The client cannot distinguish this from a crash, so it retries — four times, re-uploading the full chunk each time (see §4.3) — and then fails permanently.

`complete()` has the same structure at the equivalent lines.

### 2.6 Client-side amplification — the message is discarded

Four different error-extraction formulas exist:

| Location | Code | Result for a 500 |
|---|---|---|
| `resources/js/Composables/useUploads.js:354` (`uploadDirectly`) | `job.error = err.message \|\| "Upload failed"` | **`"Request failed with status code 500"`** |
| `resources/js/Composables/useUploads.js:539-544` (`startUpload`) | `err.response?.data?.message \|\| err.response?.data?.error \|\| err.message \|\| "Upload failed"` | server message |
| `resources/js/Composables/useUploads.js:616-619` (`runPool`) | `errors[0].response?.data?.message \|\| errors[0].message` | server message |
| `resources/js/Composables/useOfflineUploads.js:306` | `popupJob.error = err?.message \|\| 'Upload failed'` | **`"Request failed with status code 500"`** |

**This exactly explains the field screenshots.** Images take `uploadDirectly` (non-chunked — see §3.1) and show the useless axios string; videos take the chunked pool and show the real server message `tempnam(): file created in the system's te...`. Same root cause, two different display paths.

### 2.7 Security defect in the same closure

The render closure returns `file`, `line`, and **the full stack trace** in the JSON body, unconditionally — including in production, where `APP_DEBUG=false`. This leaks absolute filesystem paths, framework internals, and the SQL error text of the production database to any client that can reach an `api/*`, `_native/*`, `*chunk*`, or `*upload*` route. It also inflates every error response to multiple kilobytes, which is transmitted 4× per failed chunk × 4 parallel chunks.

### 2.8 Required fix — FIX-REL-1 (P0)

**In `bootstrap/app.php`:** before the generic `$status` assignment, explicitly pass through the exception types Laravel would otherwise convert. At minimum:

- `ValidationException` → return `422` with the `errors` bag in the standard Laravel shape (`{ message, errors: { field: [...] } }`), so the client can display field-level messages.
- `AuthenticationException` → `401`.
- `AuthorizationException` → `403`.
- `NotFoundHttpException` / `ModelNotFoundException` → `404`.
- `TokenMismatchException` → `419`.

Gate `file`, `line`, `trace`, `sqlstate`, and `sqlite_error` behind `app()->hasDebugModeEnabled()`.

**In `ChunkUploadController::init()`:** move `$request->validate([...])` **above** the `try {`.

**In `ChunkUploadController::chunk()` and `complete()`, and the equivalent methods in `UploadsController`:** add a dedicated catch before `\Throwable`:

```php
catch (ModelNotFoundException $e) {
    return response()->json([
        'message'   => 'Upload session not found or expired',
        'upload_id' => $validated['upload_id'] ?? null,
        'code'      => 'SESSION_EXPIRED',
    ], 410);
}
```

`410 Gone` is the correct status for an expired session and is semantically distinct from a 404 for an unknown ID; either is acceptable provided it is **not** 500. Add a machine-readable `code` so the client can act on it (§4.4).

**In `UploadValidationService::ALLOWED_MIMES`:** add `video/3gpp`, `video/3gpp2`, `image/heif`, `video/x-ms-wmv`, `video/x-flv`, `video/x-m4v`. Reconcile this list with `UploadService::SAFE_EXTENSIONS` (`app/Domains/Media/Services/UploadService.php:15`) — they currently disagree, which is a structural problem addressed in Diagnosis 04. Extend the `match` in `init()` with `3gp`, `heif`, `m4v`, `wmv`, `flv`.

**In the client:** replace both bare `err.message` sites (`useUploads.js:354`, `useOfflineUploads.js:306`) with the full chain used at `useUploads.js:539`.

---

## 3. Finding R-2 — Offline uploads lose data permanently (P0)

### 3.1 The uploader-selection logic

`resources/js/Components/workspace/CategoryBlock.vue:464-484`

```js
const uploadFile = (file, patientId, options) => {
  const isOnline = syncOnline.value;
  if (!isOnline && typeof offlineUploadFile === 'function') {
    const patientUuid = selectedPatient.value?.uuid || patientId
    return offlineUploadFile(file, patientUuid, options);
  }
  if (typeof onlineUploadFile === 'function') {
    return onlineUploadFile(file, patientId, options);
  }
  return null;
}
```

`syncOnline` derives from `resources/js/Composables/useSyncEngine.js:23`:

```js
const isOnline = ref(typeof navigator !== 'undefined' ? navigator.onLine : true)
```

updated only by `window` `online`/`offline` events (`useSyncEngine.js:63-66`). **`navigator.onLine` in an Android WebView reports whether a network interface exists, not whether the internet is reachable.** A device on Wi-Fi with no upstream, on a captive portal, or on a stalled mobile connection reports `true`. The codebase acknowledges this unreliability in comments at `useOfflineUploads.js:53-59` and `AddRecordModal.vue:195-199`, yet still uses it as the selector.

**Consequence:** the offline path is entered non-deterministically. It must therefore be as robust as the online path. It is not.

### 3.2 The offline chunked uploader

`resources/js/Composables/useOfflineUploads.js:112-187`

```js
async function saveFileChunkedOffline(file, patientUuid, metadata = {}, job = null) {
  const CHUNK_SIZE = 5 * 1024 * 1024 // 5 MB chunks
  const token = typeof localStorage !== 'undefined' ? localStorage.getItem('np_api_token') : null;
  const headers = token ? { 'Authorization': 'Bearer ' + token } : {};

  // Step 1: Initialize chunk upload session
  const initRes = await axios.post('/api/v1/chunk/init', {
    file_name: file.name || 'video.mp4',
    file_size: file.size,
    mime_type: file.type || 'video/mp4',
    patient_id: patientUuid,
    chunk_size: CHUNK_SIZE,
    metadata: { /* ... */ },
  }, { headers })

  const { upload_id, chunk_size = CHUNK_SIZE, total_chunks } = initRes.data
  if (job) job.totalChunks = total_chunks

  // Step 2: Upload chunks sequentially
  for (let i = 0; i < total_chunks; i++) {
    const start = i * chunk_size
    const end = Math.min(file.size, (i + 1) * chunk_size)
    const chunkBlob = file.slice(start, end)

    const fd = new FormData()
    fd.append('upload_id', upload_id)
    fd.append('chunk_index', i)
    fd.append('chunk', chunkBlob, file.name || 'chunk')

    await axios.post('/api/v1/chunk/chunk', fd, {          // ← no retry, no catch
      headers: { 'Content-Type': 'multipart/form-data', ...headers },
      timeout: 120000,
    })

    if (job) {
      job.completedChunks.add(i)
      job.uploadedBytes = end
      job.progress = Math.round((end / file.size) * 100)
      // ... speed sampling ...
    }
  }

  // Step 3: Complete upload and merge chunks into patient_files
  const completeRes = await axios.post('/api/v1/chunk/complete', { upload_id }, { headers })
  // ...
}
```

**Defects, each independently sufficient to lose the file:**

| # | Defect | Consequence |
|---|---|---|
| 1 | **Zero retries.** The `await axios.post` has no retry wrapper and no try/catch | A single transient 500, a 120 s timeout on a slow link, or one dropped packet aborts the entire file |
| 2 | **Zero resume.** The `upload_sessions` localStorage map (`useUploads.js:21, 196-211, 451-459`) is written and read **only** by `useUploads.js`. The offline path never persists `upload_id` | On the next attempt the loop restarts at `i = 0`, re-uploading every byte |
| 3 | **No status check.** `useUploads.js:380-390` calls `GET /api/v1/chunk/{id}/status` and seeds `completedSet` from `received_chunks`. The offline path never calls it | Server-side progress is discarded even though the server retained it |
| 4 | **Bytes are never persisted.** The `File`/`Blob` exists only in the WebView heap for the duration of the call | Once the promise rejects, the source is unrecoverable without the user re-picking the file |

### 3.3 The UI hides the only recovery affordance

`resources/js/Components/UploadManager.vue:130`

```vue
<button
  v-if="upload.status === 'failed' && !upload.offline"
  @click="retryUpload(upload.id)"
  ...
>
```

Pause (`:89`) and cancel (`:99`) carry the same `&& !upload.offline` guard.

The offline job is flagged at `useOfflineUploads.js:~250`:

```js
popupJob = reactive({
  id: `offline-${Date.now()}-${Math.random().toString(36).slice(2)}`,
  // ...
  offline: true,
})
uploads.value.push(popupJob)
```

**A failed offline upload therefore has no retry, no pause, no cancel — only a red error string.** The user's only recourse is to re-select the file from the picker. If the source was a camera capture that was not saved to the gallery, **the data is gone**.

> The third field screenshot (online mode) *does* show retry icons, confirming those jobs came from `useUploads.js`. The first screenshot (offline) shows none. This is the visible signature of this defect.

### 3.4 Related: the retry endpoint does not exist

`resources/js/Composables/useOfflineUploads.js:398` calls:

```js
POST /_native/api/offline/uploads/{uuid}/retry
```

No such route is registered. `routes/web.php:333-347` defines only:

```
POST   /_native/api/offline/uploads              → Mobile\FileController@store
DELETE /_native/api/offline/uploads/{fileUuid}   → Mobile\FileController@destroy
GET    /_native/api/offline/uploads              → Mobile\FileController@pendingIndex
```

The call 404s. The render closure converts the 404 to a JSON body, so it fails quietly.

### 3.5 Required fix — FIX-REL-2 (P0)

`useOfflineUploads.saveFileChunkedOffline` must reach parity with `useUploads.uploadChunk`:

1. **Retry with backoff.** Wrap each chunk POST in the same `MAX_RETRIES = 3` loop with `RETRY_BASE_MS = 500`, cap 4000 ms, as `useUploads.js:639-650`. **Retry only on 5xx and network/timeout errors** — see §4.3 for why 4xx must not be retried.
2. **Persist the session.** Write `{ upload_id, file_name, file_size, patient_id, total_chunks, status }` into the same `upload_sessions` localStorage map, keyed by `fileKey(f)` (`useUploads.js:203-206`: `${f.name}_${f.size}_${f.lastModified}`). Reuse the existing `loadPersisted()` / `savePersisted()` helpers rather than adding a second store.
3. **Resume on start.** Before `init`, look up the persisted entry and call `GET /api/v1/chunk/{upload_id}/status`; seed the completed set from `received_chunks` and skip those indices.
4. **Show the controls.** Remove `&& !upload.offline` from the retry, pause, and cancel guards in `UploadManager.vue`, and route `retryUpload` for offline jobs to the offline resume function.
5. **Remove or implement** the dead `/retry` endpoint call at `useOfflineUploads.js:398`.

**Ordering note:** step 3 depends on FIX-REL-1, because an expired session currently returns 500 rather than 410 — a resume attempt against an expired session would look like a server crash and the client could not fall back to a fresh `init`. Implement FIX-REL-1 first.

---

## 4. Finding R-3 — Silent corruption in the chunk write and merge path (P0/P1)

### 4.1 The merge never verifies the assembled size

**File:** `app/Domains/Media/Services/ChunkMergeService.php`

```php
$patientFile = DB::transaction(function () use ($session) {
    $locked = UploadSession::where('id', $session->id)->lockForUpdate()->firstOrFail();
    $this->validationService->validateComplete($locked);

    $disk = Storage::disk($locked->disk);
    $patient = $locked->patient;

    $fileUuid = (string) Str::uuid();
    $extension = $locked->extension;

    // Determine final file path
    if ($locked->final_path) {
        $finalRelPath = $locked->final_path;
        $finalAbsPath = $disk->path($finalRelPath);
        // Verify final file exists and size matches            ← comment claims a size check
        if (!file_exists($finalAbsPath)) {
            throw new RuntimeException("Final file not found after direct write: {$finalAbsPath}");
        }
        $size = filesize($finalAbsPath) ?: 0;
        if ($size === 0) {                                       ← the ONLY size assertion
            throw new RuntimeException("Direct-write file is empty: {$finalAbsPath}");
        }
    } else {
        // Legacy: perform actual merge from temporary chunks
        // ...
    }
```

**The comment says "size matches". The code only checks `!== 0`.** `$locked->total_size` — the client-declared size, stored at `init` — is never compared against `filesize($finalAbsPath)`.

The only completeness gate is a **row count**:

`app/Services/Upload/UploadValidationService.php`

```php
public function validateComplete(UploadSession $session): void
{
    if ($session->status !== 'uploading') {
        throw new HttpException(400, 'Session is not in uploading state');
    }

    // Use DB count for race-safe verification
    $receivedCount = DB::table('upload_chunk_receipts')
        ->where('session_id', $session->id)
        ->count();

    if ($receivedCount < $session->total_chunks) {
        $missing = $session->total_chunks - $receivedCount;
        throw new HttpException(400, "Missing {$missing} chunk(s)");
    }
}
```

A receipt row is inserted **after** a chunk write returns (`ChunkUploadService.php:57`). It records *that a write was attempted and returned*, not *that the correct bytes landed*. A short write, a partial `fwrite`, or a truncated body all still produce a receipt.

**Failure scenario:** the last chunk's body is truncated in transit (WebView shim drops bytes, or `post_max_size` silently truncates). `fwrite` writes fewer bytes than expected but returns without error. The receipt is inserted. `validateComplete` counts 4 of 4. `filesize` returns non-zero. The file is written to `patient_files` with `upload_status = 'ready'` and `size = filesize(...)` — a **truncated medical video, marked as successfully stored**, with no error anywhere.

The final `PatientFile::create` records the truncated size rather than the declared one, so even a later size comparison against `total_size` would not detect it from the row alone:

```php
$patientFile = PatientFile::create([
    'uuid'           => $fileUuid,
    'patient_id'     => $locked->patient_id,
    'uploaded_by_id' => $uploadedById,
    // ...
    'size'           => $size ?? $locked->total_size,      // ← records the *actual* size, hiding the discrepancy
    'file_path'      => $finalRelPath,
    'upload_status'  => 'ready',
    'sync_status'    => config('database.default') === 'sqlite' ? 'pending_sync' : 'synced',
]);
```

### 4.2 The checksum is never computed on the primary path

`ChunkMergeService` calls:

```php
$this->sessionService->markCompleted($locked->uuid, $finalHash ?? null);
```

`$finalHash` is assigned **only in the legacy branch** (the `else` that streams chunk files together). On the direct-write path — which is the path taken whenever `final_path` is set, i.e. whenever the patient UUID resolved at `init`, i.e. essentially always — `$finalHash` is undefined and `null` is passed.

`upload_sessions.final_checksum` (`VARCHAR(64)`, migration `2026_07_01_000001_create_upload_sessions_table.php:23`) is therefore **never populated for the primary upload path**. There is no end-to-end integrity check of any kind.

Per-chunk checksums are supported but optional:

`app/Services/Upload/ChunkUploadService.php:36-42`
```php
if ($clientChecksum !== null) {
    $serverChecksum = $this->checksumService->chunkChecksum($chunk);
    if ($serverChecksum !== $clientChecksum) {
        throw new HttpException(400, 'Chunk checksum mismatch');
    }
}
```

**No client sends `checksum`.** Verified: neither `useUploads.js:uploadChunk` (which appends only `upload_id`, `chunk_index`, `chunk`) nor `useOfflineUploads.js` nor `FileSyncService::uploadLargeFileResumable` populates it. The verification code exists and is dead.

### 4.3 An oversized chunk silently overwrites the next chunk's bytes

Two facts that contradict each other:

**Fact 1 — the write offset is computed from the *declared* chunk size:**

`app/Services/Upload/ChunkUploadService.php:98-153` (`writeChunkDirect`)

```php
$disk = Storage::disk($session->disk);
$finalRelPath = $session->final_path;
$finalAbsPath = $disk->path($finalRelPath);
$offset = $chunkIndex * $session->chunk_size;      // ← fixed stride

$dir = dirname($finalAbsPath);
if (!is_dir($dir)) {
    mkdir($dir, 0755, true);
}

$fp = fopen($finalAbsPath, 'c+');
if (!$fp) { throw new HttpException(500, "Cannot open file for writing"); }

if (fseek($fp, $offset) !== 0) { fclose($fp); throw new HttpException(500, "Failed to seek in file"); }

$tmpPath = $chunk->getRealPath();
$input = fopen($tmpPath, 'rb');
if (!$input) { fclose($fp); throw new HttpException(500, "Cannot open chunk for reading"); }

$bufferSize = 4 * 1024 * 1024;
while (!feof($input)) {
    $buffer = fread($input, $bufferSize);
    // ... partial-write loop ...
    $written = fwrite($fp, substr($buffer, $pos));
    // ...
}
fclose($input);
fflush($fp);
fclose($fp);
```

**Fact 2 — the accepted chunk size is larger than the stride:**

`app/Services/Upload/UploadValidationService.php`

```php
public function validateChunk(UploadSession $session, UploadedFile $chunk, int $chunkIndex): void
{
    if ($session->status !== 'pending' && $session->status !== 'uploading') {
        throw new HttpException(400, 'Session is not active');
    }
    if ($chunkIndex < 0 || $chunkIndex >= $session->total_chunks) {
        throw new HttpException(422, "Invalid chunk index {$chunkIndex}");
    }
    if ($chunk->getError() !== UPLOAD_ERR_OK) {
        throw new HttpException(422, 'Chunk upload error');
    }
    $maxAllowed = $session->chunk_size + 1048576; // 1MB tolerance for WebView / MediaStore changes
    if ($chunk->getSize() > $maxAllowed) {
        throw new HttpException(422, "Chunk {$chunkIndex} exceeds expected size");
    }
}
```

A chunk of up to `chunk_size + 1 MiB` is accepted and written at offset `chunkIndex * chunk_size`. **Any chunk exceeding `chunk_size` overwrites up to 1 MiB of chunk N+1's region.**

If chunk N+1 was already written and its receipt already recorded, the corruption is invisible: `validateComplete` counts receipts, and `merge()` checks only `size !== 0`. The file is stored as `ready` with 1 MiB of wrong bytes in the middle.

The 1 MiB tolerance was presumably added to work around WebView/MediaStore size discrepancies. **That is the wrong remedy** — if the client's chunk size and the server's stride can diverge, the write must use the actual received length, not a tolerance on validation. There is no scenario in which writing an oversized chunk at a fixed stride is correct.

### 4.4 `mkdir` TOCTOU under concurrency

`ChunkUploadService.php:106-108`

```php
if (!is_dir($dir)) {
    mkdir($dir, 0755, true);
}
```

Two parallel requests for the first chunks of a new patient both evaluate `!is_dir($dir)` as true, and both call `mkdir(..., recursive: true)`. The loser receives `E_WARNING: mkdir(): File exists`, which `HandleExceptions::handleError()` converts to a thrown `ErrorException` — **before** any of the `HttpException(500, ...)` guards below it can run. There is no `@` suppression and no `is_dir()` recheck.

The client-side pool is `POOL_SIZE = 4` (`useUploads.js:18`), so this window is reachable on the online path. On-device it is currently masked by `g_php_request_mutex` in `php_bridge.c:271`, which serialises all PHP requests — but that mutex is a device-runtime artifact, not a guarantee, and it does not exist on the production MySQL server where the same endpoints are exposed.

### 4.5 Legacy chunk staging uses a non-unique filename

`ChunkUploadService.php:156-171` (`writeChunkLegacy`, used when `final_path` is null)

```php
$chunkPath = "{$chunkDir}/{$chunkIndex}";
$tmpPath   = "{$chunkDir}/_{$chunkIndex}.tmp";

$chunk->storeAs(dirname($chunkPath), basename($tmpPath), $session->disk);
if ($disk->exists($tmpPath)) { $disk->move($tmpPath, $chunkPath); }
```

`_{$chunkIndex}.tmp` is identical for every attempt at that index. A retry racing the original writes the same staging path; whichever `move`s second gets `false` (the disk has `'throw' => false`, `config/filesystems.php:37`) and is silently ignored, leaving the chunk possibly half-written. Both return values are discarded.

### 4.6 Required fixes — FIX-REL-3 (P0/P1)

1. **Verify the final size (P0).** In `ChunkMergeService`, after the `filesize()` call, assert `$size === (int) $locked->total_size`. On mismatch, throw and mark the session failed — do **not** create a `PatientFile` row. Extend the same check to the legacy branch.
2. **Compute and store the final checksum (P0).** Run `hash_file('sha256', $finalAbsPath)` on the direct-write path and persist it to `upload_sessions.final_checksum` and `patient_files.sha256` (the column exists — migration `2026_08_02_000001`, indexed). This is what makes later corruption detectable at all.
3. **Remove the 1 MiB tolerance (P1).** Require `$chunk->getSize() === $session->chunk_size` for every index except the last, and `=== $totalSize - (lastIndex * chunkSize)` for the last. Reject anything else with `422`. If the WebView genuinely produces variable sizes, fix the client rather than widening the server's acceptance — an offset-addressed write cannot tolerate variable-length chunks.
4. **Fix the `mkdir` race (P1).** Replace with a suppressed `mkdir` plus an `is_dir()` recheck, or use `Storage::disk(...)->makeDirectory()` which handles the case internally. Do not leave a bare `mkdir` that can raise `E_WARNING`.
5. **Make the legacy staging name unique (P2).** Include a random suffix or the request ID.
6. **Consider enabling per-chunk checksums (P2).** The server support at `ChunkUploadService.php:36-42` is complete; the clients simply need to send `checksum`. Compute it with `crypto.subtle.digest('SHA-256', ...)` on the sliced blob. Note the CPU cost on low-end devices — measure before enabling by default, and consider enabling only for chunks that failed once.

---

## 5. Finding R-4 — Concurrency and transaction safety (P1)

### 5.1 `lockForUpdate()` is a no-op on SQLite

`ChunkMergeService` opens with:

```php
$patientFile = DB::transaction(function () use ($session) {
    $locked = UploadSession::where('id', $session->id)->lockForUpdate()->firstOrFail();
```

`vendor/laravel/framework/src/Illuminate/Database/Query/Grammars/SQLiteGrammar.php:31`

```php
protected function compileLock(Builder $query, $value) { return ''; }
```

**SQLite compiles `FOR UPDATE` to an empty string.** The same applies to:

| Call site | File:line |
|---|---|
| Merge session lock | `ChunkMergeService.php:31` |
| `ensureUploading` | `UploadSessionService.php:95` |
| `markCompleted` | `UploadSessionService.php:113` |
| `markFailed` | `UploadSessionService.php:128` |

On device, two concurrent `POST /chunk/complete` for the same `upload_id` both pass `validateComplete()` and both execute `PatientFile::create()` → **two rows for one file**.

This is currently masked by `g_php_request_mutex` (`php_bridge.c:271`), which serialises every PHP request on the device. That mask does **not** apply on the production MySQL server, where `lockForUpdate` is real — so production is safe for the wrong reason and device is unsafe for a reason that could disappear with any runtime change.

### 5.2 `ensureUploading` has a stale fast path

`UploadSessionService.php:86-99`

```php
public function ensureUploading(UploadSession $session): UploadSession
{
    if ($session->status === 'uploading') { return $session; }     // ← in-memory model, may be stale

    return DB::transaction(function () use ($session) {
        $locked = UploadSession::where('id', $session->id)->lockForUpdate()->first();
        // ...
        if ($locked->status === 'pending') { $locked->update(['status' => 'uploading']); }
        // ...
    });
}
```

The fast path reads the status from the in-memory model loaded earlier in the request. If another request transitioned the session to `failed` or `cancelled` in the interim, this returns a stale `uploading` and the chunk is written into a session that should be rejected.

### 5.3 SQLite transaction mode can fail the merge outright

`config/database.php:35-57`

```php
'sqlite' => [
    'driver'                  => 'sqlite',
    'database'                => env('DB_DATABASE', database_path('database.sqlite')),
    'foreign_key_constraints' => env('DB_FOREIGN_KEYS', true),
    'busy_timeout'            => 5000,
    'journal_mode'            => 'wal',
    'synchronous'             => 'NORMAL',
    'transaction_mode'        => 'DEFERRED',
],
```

`DEFERRED` means `ChunkMergeService`'s transaction begins as a reader and upgrades to a writer at its first write. If another connection — the background sync worker runs in a **separate PHP runtime** (`LaravelEnvironment.kt` boots one) — committed in the interim, SQLite returns **`SQLITE_BUSY_SNAPSHOT`**. `busy_timeout` does **not** retry that error class; it fails immediately with `database is locked`, which is caught by `catch (\Throwable)` in `complete()` and returned as **500 "Upload completion failed: ... database is locked"**.

This is a realistic race: a user completing an upload while a scheduled sync is running.

### 5.4 Receipts have no foreign key and outlive their session

`database/migrations/2026_07_05_000002_create_upload_chunk_receipts_table.php:12-19`

```php
$table->id();
$table->unsignedBigInteger('session_id');        // ← no foreign key, no cascade
$table->unsignedInteger('chunk_index');
$table->timestamp('received_at')->default(DB::raw('CURRENT_TIMESTAMP'));
$table->unique(['session_id', 'chunk_index']);   // makes insertOrIgnore idempotent — this part is correct
$table->index(['session_id', 'received_at']);
```

`UploadCleanupService::purgeByUuid()` calls `$session->delete()` and leaves the receipts behind. Because SQLite reuses `rowid` values after a delete, a future session can be assigned a `session_id` that already has receipt rows — and `validateComplete()` would then count those stale receipts and **pass an upload for which no chunk was ever received**, producing a `PatientFile` row for a file that does not exist.

The probability is low but the failure is silent and catastrophic. Add the foreign key with `cascadeOnDelete`, or delete receipts explicitly in both cleanup methods.

### 5.5 `fflush` without `fsync`

`ChunkUploadService.php:152-153`

```php
fflush($fp);
fclose($fp);
```

`fflush` pushes PHP's userspace buffer to the OS; it does not force the OS page cache to disk. A device power loss or kernel panic between the last chunk write and the merge can leave a file whose tail is missing while the receipts and the `patient_files` row both indicate success. For a medical record this warrants an `fsync` on the final chunk, accepting the latency cost.

### 5.6 The `finally` block in `complete()` misfires

`ChunkUploadController::complete()`

```php
} finally {
    if (isset($e)) { BackgroundSync::stop('فشل رفع الملف'); }
}
```

`$e` is bound by any of the catch blocks. The `HttpException` catch returns before the `finally` runs — but `finally` still executes on the way out, so a legitimate 4xx (e.g. "Missing 1 chunk(s)") fires the *failure* notification. On the success path `$e` is unset, so nothing happens, but `BackgroundSync::stop('اكتمل...')` was already called earlier in the method. The net effect is a user-visible failure notification for recoverable states.

### 5.7 Required fixes — FIX-REL-4 (P1)

1. Replace `lockForUpdate()`-based mutual exclusion on SQLite with an **idempotency guarantee that does not depend on row locking**: a unique index on `upload_sessions.uuid` combined with a conditional status transition (`UPDATE upload_sessions SET status='completed' WHERE uuid=? AND status='uploading'`) and an affected-row-count check. If zero rows were affected, another request already completed the session — return that session's existing `PatientFile` rather than creating a second one.
2. Remove the stale fast path in `ensureUploading` or re-read the model before trusting it.
3. Handle `SQLITE_BUSY` / `SQLITE_BUSY_SNAPSHOT` explicitly: catch the `PDOException`, inspect `errorInfo`, and return **503 with `Retry-After`** so the client backs off and retries rather than treating it as a crash. Consider `transaction_mode = IMMEDIATE` for the merge transaction specifically.
4. Add the foreign key (or explicit cleanup) for `upload_chunk_receipts`.
5. Add `fsync` after the final chunk write.
6. Fix the `finally` block so it only fires on genuine failure.

---

## 6. Finding R-5 — Device→server sync never resumes (P1)

**File:** `app/Services/Sync/FileSyncService.php:190-196`

```php
// Step 2: Check uploaded chunks status (Resumable check)
$uploadedChunks = [];
try {
    $statusRes = $this->api->get("/chunk/{$uploadId}/status", [], 60);
    $uploadedChunks = $statusRes['uploaded_chunks'] ?? [];       // ← wrong key
} catch (Throwable $e) {
    // New upload session
}
```

The server returns `received_chunks`, not `uploaded_chunks`:

`app/Services/Upload/ChunkUploadService.php:219`
```php
'received_chunks'    => $received,
```

`$uploadedChunks` is therefore **always `[]`**, and the skip branch never fires:

```php
for ($chunkIndex = 0; $chunkIndex < $totalChunks; $chunkIndex++) {
    if (in_array($chunkIndex, $uploadedChunks)) {      // ← never true
        fseek($handle, ($chunkIndex + 1) * self::CHUNK_SIZE);
        continue;
    }
    // ... upload ...
}
```

**Every sync retry re-uploads the entire file from chunk 0.** On a slow or intermittent connection this becomes an unbounded loop of full-file uploads, consuming the user's data allowance and never converging. Combined with `RemoteApiService::buildClient()`'s `->retry(2, 500)` and `timeout: 300` (§`RemoteApiService.php:107, 191-201`), a single chunk can occupy up to 15 minutes before failing.

The JS client reads the key correctly (`useUploads.js:388`: `new Set(r.data.received_chunks || [])`), which is why online resume works and server-side sync does not.

**Note a second inconsistency in the same API:** `ChunkUploadService.php:82` returns `'received_chunks' => $receivedCount` (an **integer**) from `storeChunk`, while `:219` returns `'received_chunks' => $received` (an **array**) from `getStatus`. The same key name carries two different types on two endpoints. Any consumer that does not know which endpoint it called will break. This is a design defect covered in Diagnosis 04, but it is worth fixing in the same change.

**Fix — FIX-REL-5 (P1):** change `FileSyncService.php:193` to read `received_chunks`. Add a feature test asserting that a sync interrupted after chunk 2 resumes at chunk 2. Rename one of the two `received_chunks` fields so the types no longer collide.

---

## 7. Finding R-6 — Storage failures are written into the database as success (P0)

`config/filesystems.php:33-39`

```php
'local' => [
    'driver' => 'local',
    'root'   => storage_path('app/private'),
    'serve'  => true,
    'throw'  => false,      // ← write failures return false
    'report' => false,      // ← and are not logged
],
```

Call sites that discard the return value:

| File:line | Code |
|---|---|
| `app/Domains/Media/Services/UploadService.php:62` | `$disk->putFileAs("patients/{$patientUuid}", $file, $fileName);` |
| `app/Domains/Media/Services/UploadService.php:79` | `'file_path' => $relPath` written unconditionally afterwards |
| `app/Http/Controllers/Api/Mobile/FileController.php:158-162` | `$uploadedFile->storeAs(...)` — result can be `false`, written into a `NOT NULL VARCHAR(255)` column |
| `app/Services/Upload/ChunkUploadService.php:167-169` | `storeAs(...)` and `$disk->move(...)` both discarded |

**Failure scenario:** device storage fills during a large video upload. `putFileAs` returns `false`. `UploadService` proceeds to `PatientFile::create([... 'file_path' => $relPath, 'upload_status' => 'ready'])`. The application now believes a patient's scan is stored. It is not. Nothing logged, nothing raised.

Additional related defect in the same file:

`UploadService.php:56`
```php
$patientUuid = Patient::where('id', $patientId)->value('uuid');
```

This query **applies the `DoctorIsolationScope` global scope**. If the current user is not the patient's doctor (or there is no authenticated user, which is the norm on device), `$patientUuid` returns `null` and the path becomes `patients//{uuid}.ext` — a double slash, writing into a directory that does not correspond to any patient. Compare `ChunkUploadController::resolvePatient()` which uses `Patient::withoutGlobalScopes()`.

**Fix — FIX-REL-6 (P0):** covered jointly with Diagnosis 01 §9.3 (set `'throw' => true` or check every return). In addition, wrap `storeAs` + `PatientFile::create` in a transaction so a DB failure does not leave an orphan file and a disk failure does not leave an orphan row (`Mobile\FileController::store` currently has neither). Add `withoutGlobalScopes()` to the `UploadService.php:56` lookup.

---

## 8. Finding R-7 — Orphaned files and rows (P2)

### 8.1 Abandoned direct-write sessions leak full-size files

`app/Services/Upload/UploadCleanupService.php`

```php
public function purgeExpired(): int
{
    $expired = UploadSession::where('expires_at', '<', now())
        ->whereIn('status', ['pending', 'uploading'])
        ->get();

    $count = 0;
    foreach ($expired as $session) {
        $disk = Storage::disk($session->disk);
        $chunkDir = $session->chunkDir();
        if ($disk->exists($chunkDir)) {
            $disk->deleteDirectory($chunkDir);
        }
        $session->update(['status' => 'expired']);
        $count++;
    }
    return $count;
}

public function purgeByUuid(string $uuid): bool
{
    $session = UploadSession::where('uuid', $uuid)->first();
    if (!$session) return false;

    $disk = Storage::disk($session->disk);
    $chunkDir = $session->chunkDir();
    if ($disk->exists($chunkDir)) {
        $disk->deleteDirectory($chunkDir);
    }
    $session->delete();
    return true;
}
```

Both methods delete `chunkDir()` (`tmp/chunks/{uuid}`, `UploadSession.php:62-65`) and **neither deletes `final_path`**. The direct-write path — the primary path — never uses `chunkDir()`; it writes straight to `patients/{patientUuid}/{fileUuid}.{ext}`.

**Every abandoned or expired direct-write session leaves a full-size partial file on device storage forever.** A user who starts and cancels three 100 MB video uploads permanently loses 300 MB of device storage with no UI indication and no cleanup path. Over months on a device with limited storage this is a support incident, and it eventually causes the disk-full scenario in §7.

Neither method deletes the associated `upload_chunk_receipts` rows (§5.4).

### 8.2 Orphaned localStorage keys

`useUploads.js` writes a resume entry per file (`:451-459`) and removes it only on success (`:528`) or explicit cancel (`:736-741`). A failed or abandoned upload leaves the key permanently. `savePersisted` swallows quota errors silently (`catch {}`, `:206-211`), so once the key grows past quota, **resume silently stops working for all uploads** with no error surfaced.

Entries are metadata only (no file bytes), so the absolute size is small — but there is no eviction, and the silent `catch {}` means the failure mode is invisible.

**Fix — FIX-REL-7 (P2):** delete `final_path` in both cleanup methods; delete receipts; add TTL-based eviction of `upload_sessions` localStorage keys and log (do not swallow) quota errors.

---

## 9. Finding R-8 — Schema constraints that surface as 500s (P2)

| # | Constraint | Violated by | Result |
|---|---|---|---|
| 1 | `upload_sessions.extension` is `VARCHAR(20)` (migration `2026_07_01_000001:18`) | `UploadSessionService.php:24-25` takes the extension from the raw client filename with only `strtolower(trim(...))`, no length cap | On MySQL: `Data too long for column 'extension'` → `PDOException` → 500 from `init`. On SQLite: silently accepted (no length enforcement) — **the two platforms diverge**, so this cannot be caught on device |
| 2 | `patient_files.uploaded_by_id` is a `NOT NULL` FK to `users` (migration `2026_06_29_144925:17`) | `app/Http/Controllers/Api/UploadController.php:33` passes `uploaderId: auth()->id()`, which is `null` for an unauthenticated request | FK violation → `PDOException` → 500 |
| 3 | `patient_files.file_path` is `NOT NULL VARCHAR(255)` | `storeAs`/`putFileAs` returning `false` (§7) | `false` cast to `''` written into the column |
| 4 | `patient_files.title` is `VARCHAR(255)` | `Mobile/FileController.php:182` writes `getClientOriginalName()` | Long filenames overflow on MySQL |

**Fix — FIX-REL-8 (P2):** cap `extension` at 20 characters (and validate it against the allowlist) in `UploadSessionService`; resolve `uploaderId` with the same fallback chain used at `ChunkMergeService` (`$locked->user_id ?: $patient->primary_doctor_id ?? User::value('id') ?? 1`); truncate or validate `title`.

---

## 10. Call graph — chunked upload, success path

```
useUploads.startUpload(job)                                  [useUploads.js:359]
  ├── isVideo? no  → uploadDirectly(job)                     [:297]  ── single POST, no retry, no timeout
  └── isVideo? yes
        ├── loadPersisted() → GET /api/v1/chunk/{id}/status  [:380]  ── resume seed (online only)
        ├── POST /api/v1/chunk/init                          [:413]
        │     └── ChunkUploadController::init()
        │           ├── mime correction                       [:34-56]
        │           ├── $request->validate()   ★ inside try → 500 on failure
        │           ├── resolvePatient()                      [:406-477]
        │           └── UploadSessionService::create()
        │                 └── UploadValidationService::validateInit()  → HttpException(422) → swallowed → 500
        │
        ├── runPool(job)                                     [:567]
        │     └── ×4 concurrent → uploadChunk(job, i)        [:625]
        │           └── POST /api/v1/chunk/chunk  (timeout 300 s, 3 retries, blind to status)
        │                 └── ChunkUploadController::chunk()
        │                       ├── validate()  ★ outside try → escapes → global closure → 500
        │                       ├── findOrFail()  ★ inside try → ModelNotFound → 500
        │                       └── ChunkUploadService::storeChunk()
        │                             ├── ensureUploading()   ★ stale fast path
        │                             ├── validateChunk()     ★ +1 MiB tolerance
        │                             ├── writeChunkDirect()
        │                             │     ├── mkdir()       ★ TOCTOU → ErrorException → 500
        │                             │     ├── fopen 'c+'    ── no flock
        │                             │     ├── fseek(chunkIndex * chunk_size)   ★ fixed stride
        │                             │     └── fwrite loop / fflush  ── no fsync
        │                             └── recordChunkReceipt()  ── insertOrIgnore, idempotent ✓
        │
        └── POST /api/v1/chunk/complete                      [:488]
              └── ChunkUploadController::complete()
                    └── ChunkMergeService::merge()
                          └── DB::transaction
                                ├── lockForUpdate()  ★ no-op on SQLite
                                ├── validateComplete()  ── counts receipts only
                                ├── filesize() !== 0    ★ no comparison to total_size
                                ├── PatientFile::create(upload_status='ready')
                                ├── markCompleted(uuid, null)  ★ checksum never computed
                                └── GenerateThumbnailJob::dispatch()  ── sync queue, inside the transaction
```

★ marks a defect described in this document.

---

## 11. Affected files — consolidated

| File | Lines | Findings |
|---|---|---|
| `bootstrap/app.php` | `withExceptions` render closure | R-1 (status mapping, trace leak) |
| `app/Http/Controllers/Api/ChunkUploadController.php` | 34-56, 62-74, 154-197, 200-245, 278-352, 406-477 | R-1 (validate in try, findOrFail in try, finally block), R-4 |
| `app/Http/Controllers/Api/UploadsController.php` | 34-45, 151-180, 268-295, 353-383 | R-1 (identical defects in the twin controller) |
| `app/Http/Controllers/Api/UploadController.php` | 19-33, 63, 84 | R-8 (`auth()->id()` null) |
| `app/Http/Controllers/Api/Mobile/FileController.php` | 101-107, 119-123, 158-162, 182, 188-196 | R-6 (no transaction, discarded return), R-8 |
| `app/Services/Upload/UploadValidationService.php` | 13-48, 50-65, 67-82 | R-1 (MIME list), R-3 (1 MiB tolerance, receipt-count-only completeness) |
| `app/Services/Upload/ChunkUploadService.php` | 20-96, 98-154, 156-171, 219 | R-3 (offset/TOCTOU/fsync/legacy naming), R-5 (key type collision) |
| `app/Domains/Media/Services/ChunkMergeService.php` | 30-50, 155-200 | R-3 (no size check, no checksum), R-4 (lock no-op) |
| `app/Services/Upload/UploadSessionService.php` | 21-35, 39-71, 86-99, 113, 128 | R-3 (extension length), R-4 (stale fast path, lock no-ops) |
| `app/Services/Upload/UploadCleanupService.php` | 10-27, 29-42 | R-7 (never deletes `final_path` or receipts) |
| `app/Services/Sync/FileSyncService.php` | 190-196 | R-5 (`uploaded_chunks` vs `received_chunks`) |
| `app/Domains/Media/Services/UploadService.php` | 56, 62, 79 | R-6 (global scope, discarded return) |
| `config/filesystems.php` | 33-39 | R-6 (`throw => false`) |
| `config/database.php` | 35-57 | R-4 (`DEFERRED` transaction mode) |
| `database/migrations/2026_07_05_000002_create_upload_chunk_receipts_table.php` | 12-19 | R-4 (no FK) |
| `database/migrations/2026_07_01_000001_create_upload_sessions_table.php` | 18, 23 | R-8 (`extension` VARCHAR(20)), R-3 (`final_checksum` unused) |
| `resources/js/Composables/useOfflineUploads.js` | 112-187, 223-310, 398 | R-2 (no retry/resume, message discarded, dead endpoint) |
| `resources/js/Composables/useUploads.js` | 354, 388, 451-459, 528, 639-650 | R-1 (message discarded), R-2 (resume store), R-3 |
| `resources/js/Components/UploadManager.vue` | 89, 99, 130, 181 | R-2 (controls hidden for offline jobs) |
| `resources/js/Composables/useSyncEngine.js` | 23, 63-66 | R-2 (unreliable online detection selects the fragile uploader) |

---

## 12. Risks and edge cases

| # | Risk / edge case | Notes |
|---|---|---|
| 1 | Returning 422 instead of 500 changes client behaviour | The retry loop at `useUploads.js:639-650` currently retries **all** errors. Once statuses are truthful, it must stop retrying 4xx — otherwise a permanent rejection still costs 4 full chunk uploads. Fix both together |
| 2 | Adding a strict size assertion in `merge()` rejects previously-"successful" uploads | Intentional. But existing `patient_files` rows may already be truncated. Ship a one-off audit query (§13.5) before enabling, so pre-existing corruption is identified rather than silently inherited |
| 3 | Removing the 1 MiB tolerance may reject real WebView chunks | Measure actual chunk sizes on-device first. If they genuinely vary, the correct fix is on the client (slice deterministically) or a length-aware server write — **not** widening the tolerance |
| 4 | Enabling per-chunk SHA-256 on low-end devices | Measure. `crypto.subtle.digest` on 5 MB is fast on modern hardware and noticeably slow on very old devices. Consider enabling only on retry |
| 5 | Adding the receipts FK on an existing database | Orphan receipt rows from past sessions will block the constraint. Ship a data-cleanup migration first |
| 6 | `transaction_mode = IMMEDIATE` increases lock contention | Scope it to the merge transaction only, not globally |
| 7 | Hiding `trace` in production removes a debugging aid | Keep the full detail in the server log (already present via `Log::error('UPLOAD/API EXCEPTION RESPONSE', ...)`); remove it only from the HTTP body |
| 8 | Showing retry/cancel for offline jobs | `retryUpload()` in `useUploads.js` does not know how to resume an offline job. The button must be wired to the offline resume function, not just unhidden |
| 9 | An expired session now returns 410 | The client must handle 410 by discarding the persisted `upload_id` and calling `init` afresh — otherwise it loops on a dead session |
| 10 | Duplicate `PatientFile` rows already exist in the field | Ship a detection query (§13.5) alongside the idempotency fix |

---

## 13. Testing plan

### 13.1 Current state

Existing coverage is a single test — `tests/Feature/ChunkUploadInitTest.php` (56 lines) — which asserts that `POST /api/v1/chunk/init` returns 200 for an unknown patient UUID and creates a stub patient with `primary_doctor_id` set. It covers none of the findings in this document: no chunk upload, no merge, no error-status assertions, no resume.

`app/Http/Requests` does not exist and `grep -rl "FormRequest" app/` returns nothing — all validation is inline in controllers. Every test below is new.

> **Note for whoever implements FIX-REL-1:** `ChunkUploadInitTest` asserts `assertStatus(200)` on the happy path only, so it will not catch a regression in error-status mapping. It will, however, **break** if stub-patient creation is removed — see Diagnosis 04, which questions whether `init` should be creating patients at all. Coordinate before changing that behaviour.

### 13.2 Feature tests — error semantics (guards R-1)

| Test | Assertion |
|---|---|
| `init_returns_422_for_missing_file_name` | `assertStatus(422)`, `assertJsonValidationErrors('file_name')` |
| `init_returns_422_for_unsupported_mime` | POST `mime_type: 'video/3gpp'` → **422**, not 500 |
| `init_accepts_3gp_after_allowlist_fix` | POST `.3gp` → 200 |
| `init_accepts_heif` | POST `image/heif` → 200 |
| `init_accepts_octet_stream_with_known_extension` | `application/octet-stream` + `file_name: 'a.mp4'` → 200 |
| `chunk_returns_410_for_expired_session` | Expire the session, POST a chunk → **410**, body contains `code: SESSION_EXPIRED` |
| `chunk_returns_410_for_unknown_upload_id` | Random UUID → 410/404, not 500 |
| `chunk_returns_422_for_out_of_range_index` | `chunk_index = 99` of 4 → 422 |
| `unauthenticated_api_request_returns_401` | Not 500 |
| `error_response_omits_trace_when_debug_disabled` | `config(['app.debug' => false])` → response JSON has no `trace`, `file`, `line` |
| `error_response_includes_trace_when_debug_enabled` | Inverse |

### 13.3 Feature tests — data integrity (guards R-3, R-4)

| Test | Assertion |
|---|---|
| `complete_rejects_truncated_file` | Write chunks totalling less than `total_size`, insert all receipts manually, call `complete` → non-2xx; **no** `patient_files` row created |
| `complete_stores_sha256` | Successful upload → `upload_sessions.final_checksum` and `patient_files.sha256` are non-null and match `hash_file('sha256', $path)` |
| `oversized_chunk_is_rejected` | Chunk of `chunk_size + 1` bytes → 422; assert the next chunk's byte range is unmodified |
| `chunk_size_matches_exactly_for_non_final_chunks` | Any deviation → 422 |
| `final_chunk_may_be_shorter` | Last chunk of `total_size % chunk_size` bytes → accepted |
| `concurrent_complete_creates_one_patient_file` | Two simultaneous `complete` calls for one `upload_id` → exactly one row; the second returns the same file UUID |
| `parallel_first_chunks_do_not_race_on_mkdir` | Four concurrent chunk 0..3 for a brand-new patient directory → all succeed, no `ErrorException` |
| `duplicate_chunk_upload_is_idempotent` | Upload chunk 2 twice → one receipt row, file bytes identical |
| `receipts_are_deleted_with_session` | `purgeByUuid` → zero receipt rows remain |
| `expired_session_cleanup_removes_final_path` | Expire a partially-written direct session, run purge → the partial file is gone from disk |

### 13.4 Feature tests — resume and retry (guards R-2, R-5)

| Test | Assertion |
|---|---|
| `status_endpoint_reports_received_chunks` | Upload chunks 0 and 2 → `received_chunks` contains exactly `[0, 2]` |
| `sync_resumes_from_received_chunks` | Interrupt `FileSyncService::uploadLargeFileResumable` after chunk 2, restart → chunks 0–2 are **not** re-uploaded (assert on the mocked HTTP client's call count) |
| `offline_upload_retries_on_5xx` | Mock chunk endpoint to fail twice then succeed → upload completes; assert 3 attempts |
| `offline_upload_does_not_retry_on_422` | Mock a 422 → exactly 1 attempt, job marked failed |
| `offline_upload_resumes_after_failure` | Fail at chunk 2, restart → `init` is not called again; chunks 0–1 are skipped |

### 13.5 Pre-deployment audit queries — run before enabling strict validation

**Truncated files already in the database:**
```sql
SELECT pf.uuid, pf.file_name, pf.size, pf.file_path, pf.upload_status
FROM patient_files pf
WHERE pf.upload_status = 'ready'
ORDER BY pf.created_at DESC;
```
For each row, compare the `size` column against the actual on-disk byte count. Any mismatch, or any missing file, is pre-existing corruption that the new assertion would otherwise attribute to the change.

**Duplicate rows from the `lockForUpdate` no-op:**
```sql
SELECT patient_id, file_name, size, COUNT(*) AS copies
FROM patient_files
WHERE deleted_at IS NULL
GROUP BY patient_id, file_name, size
HAVING COUNT(*) > 1;
```

**Orphaned receipts:**
```sql
SELECT r.session_id, COUNT(*) AS receipts
FROM upload_chunk_receipts r
LEFT JOIN upload_sessions s ON s.id = r.session_id
WHERE s.id IS NULL
GROUP BY r.session_id;
```

**Leaked partial files** (device shell):
```bash
adb shell run-as <application.id> \
  find persisted_data/storage/app/private/patients -type f -size +1M
```
Cross-reference each path against `patient_files.file_path`. Anything not referenced is a leak from §8.1.

### 13.6 Device scenarios

| # | Scenario | Pass criterion |
|---|---|---|
| 1 | Kill Wi-Fi mid-upload, restore, retry | Resumes from the last completed chunk; `init` not re-called; total bytes transferred < 2× file size |
| 2 | Force-stop the app mid-upload, relaunch, retry | Same as 1 |
| 3 | Start an upload, wait >6 h, retry | Client receives 410, discards the stale `upload_id`, starts a fresh session — no 500 |
| 4 | Upload a `.3gp` file | Succeeds (post-allowlist fix) |
| 5 | Upload with an unsupported type (e.g. `.exe`) | **422** with a readable message shown in the UI — not "Request failed with status code 500" |
| 6 | Offline upload, fail one chunk, tap retry | Retry button is **visible**; retry resumes rather than restarting |
| 7 | Complete the same upload twice (double-tap) | One `patient_files` row |
| 8 | Fill device storage to <20 MB, upload a 50 MB video | Explicit error; **no** `ready` row; no partial file left behind |
| 9 | Upload while a manual sync is running | No `database is locked` 500; if contention occurs, a 503 with `Retry-After` |
| 10 | Upload a 17.4 MB video, then byte-compare | `cmp` against the source returns identical; SHA-256 matches `patient_files.sha256` |

### 13.7 Integrity verification after every device scenario

```bash
adb shell run-as <application.id> \
  sqlite3 persisted_data/database/medical_plus.sqlite \
  "SELECT uuid, file_name, size, sha256, upload_status, file_path FROM patient_files ORDER BY id DESC LIMIT 10;"
```

For each row assert: the file exists, its on-disk byte size equals `size`, `size` equals the source file's size, and `sha256` equals the source file's SHA-256. **A row that reports success but fails any of these is a P0 regression regardless of what the UI displayed.**

---

## 14. Acceptance criteria

- [ ] **AC-R1** No upload-path response returns 500 for a client-side error. Validation → 422 with an `errors` bag; auth → 401; forbidden → 403; unknown/expired session → 410 or 404
- [ ] **AC-R2** `trace`, `file`, `line`, `sqlstate`, `sqlite_error` are absent from HTTP response bodies when `APP_DEBUG=false`, and present in the server log
- [ ] **AC-R3** `.3gp`, `.heif`, `.m4v`, `.wmv`, `.flv` upload successfully; a genuinely unsupported type returns 422 with a message the UI displays verbatim
- [ ] **AC-R4** An offline upload interrupted at any chunk resumes from that chunk; total bytes transferred across the interruption is less than 2× the file size
- [ ] **AC-R5** Retry, pause, and cancel are visible and functional for offline jobs
- [ ] **AC-R6** `complete` rejects any assembled file whose byte size differs from `total_size`, and creates no `patient_files` row in that case
- [ ] **AC-R7** Every successful upload populates `patient_files.sha256`, and that value equals the SHA-256 of the source file
- [ ] **AC-R8** Two concurrent `complete` calls for one `upload_id` produce exactly one `patient_files` row
- [ ] **AC-R9** A chunk whose size differs from the expected size for its index is rejected with 422
- [ ] **AC-R10** Device→server sync interrupted after chunk N resumes at chunk N
- [ ] **AC-R11** A storage write failure never produces a `patient_files` row with `upload_status = 'ready'`
- [ ] **AC-R12** Expiring or cancelling a session removes both its partial file and its receipt rows
- [ ] **AC-R13** The audit queries in §13.5 return zero rows on a clean post-fix database

---

## 15. Regression risks

| # | Change | Regression risk | Detection |
|---|---|---|---|
| 1 | Truthful HTTP statuses | Client code branching on `status === 500` breaks | Grep the JS for status checks before shipping; §13.2 covers the server side |
| 2 | Retry only on 5xx | A transient error currently masked by blind retry now surfaces | Intentional. Verify scenario 1 in §13.6 still passes |
| 3 | Strict final-size assertion | Uploads that previously "succeeded" while truncated now fail | Run §13.5 first; the failures are correct, but they must not be attributed to this change |
| 4 | Removing the 1 MiB tolerance | Legitimate WebView chunks rejected if sizes genuinely vary | Instrument actual chunk sizes on-device before enabling |
| 5 | Idempotent completion | If implemented by returning the existing file, callers expecting a fresh UUID break | Return the same shape as a first completion |
| 6 | `'throw' => true` on the disk | Previously-tolerated missing-file deletes now throw | Audit `UploadCleanupService`, `FileCacheService`, `OfflineUploadService` |
| 7 | Receipts FK | Existing orphan rows block the migration | Data cleanup migration first |
| 8 | 410 for expired sessions | A client that does not clear its persisted `upload_id` loops forever | Client fix must ship in the same release |
| 9 | Adding SHA-256 computation | Extra full-file read at completion — noticeable on low-end devices for large files | Measure; consider computing incrementally during chunk writes instead |
| 10 | Fixes applied to `ChunkUploadController` only | `UploadsController` is a near-identical twin exposing the same endpoints under `/api/v1/mobile/uploads/*` and would retain every defect | Apply every fix to both, or consolidate them (Diagnosis 04) |

---

## 16. Definition of done

- [ ] Render closure in `bootstrap/app.php` maps `ValidationException`/`Authentication`/`Authorization`/`NotFound`/`TokenMismatch` to their real statuses
- [ ] Debug detail gated behind `app()->hasDebugModeEnabled()`
- [ ] `validate()` moved above `try` in `ChunkUploadController::init` **and** `UploadsController::start`
- [ ] `ModelNotFoundException` caught and mapped to 410 in `chunk`, `complete`, `status`, `resume`, `finish` across **both** controllers
- [ ] `ALLOWED_MIMES` extended and reconciled with `UploadService::SAFE_EXTENSIONS`; `init` mime `match` extended
- [ ] Offline uploader has retry with backoff, session persistence, and status-based resume
- [ ] `UploadManager.vue` shows retry/pause/cancel for offline jobs, wired to the offline resume path
- [ ] Both bare `err.message` sites replaced with the full extraction chain
- [ ] `ChunkMergeService` asserts `filesize === total_size` and computes/stores SHA-256
- [ ] 1 MiB chunk tolerance removed; exact-size validation in place
- [ ] `mkdir` race fixed; `fsync` added on the final chunk
- [ ] Completion made idempotent without relying on `lockForUpdate`
- [ ] `SQLITE_BUSY` mapped to 503 with `Retry-After`
- [ ] `upload_chunk_receipts` FK added (after data cleanup)
- [ ] `FileSyncService.php:193` reads `received_chunks`; the two `received_chunks` types disambiguated
- [ ] `UploadCleanupService` deletes `final_path` and receipts
- [ ] Disk write returns checked or `'throw' => true`
- [ ] All tests in §13.2–13.4 pass; §13.5 audit queries return zero rows; §13.6 device matrix passes on three devices
