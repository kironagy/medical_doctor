# Diagnosis 03 — Upload Performance, Memory & Throughput

**Project:** Medical_Plus_v4 (Laravel 13 + Vue 3 + Inertia + NativePHP Mobile 3.3 / Android)
**Branch:** `pro-version`
**Scope of this document:** everything that determines whether a large upload is fast, smooth, and survivable on a low-end device and a slow network — transport-layer memory amplification, concurrency, memory leaks, chunk sizing, timeouts, scheduling, logging overhead, and the read/preview paths that compete for the same memory budget.
**Out of scope (covered by sibling documents):** the temp-directory environment defect (01), error semantics and data integrity (02), API surface and structural design (04).

This document is self-contained. Everything needed to implement and verify the fixes is here.

> **Stated requirement driving this document:** this application exists to upload *large* medical data — imaging and video, not thumbnails. Slowness, UI freezing, and data loss are all disqualifying. The performance characteristics below are therefore treated as functional requirements, not optimisations.

> **Context from outside this document, stated once:** there is a separate P0 defect that makes every on-device upload fail immediately (`tempnam(sys_get_temp_dir(), ...)` at `ParseMobileMultipartMiddleware.php:188`). **Nothing in this document is observable until that is fixed** — the performance characteristics described here are what the app will exhibit *once uploads run at all*. Fix order matters: Diagnosis 01 first, then this document.

---

## 1. Executive statement

The transport layer between the WebView and PHP converts every upload body into base64 text and passes it across the JNI boundary as a Java `String`. For a 5 MB chunk this produces roughly **45 MB of transient allocation across four memory spaces**. The client runs four such chunks in parallel — approximately **180 MB peak** — against a PHP `memory_limit` of 256 MB and a WebView renderer budget that is far smaller on low-end devices.

The parallelism buys nothing. A `pthread_mutex` in the native bridge serialises every PHP request on the device, so four concurrent chunk uploads execute strictly one after another. **`POOL_SIZE = 4` multiplies memory pressure by four for zero throughput gain.**

Separately, the bridge stores each request body under three map keys and removes only one, leaking the largest allocation in the system.

| # | Finding | Impact | Severity |
|---|---|---|---|
| P-1 | Base64 transport amplifies each chunk ~9× across four memory spaces | OOM, jank, renderer kills | P0 |
| P-2 | Global PHP mutex makes client parallelism useless | 4× memory for 0× speed | P0 |
| P-3 | POST-body map leaks ~13 MB of JVM heap per non-consumed request | Monotonic growth → OOM after sustained use | P0 |
| P-4 | Chunk size (5 MB) is tuned for desktop, not for a WebView bridge | Peak memory and frozen progress bars | P1 |
| P-5 | Missing timeouts on the majority of upload requests | Permanent hangs on stalled networks | P1 |
| P-6 | Chunk scheduler gated on unrelated app traffic with a 4 s watchdog | Up to 4 s stall per chunk on slow networks | P1 |
| P-7 | Blind retry of non-retryable errors | 4× wasted full-chunk uploads per permanent failure | P1 |
| P-8 | Per-request disk logging and debug POSTs on the hot path | Sustained I/O per chunk | P2 |
| P-9 | Read/preview paths allocate whole files in the same heap | OOM during preview, competing with uploads | P1 |
| P-10 | `ffmpeg` runs synchronously inside the merge DB transaction | Up to 60 s lock hold on production | P1 |
| P-11 | Redundant full-file hashing on every sync attempt | CPU burn on low-end devices | P2 |
| P-12 | Reactive progress churn on every progress event | Vue re-render cost during upload | P2 |

---

## 2. Finding P-1 — Base64 transport amplification (P0)

### 2.1 Why the shim exists

The native bridge passes the HTTP request body from Kotlin to C as a Java `String`, and C then measures it with `strlen()`:

`nativephp/android/app/src/main/cpp/php_bridge.c:465-467, 481`
```c
JNIEXPORT jbyteArray JNICALL native_persistent_dispatch(
        JNIEnv *env, jobject thiz,
        jstring jMethod, jstring jUri, jstring jPostData, jstring jScriptPath) {
    // ...
    const char *post = jPostData ? (*env)->GetStringUTFChars(env, jPostData, NULL) : "";
```
and later:
```c
php_stream_write(post_stream, post, strlen(post));
SG(request_info).content_length = strlen(post);
```

`strlen()` on binary data is NUL-truncating and the JNI string conversion is not binary-safe. The codebase documents having discovered this the hard way:

`nativephp/android/app/src/main/java/com/nativephp/mobile/network/WebViewManager.kt:598-616`
```javascript
// FIX (BINARY CORRUPTION): readAsBinaryString() produced a
// Latin-1 string that the Android JNI JavascriptInterface
// re-encodes as modified UTF-8 (MUTF-8) on the way to PHP —
// every byte >= 0x80 got doubled (0x89 -> c2 89, 0x00 -> c0 80)
// and ALL uploaded images/videos arrived corrupted on disk.
// readAsDataURL() yields pure-ASCII base64 which is 100%
// safe through the string bridge; PHP decodes it back to bytes.
var dataUrl = reader.result;
var commaIdx = dataUrl.indexOf(',');
var b64 = commaIdx >= 0 ? dataUrl.substring(commaIdx + 1) : dataUrl;
parts[idx] = '--' + boundary + '\r\n' +
    'Content-Disposition: form-data; name="' + key + '"; filename="' + filename + '"\r\n' +
    'Content-Type: ' + mimeType + '\r\n' +
    'Content-Transfer-Encoding: base64\r\n\r\n' +
    b64 + '\r\n';
reader.readAsDataURL(val);
```

**The base64 encoding is a correct fix for a real corruption bug.** It is not a mistake to be reverted — it is a workaround whose cost is the subject of this finding. The correct long-term fix is to stop passing bodies as strings at all (§2.5).

### 2.2 The full serialisation path

`WebViewManager.kt:571-645` (`serializePostData`), invoked from the patched `XMLHttpRequest.prototype.send` (`:659`) and `window.fetch` (`:677`):

```javascript
function serializePostData(data, callback) {
    if (typeof FormData !== 'undefined' && data instanceof FormData) {
        var boundary = '----WebKitFormBoundary' + Math.random().toString(36).substring(2) + Date.now().toString(36);
        var entries = Array.from(data.entries());
        var parts = new Array(entries.length);
        var pendingBlobs = 0;

        entries.forEach(function(entry, idx) {
            var key = entry[0];
            var val = entry[1];

            if (val instanceof Blob || val instanceof File) {
                pendingBlobs++;
                var reader = new FileReader();
                reader.onload = function() {
                    var dataUrl = reader.result;                    // ← full base64 string
                    var b64 = dataUrl.substring(dataUrl.indexOf(',') + 1);
                    parts[idx] = '--' + boundary + '\r\n'
                        + /* headers */ + b64 + '\r\n';
                    pendingBlobs--;
                    if (pendingBlobs === 0) {
                        callback(parts.join('') + '--' + boundary + '--\r\n', boundary);   // ← second full copy
                    }
                };
                reader.readAsDataURL(val);
            } else {
                parts[idx] = /* text field */;
            }
        });
    }
    // ...
}
```

Then:

```javascript
XMLHttpRequest.prototype.send = function(data) {
    if (["post","patch","put"].includes(this._method.toLowerCase()) && data) {
        // → serializePostData → AndroidPOST.logPostData(body, url, boundary, requestId)
```

### 2.3 Memory accounting for one 5 MB chunk

| # | Stage | Space | Size | Source |
|---|---|---|---|---|
| 1 | `job.file.slice(start, end)` Blob | Renderer (usually disk-backed) | 5.00 MB | `useUploads.js:632` |
| 2 | `FileReader.readAsDataURL` result — ASCII base64 | Renderer JS heap (one-byte string) | 6.67 MB | `WebViewManager.kt:614` |
| 3 | `parts.join('')` — assembled multipart body | Renderer JS heap | 6.67 MB | `WebViewManager.kt:606` |
| 4 | JS → Java `String` across `@JavascriptInterface` | JVM heap (UTF-16) | **13.33 MB** | `WebViewManager.kt:757` |
| 5 | `GetStringUTFChars` copy | Native heap | 6.67 MB | `php_bridge.c:481` |
| 6 | `php_stream_memory` copy | PHP heap | 6.67 MB | `php_bridge.c` post-stream write |
| 7 | PHP parse: 64 KB read buffer, base64-decoded streaming to the temp file | PHP heap | ~0.13 MB | `ParseMobileMultipartMiddleware.php:85, 307-337` |
| | **Transient peak, one chunk** | | **≈ 45 MB** | |

Stage 7 is the one part of the pipeline that is correctly streamed — `writeBodyData` decodes base64 incrementally into the temp file and never buffers the whole part. The amplification is entirely in stages 2–6.

### 2.4 Multiplied by the client pool

`resources/js/Composables/useUploads.js:17-19`
```js
let CHUNK_SIZE  = 5 * 1024 * 1024;  // 5 MB  — reverted from broken 20 MB
let POOL_SIZE   = 4;                  // concurrent uploads
let MAX_RETRIES = 3;
```

Four chunks in flight → **≈ 180 MB transient**, against:

- `@ini_set('memory_limit', '256M')` (`ParseMobileMultipartMiddleware.php:35`) — PHP side only;
- the Android WebView renderer's per-process heap budget, which on a 2–3 GB device is commonly a few hundred MB and is shared with the entire SPA, the DOM, images, and any open preview;
- the JVM heap of the app process, where stage 4 lives.

**Stage 4 is the dangerous one.** A 13.33 MB UTF-16 `String` per chunk × 4 chunks = 53 MB of JVM heap in short-lived large objects, which is exactly the allocation shape that triggers GC pauses and, combined with §4, `OutOfMemoryError`. Large `@JavascriptInterface` string arguments also traverse the renderer→browser IPC channel, which has its own size constraints; very large values can fail or kill the renderer rather than merely being slow.

The comment block above `CHUNK_SIZE` explains the current values:

```js
// These defaults were measured to produce highest sustained throughput on this
// stack (local PHP + MySQL).  5 MB chunks with 4 parallel slots means ~22
// in-flight operations for a 100 MB file — the pool stays full for almost the
// entire upload, maximising bandwidth utilisation.
```

**"local PHP + MySQL" is the desktop/server stack.** These values were measured on a path that has no WebView shim, no JNI boundary, and no request mutex. They do not transfer to the device.

### 2.5 Required fix — FIX-PERF-1

**Immediate (P0, low risk):** reduce the multiplier by reducing the operand — see FIX-PERF-4 (chunk size) and FIX-PERF-2 (pool size). Together these take the peak from ~180 MB to ~10–18 MB.

**Structural (P2, high value):** eliminate the string bridge for request bodies. Options, in order of preference:

1. **File handoff.** Have the JS side write the chunk to a location the native side can read (or, better, have the native side read the source file directly by path — the native picker already returns an absolute path, see §10.1), and pass only the path across the bridge. PHP then reads the file with normal stream I/O. This removes stages 2–6 entirely.
2. **`ByteBuffer` / `byte[]` transfer.** Change the JNI signature from `jstring` to `jbyteArray` and use `GetByteArrayElements`. Removes the base64 expansion and the UTF-16 doubling; keeps one native copy.
3. **A local socket.** Serve the embedded PHP over a localhost HTTP socket so the WebView's native networking stack handles the body with no bridge at all.

Option 1 is the smallest change with the largest benefit for the specific case of file uploads, because the bytes already exist on disk — the current design reads them into JS only to re-encode them and hand them back to native code.

---

## 3. Finding P-2 — The global PHP mutex makes client parallelism useless (P0)

`nativephp/android/app/src/main/cpp/php_bridge.c:28`
```c
static pthread_mutex_t g_php_request_mutex = PTHREAD_MUTEX_INITIALIZER;
```

`php_bridge.c:270-272, 373-374`
```c
LOGI("run_php_request: waiting for mutex (uri=%s)", uri);
pthread_mutex_lock(&g_php_request_mutex);
LOGI("run_php_request: mutex acquired (uri=%s)", uri);
// ... entire request execution ...
LOGI("run_php_request: releasing mutex (uri=%s)", uri);
pthread_mutex_unlock(&g_php_request_mutex);
```

`php_bridge.c:388`
```c
// The mutex serializes all access — only one PHP execution at a time.
```

This is architecturally necessary — `php_embed` is not thread-safe in this configuration — and it is correct. But it means:

**Every one of the four "parallel" chunk requests executes strictly one after another inside PHP.** The client holds four full request bodies in memory simultaneously (§2.4) while three of them wait on a mutex.

Worse, the waiting is not free. `PHPBridge.consumePostData` (`PHPBridge.kt:341-355`) busy-waits:

```kotlin
fun consumePostData(key: String): String? {
    var data = postDataByKey.remove(key)

    if (data == null) {
        for (i in 1..10) {
            Thread.sleep(5)
            data = postDataByKey.remove(key)
            if (data != null) { break }
        }
    }
    // ...
}
```

and the log lines at `php_bridge.c:270-272` fire per request, adding logcat traffic proportional to contention.

**Net effect of `POOL_SIZE = 4` on device: 4× memory, 4× GC pressure, 4× bridge-map occupancy, and zero throughput improvement.** On the production web path (browser → nginx/Apache → PHP-FPM) the pool is genuinely useful; on device it is pure cost.

### 3.1 Required fix — FIX-PERF-2 (P0)

Make `POOL_SIZE` platform-aware. `configureUploads()` already exists for exactly this purpose:

`useUploads.js:31-35`
```js
export function configureUploads({ chunkSize, poolSize, maxRetries } = {}) {
    if (chunkSize  != null) CHUNK_SIZE  = chunkSize;
    if (poolSize   != null) POOL_SIZE   = poolSize;
    if (maxRetries != null) MAX_RETRIES = maxRetries;
}
```

Call it at app bootstrap (`resources/js/app.js`) with device-appropriate values when the native runtime is detected. A native-detection helper already exists (`detectNative()`, used at `InlineFilePreview.vue:410` and `CategoryBlock.vue:427`).

Recommended device values: `poolSize: 1`, `chunkSize: 1–2 MB` (see §5). Keep `poolSize: 4`, `chunkSize: 5 MB` for the browser.

**Do not simply hardcode `POOL_SIZE = 1`** — the same module serves the production web build, where the pool is beneficial.

---

## 4. Finding P-3 — The POST-body map leaks the largest allocation in the system (P0)

### 4.1 Three writes, one delete

`WebViewManager.kt:757-776`
```kotlin
@JavascriptInterface
fun logPostData(data: String, url: String, boundary: String, requestId: String) {
    Log.d("$TAG-JS", "📦 POST data captured (fetch/XHR) for: $url reqId=$requestId (length=${data.length}, boundary=$boundary)")

    // Store by unique request ID — fetch/XHR requests carry the ID as a header
    phpBridge.storePostData(requestId, data)
    if (boundary.isNotEmpty()) {
        phpBridge.storeBoundary(requestId, boundary)
    }

    // Also store by url and path as fallback in case header lookup fails
    val path = android.net.Uri.parse(url).path ?: url
    phpBridge.storePostData(url, data)            // ← copy 2
    if (path != url) {
        phpBridge.storePostData(path, data)       // ← copy 3
    }

    // Try to extract CSRF token
    LaravelSecurity.extractFromPostBody(data)     // ← scans the whole 6.67 MB string
}
```

`PHPBridge.kt:323-326`
```kotlin
fun storePostData(key: String, data: String) {
    postDataByKey[key] = data
    Log.d(TAG, "Stored POST data for key=$key (length=${data.length})")
}
```

`PHPBridge.kt:341-355`
```kotlin
fun consumePostData(key: String): String? {
    // Try immediate lookup
    var data = postDataByKey.remove(key)          // ← removes ONE key only
    // ... busy-wait retry ...
    return data
}
```

`shouldInterceptRequest` calls `consumePostData` once, with the request-ID key. **The `url` and `path` entries are never removed.**

### 4.2 Leak characterisation

Two distinct behaviours:

**(a) Bounded but permanent retention.** The `url`/`path` keys are identical for every chunk (`/api/v1/chunk/chunk`), so each new chunk overwrites the previous value. The map therefore holds **one full chunk body forever** — 6.67 MB as a Kotlin `String`, i.e. ~13.3 MB of JVM heap — for the lifetime of the process, long after all uploads finish. Multiply by the number of distinct upload endpoints in use (`/chunk/chunk`, `/chunk/init`, `/chunk/complete`, `/_native/api/offline/uploads`, `/api/v1/mobile/patients/{uuid}/files`) and the resident floor is tens of megabytes.

**(b) Unbounded growth on failure.** When a request is aborted, times out, or never reaches `shouldInterceptRequest`, the **request-ID-keyed** entry is never consumed either. Request IDs are unique per request, so these accumulate without bound — ~13.3 MB of JVM heap per abandoned chunk.

This interacts directly with the reliability defects in Diagnosis 02: the online path retries a failed chunk up to 4 times (`useUploads.js:639-650`), and each attempt that fails before interception leaks another body.

### 4.3 The cleanup that does not cover it

`PHPBridge.kt:300-320`
```kotlin
// Find keys with timestamps older than MAX_REQUEST_AGE
requestDataMap.keys.forEach { key ->
    if (key.contains("-")) {
        val timestampStr = key.substringAfterLast("-")
        try {
            val timestamp = timestampStr.toLong()
            if (now - timestamp > MAX_REQUEST_AGE) {
                keysToRemove.add(key)
            }
        } catch (e: NumberFormatException) { }
    }
}
keysToRemove.forEach { requestDataMap.remove(it) }
```

This sweeps **`requestDataMap`**, a different map. `postDataByKey` — the one holding the multi-megabyte bodies — is never swept. `boundaryDataByKey` has the same problem but holds only short strings.

### 4.4 Secondary cost — full-body CSRF scan

`LaravelSecurity.extractFromPostBody(data)` runs on every stored body:

```kotlin
fun extractFromPostBody(body: String?) {
    if (body.isNullOrEmpty()) return
    Log.d(TAG, "🔎 Extracting CSRF token from body")
    try {
        if (body.startsWith("{")) {
            val json = JSONObject(body)
            // ...
        } else if (body.contains("_token=")) {          // ← scans 6.67 MB of base64
            val token = body.split("&")                  // ← splits 6.67 MB into an array
                .find { it.startsWith("_token=") }
            // ...
        }
```

For a multipart chunk body this can never find a token, but `body.contains("_token=")` scans the entire 6.67 MB string, and if a `_token=` substring happens to appear anywhere in the base64 payload, `body.split("&")` allocates an array of substrings over the whole body — potentially tens of megabytes of additional garbage. Every upload route is CSRF-exempt (`bootstrap/app.php` `validateCsrfTokens(except: ['/api/v1/*', '/_native/*', '/chunk/*', '/uploads/*'])`), so this work is pure waste on the upload path.

### 4.5 Required fix — FIX-PERF-3 (P0)

1. **Consume all keys.** Have `logPostData` record which alias keys it wrote (or have `consumePostData` remove the `url` and `path` aliases alongside the request-ID key). The cleanest form is to store the body once under the request ID and store only a *pointer* (the request ID) under `url`/`path`, so there is exactly one large allocation and the aliases are cheap.
2. **Sweep `postDataByKey`.** Extend the existing `cleanupOldRequests` to cover it, using the same `MAX_REQUEST_AGE` policy, so abandoned requests cannot accumulate.
3. **Skip the CSRF scan for multipart and for CSRF-exempt paths.** Guard `extractFromPostBody` on `boundary.isEmpty()` (i.e. non-multipart) and on the URL not matching an exempt prefix.
4. **Do not log `length` on the hot path** — the `Log.d` calls in `storePostData` and `logPostData` fire per chunk. Gate them behind `BuildConfig.DEBUG`.

---

## 5. Finding P-4 — Chunk size is tuned for the wrong stack (P1)

`useUploads.js:11-19` — the rationale in the source:

```js
// Root-cause of the regression: CHUNK_SIZE was raised to 20 MB which produced
// only ~5 chunks, starving the parallel pool and making the upload effectively
// sequential after the first wave.
let CHUNK_SIZE  = 5 * 1024 * 1024;  // 5 MB  — reverted from broken 20 MB
```

The reasoning is sound **for a stack where parallelism works**. On device, parallelism does not work (§3), so the argument for keeping chunks large enough to fill a pool does not apply.

The offline path independently hardcodes the same value:

`useOfflineUploads.js:113`
```js
const CHUNK_SIZE = 5 * 1024 * 1024 // 5 MB chunks
```

and the server-to-server sync path a third time:

`app/Services/Sync/FileSyncService.php:16-17`
```php
private const RESUMABLE_THRESHOLD = 26214400; // 25 MB
private const CHUNK_SIZE = 5242880;          // 5 MB
```

### 5.1 Consequences of 5 MB on a slow link

Beyond memory, chunk size determines **user-visible progress granularity**. On the offline path, progress advances only between chunks:

`useOfflineUploads.js:155-166`
```js
if (job) {
  job.completedChunks.add(i)
  job.uploadedBytes = end
  job.progress = Math.round((end / file.size) * 100)
  // ...
}
```

There is no `onUploadProgress` handler. On a 500 kbit/s link a 5 MB chunk takes ~80 seconds, during which **the progress bar does not move at all**, then jumps 25%. Against a 120 s timeout (`useOfflineUploads.js:153`), the margin is 40 seconds — a slightly slower link produces a timeout, and with zero retries (Diagnosis 02) that destroys the upload.

Smaller chunks improve all four properties simultaneously: lower peak memory, smoother progress, more granular resume, and more headroom against the timeout.

The server accepts a wide range, so no server change is needed:

`ChunkUploadController.php:68`
```php
'chunk_size' => 'sometimes|integer|min:1048576|max:52428800',   // 1 MiB … 50 MiB
```

### 5.2 Required fix — FIX-PERF-4 (P1)

Set device chunk size to **1–2 MB** via `configureUploads()` (§3.1), and have `useOfflineUploads.js` read the shared configuration rather than hardcoding its own constant. Align `FileSyncService::CHUNK_SIZE` with whatever value is chosen, or leave it at 5 MB — the server-to-server path has no WebView bridge and no mutex, so its constraints differ; document the divergence deliberately rather than by accident.

**Measure before finalising.** The correct value is the one that minimises total upload time on a real low-end device on a real slow link, not the one that minimises peak memory in isolation. Per-chunk overhead (a full Laravel boot per request on the embedded runtime) grows as chunks shrink.

**Note the per-request boot cost:** `bootstrap/cache/packages.php` and `services.php` exist, but there is **no `bootstrap/cache/config.php`** — configuration is **not cached**. Every chunk request re-reads and re-merges all config files. Since every branch in the upload code keys off `config('database.default')`, this is on the hot path. Running `php artisan config:cache` as part of the build is a cheap, large win that also reduces the penalty of smaller chunks. Verify it is compatible with the runtime env-var overrides in `LaravelEnvironment.kt` before enabling — cached config freezes `env()` values.

---

## 6. Finding P-5 — Missing timeouts (P1)

| Request | Timeout | File:line |
|---|---|---|
| `POST /api/v1/mobile/patients/{id}/files` (all online images and PDFs) | **none** | `useUploads.js:309` |
| `POST /api/v1/chunk/init` | **none** | `useUploads.js:413` |
| `POST /api/v1/chunk/complete` | **none** | `useUploads.js:488` |
| `GET /api/v1/chunk/{id}/status` | **none** | `useUploads.js:380` |
| `POST /api/v1/chunk/chunk` | 300 000 ms | `useUploads.js:665` |
| Offline chunk POST | 120 000 ms | `useOfflineUploads.js:153` |
| Offline single POST | 120 000 ms | `useOfflineUploads.js:97` |

There is no global axios default:

`resources/js/bootstrap.js` (4 lines)
```js
import axios from 'axios';
window.axios = axios;
window.axios.defaults.headers.common['X-Requested-With'] = 'XMLHttpRequest';
```

and the dedicated upload client sets none either:

`useUploads.js:57-75`
```js
const uploadHttp = axios.create();
const csrfToken = document.head.querySelector('meta[name="csrf-token"]');
if (csrfToken) {
    uploadHttp.defaults.headers.common['X-CSRF-TOKEN'] = csrfToken.content;
}
uploadHttp.defaults.headers.common['X-Requested-With'] = 'XMLHttpRequest';

const _uploadToken = typeof localStorage !== 'undefined' ? localStorage.getItem('np_api_token') : null;
if (_uploadToken) {
    uploadHttp.defaults.headers.common['Authorization'] = 'Bearer ' + _uploadToken;
}
```

**`uploadDirectly` is the worst case.** It is the path taken by *every* online image and PDF — the majority of uploads by count:

`useUploads.js:359-364`
```js
async function startUpload(job, debug = null) {
    const isVideo = job.file?.type?.startsWith("video/") || /\.(mp4|mov|avi|mkv|webm)$/i.test(job.file?.name || "");
    if (!isVideo) {
        return uploadDirectly(job, d);
    }
```

`useUploads.js:297-357`
```js
const res = await uploadHttp.post(`/api/v1/mobile/patients/${job.patientId}/files`, formData, {
    headers: { "Content-Type": "multipart/form-data" },
    onUploadProgress: (evt) => { /* ... */ }
});
```

No timeout, no retry, no `AbortController`, and — because `UploadManager.vue:99` only renders cancel for `status === 'uploading' && !upload.offline` and `cancelUpload` operates on `job._controllers` which this path never populates — **no way to cancel it**. On a stalled network the job hangs in `uploading` state indefinitely, holding its share of the global pool semaphore (`acquireGlobalSlot`/`releaseGlobalSlot`, `useUploads.js:186-194`) and blocking other uploads.

### 6.1 Server-side timeout amplification

`app/Services/Mobile/RemoteApiService.php:105-125, 191-201`
```php
public function upload(string $endpoint, array $files, array $data = []): array
{
    $client = $this->buildClient(timeoutSeconds: 300);
    foreach ($files as $name => $filePath) {
        if (!file_exists($filePath)) { throw new RuntimeException("File not found on disk: {$filePath}"); }
        $contents = fopen($filePath, 'r');
        $client->attach($name, $contents, basename($filePath));
    }
    // ...
}

private function buildClient(int $timeoutSeconds = 30): PendingRequest
{
    $client = Http::timeout($timeoutSeconds)
        ->retry(2, 500)                      // ← applies to uploads too
        ->withHeaders(['Accept' => 'application/json']);
    if ($this->token) { $client->withToken($this->token); }
    return $client;
}
```

`->retry(2, 500)` with `timeout: 300` means **a single chunk can occupy up to 15 minutes** (3 attempts × 300 s) before failing. Combined with the sync-resume defect (Diagnosis 02, R-5) which restarts from chunk 0 every time, a large file on a poor connection can loop indefinitely.

`app/Jobs/RunManualSyncJob.php:42, 45`
```php
public int $timeout = 3600;
public int $tries = 1;
```

### 6.2 Required fix — FIX-PERF-5 (P1)

1. Set a default timeout on `uploadHttp` (e.g. 60 s) so control requests cannot hang forever.
2. Give `uploadDirectly` an explicit timeout, an `AbortController` registered in `job._controllers`, and retry with backoff — or, better, route non-video files through the chunked path so there is exactly one upload implementation (see Diagnosis 04).
3. Scale the chunk timeout to the chunk size rather than hardcoding it. A 5-minute timeout on a 1 MB chunk is meaningless; a 2-minute timeout on a 5 MB chunk is too tight on a slow link.
4. Reduce `RemoteApiService` upload timeout and reconsider `retry(2, 500)` for large-body requests — retrying a 300-second upload is rarely the right response.

---

## 7. Finding P-6 — The chunk scheduler is gated on unrelated traffic (P1)

`useUploads.js:77-194`

```js
let globalActiveChunks = 0;
const globalChunkQueue = [];
let normalRequestsPending = 0;

function isUploadRequest(config) {
    if (!config || !config.url) return false;
    return config.url.includes('/chunk/chunk') ||
           config.url.includes('/chunk/init') ||
           config.url.includes('/chunk/complete') ||
           (config.url.includes('/chunk/') && config.url.includes('/status'));
}

axios.interceptors.request.use(config => {
    if (!isUploadRequest(config)) {
        normalRequestsPending++;
        config._perfStart = Date.now();
    }
    return config;
});

// ...

router.on('start', (event) => {
    normalRequestsPending++;
    const url = event.detail.visit.url.toString();
    inertiaVisitStarts[url] = Date.now();
});

const WATCHDOG_MS = 4000;

function canScheduleChunk(bypassGate = false) {
    if (globalActiveChunks >= POOL_SIZE) return false;
    if (bypassGate) return true;
    return normalRequestsPending === 0;        // ← any pending app request blocks new chunks
}

function pumpScheduler() {
    const now = Date.now();
    while (globalChunkQueue.length > 0) {
        const entry = globalChunkQueue[0];
        const bypassGate = now - entry.queuedAt >= WATCHDOG_MS;
        if (!canScheduleChunk(bypassGate)) break;
        globalChunkQueue.shift();
        globalActiveChunks++;
        entry.resolve();
    }
}
```

**Intent:** keep the UI responsive by yielding to navigation and data fetches. **Reality on a slow network:** ordinary API calls take seconds, Inertia visits take seconds, and `normalRequestsPending` is rarely zero. Chunks are therefore admitted mostly by the 4-second watchdog rather than by the gate — **adding up to 4 seconds of dead time per chunk**.

On a 4-chunk file that is up to 16 seconds of pure stall. On a 100 MB file at 1 MB chunks (100 chunks) it is potentially several minutes of scheduler-induced latency.

There is also a **counter-leak risk**: `normalRequestsPending` is incremented in the request interceptor and decremented in `decrementNormalRequests`, which is called from both response handlers — but a request that is cancelled via `AbortController` before a response, or an Inertia visit that fires `start` without `finish` (cancelled navigation), can leave the counter permanently above zero. The watchdog masks this by forcing admission after 4 s, which is why it exists — but it means the system runs in watchdog mode rather than gated mode, permanently paying the 4 s penalty.

Note that `isUploadRequest` matches on `/chunk/...` only, so the **offline** uploader's requests (which use the same URLs but the global `axios` instance) are correctly excluded from the counter — but the offline uploader also never calls `acquireGlobalSlot`, so it is entirely outside this scheduler.

### 7.1 Required fix — FIX-PERF-6 (P1)

- Reduce `WATCHDOG_MS` substantially (500–1000 ms) so the penalty is bounded, or
- Replace the boolean gate with a priority scheme: allow at least one chunk in flight at all times, and use `normalRequestsPending` only to decide whether to admit *additional* chunks beyond the first. With `POOL_SIZE = 1` on device (§3.1), the gate becomes a pure stall and should be bypassed entirely.
- Make the counter self-healing: reset `normalRequestsPending` to zero when `globalChunkQueue` is non-empty and no genuine request has been outstanding for longer than a threshold.

---

## 8. Finding P-7 — Blind retry wastes bandwidth (P1)

`useUploads.js:634-722`
```js
for (let attempt = 0; attempt <= MAX_RETRIES; attempt++) {
    if (attempt > 0) {
        const delay = Math.min(RETRY_BASE_MS * Math.pow(2, attempt - 1), RETRY_CAP_MS);
        await new Promise((r) => setTimeout(r, delay));
    }
    // ...
    try {
        const response = await uploadHttp.post("/api/v1/chunk/chunk", fd, {
            signal: controller.signal,
            timeout: 300000,
            onUploadProgress: (e) => { /* ... */ },
        });
        // ...
        return;
    } catch (err) {
        lastError = err;
        // ...
        if (err.name === "CanceledError" || axios.isCancel(err)) {
            throw err;
        }
        job.failedChunks.set(chunkIndex, attempt + 1);      // ← everything else is retried
    }
}
```

The only non-retryable case is an explicit cancel. **A 422 (invalid chunk index, oversized chunk), a 403, or a 410 (expired session) is retried three more times**, each attempt re-uploading the full chunk body and paying the full base64 amplification of §2.

Cost of one permanently-rejected 5 MB chunk: 4 × 5 MB uploaded, 4 × 45 MB transient allocation, ~7.5 s of backoff, and — because the error responses include full stack traces (Diagnosis 02, §2.7) — 4 × multi-kilobyte error bodies.

This defect compounds with Diagnosis 02's finding that all errors currently return 500: the client cannot distinguish retryable from non-retryable **because the server does not tell it**. Both must be fixed together — truthful statuses on the server, status-aware retry on the client.

### 8.1 Required fix — FIX-PERF-7 (P1)

Retry only on: network errors (no `err.response`), timeouts (`ECONNABORTED`), 408, 429, 502, 503, 504, and 500 *only* if the server has not classified it. Do not retry 4xx. Honour `Retry-After` when present (Diagnosis 02 recommends returning it for SQLite lock contention).

---

## 9. Finding P-8 — Hot-path logging and debug traffic (P2)

| # | Source | Cost |
|---|---|---|
| 1 | `ParseMobileMultipartMiddleware.php:285-290` — `Log::error('[ParseMobileMultipartStream] Parsed multipart body', [...])` | One synchronous disk write **per multipart request**, i.e. per chunk. Also at `error` level, polluting the channel real errors use |
| 2 | `CategoryBlock.vue:405` — `const trace = (msg) => { fetch('/_native/api/debug/trace', {method:'POST', ...}) }` | A full HTTP round trip **and a full PHP boot** per UI step. `CategoryBlock.vue:464-484` calls it 3× per `uploadFile()` invocation |
| 3 | `ChunkUploadController.php` — `Log::channel('upload')->info('chunk:init - ENTER Controller', ['payload' => $request->all()])` | Logs the full request payload on every init |
| 4 | `php_bridge.c:270-272` — `LOGI("run_php_request: waiting for mutex ...")` ×2 per request | Logcat traffic proportional to request count |
| 5 | `PHPBridge.kt:324`, `WebViewManager.kt:758` — `Log.d(..., "length=${data.length}")` | Per chunk; string interpolation of a `length` property is cheap, but the calls are unconditional |
| 6 | `bootstrap/app.php` — `Log::error('UPLOAD/API EXCEPTION RESPONSE', ['headers' => $request->headers->all(), 'trace' => ...])` | Full headers plus stack trace per failed request; with blind retry (§8) that is 16 such writes for one permanently-failing file |
| 7 | `ChunkUploadController.php:202` — `if ($request->hasSession()) { $request->session()->save(); }` | An extra session write per chunk request |
| 8 | `resources/js/app.js:16-54` — posts every unhandled rejection to `/api/v1/log/client-error` | Each is a PHP boot |

Item 2 is the most expensive per occurrence: on the embedded runtime every HTTP request boots Laravel, so a debug trace costs the same as a real API call and contends for `g_php_request_mutex` (§3) against the very chunks it is tracing.

### 9.1 Required fix — FIX-PERF-8 (P2)

Gate all of the above behind an explicit debug flag. The codebase already demonstrates the pattern:

`bootstrap/app.php`
```php
// ── PERF FIX: Profiler now requires an explicit NATIVEPHP_PROFILER=true ──
// Previously it ran whenever APP_DEBUG=true (which debug builds ship with),
// writing REQUEST_START + REQUEST_FINISHED log entries for EVERY request
// — significant disk I/O on the embedded device and a big part of the
// perceived slowness. It is investigation-only instrumentation; enable it
// explicitly when profiling is needed.
if (env('NATIVEPHP_PROFILER', false)) {
    $middleware->append(\App\Http\Middleware\NativePHPProfilerMiddleware::class);
}
```

Apply the same gate to items 1, 3, 6, 8, and gate the JS `trace()` on the existing `upload_debug` localStorage flag (already used elsewhere in `useUploads.js`).

---

## 10. Finding P-9 — Read and preview paths compete for the same memory budget (P1)

Uploads do not run in isolation. The same WebView heap serves file previews, and those paths allocate whole files.

### 10.1 The native pick path materialises the entire file before upload begins

`resources/js/Components/workspace/CategoryBlock.vue:1068-1104`
```js
const rawPath = fileData.uri || fileData.path
if (rawPath) {
    const readUri = /^[a-z][a-z0-9+.-]*:\/\//i.test(rawPath) ? rawPath : `file://${rawPath}`
    const blob = await new Promise((resolve, reject) => {
        const xhr = new XMLHttpRequest()
        xhr.open('GET', readUri, true)
        xhr.responseType = 'blob'
        xhr.onload = () => {
            if (xhr.status === 200 || xhr.status === 0) resolve(xhr.response)
            else reject(new Error(`HTTP ${xhr.status}`))
        }
        xhr.onerror = () => reject(new Error('Network error'))
        xhr.send()
    })
    const mime = fileData.mimeType || fileData.type || blob.type
    const name = fileData.name || rawPath.split('/').pop() || 'file'
    file = new File([blob], name, { type: mime })
}
```

The camera/gallery plugin returns an **absolute on-device path**. The code reads that path into a `Blob` via `file://` XHR, then wraps it in a `File`. Chromium usually backs large blobs with disk, so this is not necessarily a full heap allocation — but it is a full file read, it is blocking, and `new File([blob], ...)` may copy again. There is no size guard.

**This is the peak-memory moment for the whole flow, and it occurs before the uploader is even involved.** It is also unnecessary: the native side already has the path, so the file could be uploaded by path (§2.5, option 1) without ever entering the JS heap.

### 10.2 Video preview reconstructs the whole file byte-by-byte in JS

`resources/js/Components/workspace/InlineFilePreview.vue:356-384`
```js
async function fetchLocalVideoBlobUrl(uuid, mimeType) {
  const CHUNK = 1024 * 1024;
  const parts = [];
  let offset = 0;
  let total = null;

  for (let i = 0; i < 2048; i++) {
    const res = await axios.get(`/_native/cache/files/${uuid}/base64`, {
      params: { offset, length: CHUNK },
    });

    const b64 = res.data?.data || '';
    if (!b64) break;
    if (total === null && typeof res.data?.size === 'number') total = res.data.size;

    const bin = atob(b64);
    const bytes = new Uint8Array(bin.length);
    for (let j = 0; j < bin.length; j++) bytes[j] = bin.charCodeAt(j);   // ← 1M iterations per MB
    parts.push(bytes);                                                    // ← retains everything

    offset += bytes.length;
    if (total !== null ? offset >= total : bytes.length < CHUNK) break;
  }

  if (!parts.length) throw new Error('no bytes received');
  return URL.createObjectURL(new Blob(parts, { type: mimeType || 'video/mp4' }));   // ← copies again
}
```

Per 1 MB chunk: base64 string (~1.33 MB, one-byte) + `atob` binary string (~1–2 MB) + `Uint8Array` (1 MB), with **all `parts` retained** — so the whole file ends up in the heap, then `new Blob(parts)` copies it. The `charCodeAt` loop runs one million iterations per megabyte.

The size guard admits exactly the file in the field reports:

`InlineFilePreview.vue:313`
```js
const size = Number(file.value.size || 0);
if (size && size > 25 * 1024 * 1024) {
    console.warn('[InlineFilePreview] file too large for the base64 fallback', size);
} else {
```

A 17.4 MB video is **under** the 25 MB guard → eligible → ~35–70 MB of transient JS heap, plus 17 round trips each costing a full PHP boot.

Fix: replace the `charCodeAt` loop with `Uint8Array.from(bin, c => c.charCodeAt(0))` or, better, request binary directly. Best: use the native media-server path and avoid base64 entirely.

### 10.3 The download route has no size cap at all

`routes/web.php:414-422`
```php
if ($absolutePath) {
    return response()->json(
        $native->saveBytes(base64_encode(file_get_contents($absolutePath)), $fileName, $mime)
    );
}
```

`file_get_contents` loads the whole file, `base64_encode` produces a 1.33× copy, and `response()->json` produces a third. For a 17.4 MB video that is ~17 + 23 + 23 ≈ 63 MB inside a process capped at 256 MB. **For a 100 MB file it is a guaranteed OOM.**

Compare the bounded sibling endpoint:

`app/Http/Controllers/Api/FileAccessController.php:21`
```php
private const BASE64_MAX_BYTES = 5 * 1024 * 1024;
```
`FileAccessController.php:639-675`
```php
if ($fileSize > self::BASE64_MAX_BYTES) {
    abort(413, 'File too large for base64; request it in chunks with ?offset=&length=.');
}
```

The correct behaviour already exists in `FileAccessController`; `routes/web.php:420` simply does not use it.

### 10.4 Binary streaming is capped and buffered

`FileAccessController.php:79`
```php
private const DEVICE_MAX_CHUNK_BYTES = 2 * 1024 * 1024;
```
`rangeCappedResponse()` at `:141-190` reads the capped window **into a PHP string** rather than streaming it — the source comment at `:186` explains why streaming is not possible through this SAPI. This bounds per-request memory at 2 MB, which is acceptable, but it means video playback issues 9 requests per 17.4 MB, each a full PHP boot, each serialised behind `g_php_request_mutex`.

### 10.5 Required fix — FIX-PERF-9 (P1)

1. Cap `routes/web.php:420` at `BASE64_MAX_BYTES` and use the chunked download path for anything larger — reuse `FileAccessController`'s existing offset/length contract.
2. Replace the `charCodeAt` loop in `InlineFilePreview.vue:376` with `Uint8Array.from(bin, c => c.charCodeAt(0))`.
3. Lower the 25 MB preview guard, or route large videos exclusively through the native media server.
4. Upload by path rather than reading the file into JS (§2.5, option 1) — this removes §10.1 entirely.

---

## 11. Finding P-10 — `ffmpeg` runs inside the merge transaction (P1)

`config` — `QUEUE_CONNECTION=sync` in `.env:43` and forced on device at `LaravelEnvironment.kt:838`:
```kotlin
"QUEUE_CONNECTION" to "sync",
```

**Every dispatched job therefore runs inline in the HTTP request.**

`app/Domains/Media/Services/ChunkMergeService.php` — inside the `DB::transaction` closure:
```php
if ($type === 'video') {
    GenerateThumbnailJob::dispatch($patientFile->id);
}
```

`app/Domains/Media/Jobs/GenerateThumbnailJob.php:31-32, 44, 96-109`
```php
public int $timeout = 120;
public int $tries   = 1;
// ...
if (config('database.default') === 'sqlite') { return; }     // ← no-op on device
// ...
$cmd = ['ffmpeg','-y','-ss','1','-i',$inputPath,'-vframes','1','-vf','scale=-1:300','-q:v','5',$thumbAbsPath];
$process = new Process($cmd);
$process->setTimeout(60);
$process->run();
```

On device the early return makes this harmless. **On the production MySQL server it is not:** a synchronous `ffmpeg` subprocess of up to 60 seconds runs while holding an open write transaction and a real `SELECT ... FOR UPDATE` row lock on `upload_sessions`.

Consequences under concurrent uploads on production:
- InnoDB lock-wait timeouts on other `complete` calls → 500s (which, per Diagnosis 02, are indistinguishable from crashes).
- One PHP-FPM worker occupied per video completion for up to 60 s.
- The client's `complete` request has **no timeout** (§6), so it waits indefinitely.

**Fix — FIX-PERF-10 (P1):** move the dispatch outside the transaction closure, and on production use a real queue driver (`database` or `redis`) so thumbnail generation is genuinely asynchronous. If `sync` must be retained, dispatch after the transaction commits (`DB::afterCommit()` or an explicit call after `DB::transaction` returns).

`app/Domains/Media/Services/UploadService.php:88` dispatches the same job outside a transaction — that call site is correct and can serve as the reference.

Note also `app/Jobs/ProcessUploadedFileJob.php` (`timeout = 300`, `tries = 1`) is defined but dispatched nowhere in the repository — dead code that should be removed or wired up deliberately.

---

## 12. Finding P-11 — Redundant full-file hashing on every sync attempt (P2)

`app/Services/Sync/FileSyncService.php:63`
```php
hash_file('sha256', $absPath)
```

`hash_file` streams correctly and does not buffer the file — but it is a **full read of the file on every sync attempt, before any upload begins**. For a 17.4 MB video on a low-end device this is measurable CPU and I/O; combined with the sync-resume defect (Diagnosis 02, R-5) which restarts from chunk 0 on every retry, the same file is hashed repeatedly with no progress made.

Additionally, `FileSyncService.php:211-213` writes each 5 MB chunk to a temp file before uploading:
```php
$chunkData = fread($handle, self::CHUNK_SIZE);   // 5 MB PHP string
$tmpChunkPath = tempnam(sys_get_temp_dir(), 'chunk_');
file_put_contents($tmpChunkPath, $chunkData);    // plus a 5 MB temp file, per chunk
try {
    $this->api->upload('/chunk/chunk', ['chunk' => $tmpChunkPath], [
        'upload_id' => $uploadId, 'chunk_index' => $chunkIndex,
    ]);
} finally {
    @unlink($tmpChunkPath);
}
```

This is a full 5 MB string in PHP memory plus a 5 MB disk write and read per chunk — avoidable by attaching a bounded stream over the source file handle instead of materialising a temp copy. (Note this is also the second site of the temp-directory defect covered in Diagnosis 01.)

**Fix — FIX-PERF-11 (P2):** cache the SHA-256 in `patient_files.sha256` (the column exists and is indexed — migration `2026_08_02_000001:63-68`) and compute it once, ideally incrementally during the original chunk writes. Replace the per-chunk temp file with a bounded stream.

---

## 13. Finding P-12 — Reactive progress churn (P2)

`useUploads.js:552-562`
```js
function updateProgressFromParts(job) {
    const inFlightTotal = Array.from(job._inFlightLoaded.values()).reduce((sum, val) => sum + val, 0);
    job.uploadedBytes = job._completedBytesSum + inFlightTotal;
    job.progress      = Math.min(100, Math.round((job.uploadedBytes / job.totalBytes) * 100));
}
```

called from every `onUploadProgress` event of every in-flight chunk:

`useUploads.js:668-680`
```js
onUploadProgress: (e) => {
    if (e.lengthComputable) {
        job._inFlightLoaded.set(chunkIndex, e.loaded);
        updateProgressFromParts(job);

        job._speedTracker.push(job.uploadedBytes);
        const bps = job._speedTracker.bps();
        if (bps > 0) job.speed = bps;
    }
},
```

`job` is a `reactive()` object (`useUploads.js:255`), and both `progress` and `speed` are rendered in two components simultaneously — `UploadManager.vue:85` and the inline list in `CategoryBlock.vue:37-59`. Every progress event triggers Vue reactivity and a re-render. With 4 concurrent chunks and browser progress events firing frequently, this is continuous main-thread work on the same thread that must stay responsive.

**Fix — FIX-PERF-12 (P2):** throttle `updateProgressFromParts` to ~4–10 Hz, or write to a non-reactive scratch object and flush to the reactive one on a `requestAnimationFrame` tick. With `POOL_SIZE = 1` on device the pressure drops substantially, so this is lower priority after FIX-PERF-2.

Also note the duplicate-UI issue: `CategoryBlock.vue:807-816` filters `uploads` by `j.patientId === pid` where `pid` is `selectedPatient.value?.id`, while `handleFiles()` at `:1174` passes `selectedPatient.value?.uuid || selectedPatient.value?.id`. The identifiers do not match, so drag/drop and `<input type=file>` uploads never appear in the inline list — they render only in `UploadManager.vue`. This is a correctness bug with a performance side effect (two components subscribed to the same reactive collection).

---

## 14. Consolidated memory budget

### 14.1 Current, per 5 MB chunk

```
Renderer JS heap
  ├── Blob slice                               5.00 MB  (usually disk-backed)
  ├── FileReader dataURL (base64, 1-byte)      6.67 MB
  └── parts.join('') assembled body            6.67 MB
                                              ────────
                                              ~13.3 MB heap + 5 MB blob

JVM heap
  ├── @JavascriptInterface String (UTF-16)    13.33 MB
  └── postDataByKey ×3 aliases                 (same object, 1 allocation, 3 refs)
      └── LEAKED: 2 of 3 refs never removed   13.33 MB retained indefinitely
                                              ────────
                                              ~13.3 MB live + 13.3 MB leaked

Native heap
  └── GetStringUTFChars copy                   6.67 MB

PHP heap
  ├── php_stream_memory copy                   6.67 MB
  ├── 64 KB parse buffer                        0.06 MB
  └── base64 decode carry                       0.07 MB
                                              ────────
                                              ~6.8 MB

TRANSIENT PEAK, ONE CHUNK                     ≈ 45 MB
× POOL_SIZE 4                                 ≈ 180 MB
```

### 14.2 After FIX-PERF-2 (`POOL_SIZE = 1`) + FIX-PERF-4 (`CHUNK_SIZE = 1 MB`)

```
Per chunk:  1.00 + 1.33 + 1.33 + 2.67 + 1.33 + 1.33 + 0.13  ≈ 9 MB
× POOL_SIZE 1                                                ≈ 9 MB

Reduction: ~180 MB → ~9 MB   (95%)
```

### 14.3 After FIX-PERF-1 structural fix (upload by path)

Stages 2–6 disappear. PHP reads the source file with a bounded stream; peak is the read buffer, single-digit megabytes regardless of file size.

**This is why FIX-PERF-2 and FIX-PERF-4 are P0 despite being two-line changes: they deliver 95% of the benefit at near-zero risk, and they are prerequisites for uploading genuinely large files at all.**

---

## 15. Affected files

| File | Lines | Findings |
|---|---|---|
| `nativephp/android/app/src/main/java/com/nativephp/mobile/network/WebViewManager.kt` | 571-645, 649-680, 757-776 | P-1 (base64 shim), P-3 (3-key store, CSRF scan), P-8 (logging) |
| `nativephp/android/app/src/main/java/com/nativephp/mobile/bridge/PHPBridge.kt` | 300-320, 323-326, 341-355 | P-3 (leak, wrong map swept, busy-wait) |
| `nativephp/android/app/src/main/cpp/php_bridge.c` | 28, 270-272, 373-374, 465-481 | P-2 (mutex), P-1 (`strlen` on string body) |
| `resources/js/Composables/useUploads.js` | 17-19, 31-35, 57-75, 77-194, 297-357, 359-364, 380, 413, 488, 552-562, 625-722 | P-2, P-4, P-5, P-6, P-7, P-12 |
| `resources/js/Composables/useOfflineUploads.js` | 97, 113, 138-153, 155-166 | P-4, P-5 |
| `resources/js/Components/workspace/CategoryBlock.vue` | 405, 427-441, 464-484, 807-816, 1068-1104, 1174 | P-8 (trace), P-9 (pick path), P-12 (filter mismatch) |
| `resources/js/Components/workspace/InlineFilePreview.vue` | 313, 356-384, 410-425 | P-9 (whole-file base64 reconstruction) |
| `resources/js/app.js` | 16-54, 62-71 | P-8 (client error POSTs); bootstrap point for `configureUploads()` |
| `routes/web.php` | 414-422 | P-9 (uncapped base64 download) |
| `app/Http/Controllers/Api/FileAccessController.php` | 21, 79, 141-190, 639-675 | P-9 (bounded reference implementation) |
| `app/Http/Middleware/ParseMobileMultipartMiddleware.php` | 35, 85, 285-290, 307-337 | P-8 (per-request `Log::error`); correct streaming decode |
| `app/Domains/Media/Services/ChunkMergeService.php` | dispatch inside `DB::transaction` | P-10 |
| `app/Domains/Media/Jobs/GenerateThumbnailJob.php` | 31-32, 44, 96-109 | P-10 |
| `app/Services/Sync/FileSyncService.php` | 16-17, 63, 198-230 | P-11 |
| `app/Services/Mobile/RemoteApiService.php` | 105-125, 191-201 | P-5 (300 s × retry 2) |
| `app/Http/Controllers/Api/ChunkUploadController.php` | 58-60, 202 | P-8 (payload logging, session write per chunk) |
| `bootstrap/app.php` | `withExceptions` report + render | P-8 (headers + trace logged per failure) |
| `bootstrap/cache/` | absence of `config.php` | P-4 (config not cached — per-request cost) |

---

## 16. Risks and edge cases

| # | Risk / edge case | Notes |
|---|---|---|
| 1 | `POOL_SIZE = 1` slows the **web** build | Must be device-scoped via `configureUploads()`, never a global constant change |
| 2 | Smaller chunks increase request count, and each request is a full Laravel boot on device | Mitigate with `config:cache`; measure end-to-end time, not just memory |
| 3 | `config:cache` freezes `env()` values | `LaravelEnvironment.kt` sets ~30 env vars at runtime. Verify every `env()` call outside config files is eliminated before caching, or the app will read stale values |
| 4 | Fixing the `postDataByKey` leak changes lookup-fallback behaviour | The `url`/`path` aliases exist because header-based lookup sometimes fails. Store a pointer, not a copy — do not simply delete the aliases |
| 5 | Skipping the CSRF scan for multipart | Verify no upload route actually relies on token extraction. All upload routes are CSRF-exempt, so this is safe, but confirm before shipping |
| 6 | Reducing `WATCHDOG_MS` increases contention with UI requests | With `POOL_SIZE = 1` the contention is minimal; measure UI responsiveness during upload |
| 7 | Status-aware retry depends on truthful HTTP statuses | Blocked on Diagnosis 02 FIX-REL-1. Shipping retry changes first would make permanent failures fail faster but still opaquely |
| 8 | Moving `ffmpeg` out of the transaction changes thumbnail timing | A `PatientFile` may briefly exist without a thumbnail. Verify the UI handles a null `thumbnail_path` |
| 9 | Upload-by-path requires native file-access permissions | The picker already returns readable paths; verify scoped-storage constraints on Android 11+ for gallery-sourced URIs |
| 10 | Capping the download route breaks the existing download button for large files | Implement the chunked fallback in the same change, not after |
| 11 | Measuring on an emulator | Emulators have desktop-class memory and no realistic thermal or GC behaviour. **All performance acceptance must run on physical low-end hardware** |

---

## 17. Acceptance criteria

- [ ] **AC-P1** Peak JVM heap during a 100 MB video upload stays below 60 MB above the app's idle baseline, measured with `adb shell dumpsys meminfo <package>` sampled every 2 s
- [ ] **AC-P2** `POOL_SIZE` is 1 on device and 4 in the browser build, set through `configureUploads()` — not by editing the constant
- [ ] **AC-P3** After 20 consecutive uploads, JVM heap returns to within 10 MB of the pre-upload baseline (no monotonic growth from the `postDataByKey` leak)
- [ ] **AC-P4** A 100 MB video uploads to completion on a device with ≤ 3 GB RAM without an `OutOfMemoryError`, a renderer kill, or a PHP `Allowed memory size exhausted`
- [ ] **AC-P5** During upload, the UI remains interactive — scrolling and navigation respond within 200 ms
- [ ] **AC-P6** Progress advances at least once per second on a throttled connection; it never sits frozen for more than 5 s while bytes are moving
- [ ] **AC-P7** Every upload request has a finite timeout; no request can hang indefinitely
- [ ] **AC-P8** A permanently-rejected chunk (422) is attempted exactly once, not four times
- [ ] **AC-P9** Uploading a 17.4 MB video takes no longer than (file size ÷ measured link throughput) × 1.5
- [ ] **AC-P10** Previewing a 17.4 MB video does not allocate more than 2× the file size in the renderer heap
- [ ] **AC-P11** The download button on a 100 MB file does not exhaust PHP memory
- [ ] **AC-P12** On production, two concurrent video completions do not produce lock-wait-timeout errors

---

## 18. Regression risks

| # | Change | Regression risk | Detection |
|---|---|---|---|
| 1 | `POOL_SIZE = 1` on device | Slower uploads if the mutex assumption is wrong for some route | Measure wall-clock time before/after on the same file and link; the mutex is unconditional in `run_php_request`, so a slowdown would indicate an unexpected code path |
| 2 | Smaller chunks | More requests → more per-request overhead could exceed the memory saving in wall-clock terms | A/B measure 1 MB / 2 MB / 5 MB on a real device before finalising |
| 3 | Fixing the body-map leak | If aliases are removed rather than converted to pointers, the URL-fallback lookup breaks and some requests lose their body → empty uploads | Test form POSTs (`logFormPostData` path, `WebViewManager.kt:778`) explicitly — they rely on URL-keyed lookup |
| 4 | `config:cache` | Stale `env()` values at runtime | Verify the app boots with correct DB path and storage path after caching |
| 5 | Throttling progress updates | Progress appears less smooth | Cap at ≥ 4 Hz |
| 6 | Removing hot-path logs | Loss of diagnostic capability for the next incident | Gate behind a flag; do not delete |
| 7 | Upload-by-path | Files sourced from `content://` URIs may not have a readable filesystem path | Keep the blob path as a fallback and select per source |
| 8 | Moving `ffmpeg` out of the transaction | Thumbnail generation failure no longer rolls back the file record — arguably correct, but a behaviour change | Verify the UI tolerates a missing thumbnail |
| 9 | Lowering `WATCHDOG_MS` | Chunks compete with UI requests more aggressively | AC-P5 covers this |

---

## 19. Testing plan

### 19.1 Instrumentation

```bash
# JVM + native heap, sampled during upload
adb shell dumpsys meminfo <application.id>

# Continuous sampling
while true; do adb shell dumpsys meminfo <application.id> | grep -E "TOTAL|Native Heap|Dalvik Heap"; sleep 2; done

# GC and OOM events
adb logcat | grep -iE "GC_|OutOfMemory|Low on memory|Background concurrent"

# Renderer process kills
adb logcat | grep -iE "chromium|Render process"
```

Network throttling — use a real constrained link where possible; otherwise a proxy with bandwidth shaping. **Do not rely on emulator network profiles for acceptance.**

### 19.2 Memory benchmark matrix

Baseline each row on the current build, then re-measure after each fix.

| # | Scenario | Metric | Target |
|---|---|---|---|
| 1 | Idle app, workspace open | Baseline JVM heap | Reference |
| 2 | Upload 17.4 MB video | Peak JVM heap above baseline | < 30 MB |
| 3 | Upload 100 MB video | Peak JVM heap above baseline | < 60 MB |
| 4 | Upload 5 files concurrently | Peak JVM heap above baseline | < 80 MB |
| 5 | 20 consecutive uploads | Heap after a forced GC | Within 10 MB of baseline |
| 6 | Upload 100 MB on a 3 GB device | OOM / renderer kill | None |
| 7 | Preview a 17.4 MB video | Peak renderer heap | < 2× file size |
| 8 | Download a 100 MB file | PHP peak memory | No `Allowed memory size exhausted` |

### 19.3 Throughput benchmark matrix

Fix the file (17.4 MB) and the link, vary the configuration:

| Config | Wall-clock | Peak heap | Progress smoothness |
|---|---|---|---|
| 5 MB × 4 (current) | | | |
| 5 MB × 1 | | | |
| 2 MB × 1 | | | |
| 1 MB × 1 | | | |
| 1 MB × 1 + `config:cache` | | | |

Run each three times and take the median. Choose the configuration with the best wall-clock time subject to peak heap < 30 MB.

### 19.4 Device matrix — mandatory

| Class | Requirement |
|---|---|
| Low-end, ≤ 3 GB RAM | Mandatory |
| The device that currently fails (Diagnosis 01) | Mandatory |
| Mid-range, current Android | Mandatory |

Emulator results are informational only and do not satisfy any acceptance criterion.

### 19.5 Network conditions

| Condition | Expected |
|---|---|
| Good Wi-Fi | Baseline throughput |
| Throttled to ~1 Mbit/s | Completes; progress visible throughout; no timeout |
| Throttled to ~256 kbit/s | Completes or fails cleanly with a resumable state — **never** hangs |
| Intermittent (toggle every 30 s) | Resumes; total bytes transferred < 2× file size |
| Captive portal (`navigator.onLine === true`, no route) | Fails within the timeout, does not hang |

### 19.6 Leak regression test

```
1. Record baseline:            adb shell dumpsys meminfo <pkg> | grep TOTAL
2. Upload 20 × 5 MB files
3. Force GC:                   adb shell am send-trim-memory <pkg> RUNNING_CRITICAL
4. Wait 10 s
5. Record again
6. Assert: delta < 10 MB
```

Repeat with **failing** uploads (point the chunk endpoint at an error) to exercise the request-ID-keyed leak path specifically — this is the unbounded variant and is the one that will kill a long session.

### 19.7 UI responsiveness

During a 100 MB upload, verify: scrolling the file list is smooth; navigating between patients completes within 1 s; opening a preview does not freeze the app. Record with `adb shell dumpsys gfxinfo <package>` and check for jank frames.

---

## 20. Recommended fix order

| Order | Fix | Effort | Impact | Depends on |
|---|---|---|---|---|
| 1 | FIX-PERF-2 — `POOL_SIZE = 1` on device | Trivial | ~75% peak memory reduction | — |
| 2 | FIX-PERF-4 — `CHUNK_SIZE` 1–2 MB on device | Trivial | ~60% further reduction; smoother progress | Measure first |
| 3 | FIX-PERF-3 — fix the `postDataByKey` leak | Small | Removes unbounded growth | — |
| 4 | FIX-PERF-5 — timeouts everywhere | Small | Removes permanent hangs | — |
| 5 | FIX-PERF-7 — status-aware retry | Small | 4× less waste on permanent failures | Diagnosis 02 FIX-REL-1 |
| 6 | FIX-PERF-6 — scheduler gate | Small | Removes up to 4 s/chunk stall | After 1 |
| 7 | FIX-PERF-9 — cap the download route, fix the preview loop | Medium | Removes preview/download OOM | — |
| 8 | FIX-PERF-10 — `ffmpeg` out of the transaction | Small | Production lock contention | — |
| 9 | FIX-PERF-8 — gate hot-path logging | Small | Steady I/O reduction | — |
| 10 | `config:cache` in the build | Small | Per-request boot cost | Verify `env()` usage |
| 11 | FIX-PERF-11 — cache SHA-256, stream sync chunks | Medium | CPU on low-end devices | — |
| 12 | FIX-PERF-12 — throttle progress reactivity | Small | Main-thread smoothness | After 1 |
| 13 | FIX-PERF-1 — replace the string bridge | Large | Removes the amplification entirely | Architectural |

**Items 1 and 2 are two lines of configuration and deliver roughly 95% of the memory benefit. They should ship in the same release as the Diagnosis 01 fix**, because the infrastructure fix is what will finally allow uploads to run — and the first thing a user will do is upload a large video.

---

## 21. Definition of done

- [ ] `configureUploads()` called at bootstrap with device-appropriate `poolSize` and `chunkSize`
- [ ] `useOfflineUploads.js` reads the shared configuration instead of its own `CHUNK_SIZE` constant
- [ ] `postDataByKey` stores one body and pointer aliases; all keys are removed on consume; the map is swept by `cleanupOldRequests`
- [ ] `extractFromPostBody` skipped for multipart and CSRF-exempt paths
- [ ] Every upload-path request has a finite timeout; `uploadDirectly` has a timeout, an `AbortController`, and retry
- [ ] Retry is status-aware (no 4xx retries); `Retry-After` honoured
- [ ] `WATCHDOG_MS` reduced or the gate bypassed when `POOL_SIZE === 1`
- [ ] `routes/web.php:420` capped at `BASE64_MAX_BYTES` with a chunked fallback
- [ ] `InlineFilePreview.vue` byte loop replaced; preview size guard reviewed
- [ ] `GenerateThumbnailJob::dispatch` moved outside the merge transaction
- [ ] Hot-path logging gated behind an explicit flag (items 1, 3, 6, 8 of §9)
- [ ] `config:cache` in the build pipeline, with `env()` usage verified
- [ ] AC-P1 through AC-P12 measured and passing on the mandatory device matrix
- [ ] Before/after memory and throughput numbers recorded in the change record
