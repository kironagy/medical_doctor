# Diagnosis 01 — Upload Infrastructure

**Project:** Medical_Plus_v4 (Laravel 13 + Vue 3 + Inertia + NativePHP Mobile 3.3 / Android)
**Branch:** `pro-version`
**Scope of this document:** the platform and environment layer that makes an upload physically possible — PHP runtime configuration, temporary-file allocation, the multipart request parser, storage path resolution, and filesystem-disk behaviour.
**Out of scope (covered by sibling documents):** error semantics and data integrity (02), memory/throughput (03), structural/API design (04).

This document is self-contained. Everything needed to implement and verify the fixes is here.

---

## 1. Executive statement

**Every file upload on the Android device fails at a single line of PHP**, because the app never tells PHP where it is allowed to create temporary files. PHP falls back to its compiled-in default `/tmp`, which does not exist inside an Android application sandbox. The resulting PHP diagnostic is converted by Laravel into a thrown exception, which reaches the global handler as **HTTP 500**.

The mechanism to fix this already exists in the codebase and works correctly — it is simply pointed at an environment variable name that PHP does not read.

**Severity:** P0 — blocks all uploads.
**Blast radius:** 100% of on-device uploads (images, videos, PDFs, every size, online and offline).
**Device dependence:** the failure is environment-dependent, which is why it reproduces on one phone and not another.

---

## 2. Observed symptoms (field evidence)

Three screenshots from two different physical devices running the same APK.

### 2.1 Device B, offline mode

| File | Size | Progress | Error text |
|---|---|---|---|
| `VID-20260724-WA0005.mp4` | 17.4 MB | `0/4` | `Request failed with status code 500` |

### 2.2 Device B, offline mode (second attempt, both file types)

| File | Size | Progress | Error text |
|---|---|---|---|
| `IMG-20260724-WA0004.jpg` | 85.8 KB | — | `Request failed with status code 500` |
| `VID-20260724-WA0005.mp4` | 17.4 MB | `0/4` | `...ated in the system's temporary directory` |

### 2.3 Device B, **online** mode — the decisive evidence

| File | Size | Progress | Error text |
|---|---|---|---|
| `IMG-20260806-WA0000.jpg` | 144.8 KB | — | `Request failed with status code 500` |
| `VID-20260724-WA0005.mp4` | 17.4 MB | `0/4` | `tempnam(): file created in the system's te...` |

### 2.4 What these three observations prove

1. **The error string is a verbatim PHP internal diagnostic.** `tempnam(): file created in the system's temporary directory` is not application text; it is emitted by the PHP engine itself.
2. **The failure is not network-mode-specific.** It reproduces identically with connectivity ON and OFF, therefore it is *not* in the offline uploader or the online uploader — it is in code shared by both.
3. **The failure is not size-dependent.** An 85.8 KB JPEG and a 17.4 MB MP4 fail identically.
4. **`0/4` is arithmetically consistent.** `ceil(17.4 MB ÷ 5 MB) = 4` chunks, and `0` completed means the very first chunk request (`chunk_index = 0`) failed. There is no partial success — the failure is at the entry point of request handling, before any upload logic runs.
5. **The two files show different error strings for the same root cause.** This is an error-reporting artifact, not two different bugs — see §7.4 and Diagnosis 02.

---

## 3. Root cause — full execution flow

### 3.1 The failing line

**File:** `app/Http/Middleware/ParseMobileMultipartMiddleware.php`
**Lines:** 187–190

```php
if ($filename !== null) {
    $tmpPath = tempnam(sys_get_temp_dir(), 'nphp_upl_');
    $tmpFp   = fopen($tmpPath, 'wb');
}
```

### 3.2 Why this line executes on every single on-device upload

The Android PHP runtime **never populates `$_FILES`**. Two independent mechanisms guarantee this:

**(a) The SAPI never declares a multipart content type.**
`nativephp/android/app/src/main/cpp/php_bridge.c:569–575`

```c
const char *content_type = getenv("CONTENT_TYPE");
if (!content_type) content_type = getenv("HTTP_CONTENT_TYPE");
if (content_type && strstr(content_type, "json")) {
    SG(request_info).content_type = "application/json";
} else {
    SG(request_info).content_type = "application/x-www-form-urlencoded";
}
```

`multipart/form-data` is never passed through, so PHP's built-in rfc1867 multipart handler is never invoked.

**(b) The dispatch preamble explicitly clears the superglobal.**
`nativephp/android/app/src/main/cpp/php_bridge.c:637` (inside the PHP `eval_code` heredoc):

```
"    $_FILES = [];\n"
```

**Consequence:** the guard in the middleware always takes the parse branch.

`app/Http/Middleware/ParseMobileMultipartMiddleware.php:57–61`

```php
$alreadyParsed = $request->files->count() > 0;   // always 0 on device
if (!$alreadyParsed) {
    $this->parseMultipartStream($request, $contentType);   // → reaches line 188
}
```

The middleware is registered **globally and first**, so it runs before routing, auth, or CSRF:

`bootstrap/app.php`
```php
->withMiddleware(function (Middleware $middleware): void {
    $middleware->prepend(\App\Http\Middleware\ParseMobileMultipartMiddleware::class);
```

**Therefore: 100% of on-device file uploads reach `tempnam(sys_get_temp_dir(), ...)`.** There is no alternate path.

### 3.3 What `sys_get_temp_dir()` returns on this build

PHP resolves the temporary directory in this order:

1. The `sys_temp_dir` INI directive, if set.
2. The `TMPDIR` environment variable, if set.
3. The compiled-in default — `/tmp` on POSIX builds.

**None of steps 1 or 2 are satisfied by this application.** Verified by exhaustive search:

```
$ grep -rn 'TMPDIR' nativephp/android/app/src/
(zero results)

$ grep -rn "sys_get_temp_dir\|tempnam\|TMPDIR\|sys_temp_dir\|upload_tmp_dir\|NATIVEPHP_TEMPDIR" \
        app/ config/ bootstrap/ nativephp/android/app/src/main/java/
app/Http/Middleware/ParseMobileMultipartMiddleware.php:188:   tempnam(sys_get_temp_dir(), 'nphp_upl_');
app/Services/Sync/FileSyncService.php:212:                    tempnam(sys_get_temp_dir(), 'chunk_');
nativephp/.../bridge/LaravelEnvironment.kt:840:               "NATIVEPHP_TEMPDIR" to context.cacheDir.absolutePath
```

**The only INI sources in the entire system:**

| Source | File:line | Contents |
|---|---|---|
| Embed SAPI | `php_bridge.c:136–139` | `output_buffering=4096`, `implicit_flush=0`, `display_errors=1`, `error_reporting=E_ALL` |
| Ephemeral runtime | `php_bridge.c:975` | `display_errors=1`, `implicit_flush=1`, `output_buffering=0` |
| Generated `php.ini` | `LaravelEnvironment.kt:893–897` | `curl.cainfo`, `openssl.cafile` — **nothing else** |
| Runtime `ini_set` | `ParseMobileMultipartMiddleware.php:35` | `@ini_set('memory_limit', '256M')` |

The generated `php.ini`, verbatim:

```kotlin
val phpIni = """
curl.cainfo="${context.filesDir.absolutePath}/$CACERT_FILE"
openssl.cafile="${context.filesDir.absolutePath}/$CACERT_FILE"
"""
File(context.filesDir, PHP_INI_FILE).writeText(phpIni)
```

**Not set anywhere:** `sys_temp_dir`, `upload_tmp_dir`, `post_max_size`, `upload_max_filesize`, `max_execution_time`, `max_input_time`. All inherit the PHP build defaults while the application validates uploads up to **5 GiB**.

### 3.4 The dead environment variable

`nativephp/android/app/src/main/java/com/nativephp/mobile/bridge/LaravelEnvironment.kt:840`

```kotlin
setEnvironmentVariables(
    // ...
    "NATIVEPHP_PLATFORM" to "android",
    "NATIVEPHP_TEMPDIR" to context.cacheDir.absolutePath
)
```

`NATIVEPHP_TEMPDIR` is **written and never read**. Confirmed: the grep above returns exactly one occurrence across the entire tree — this definition line. Nothing in `app/`, `config/`, `bootstrap/`, the C sources, or the Kotlin sources consumes it. PHP reads `TMPDIR`; it has no knowledge of `NATIVEPHP_TEMPDIR`.

**The setter mechanism itself is correct and functional.** `LaravelEnvironment.kt:924–933`:

```kotlin
private fun setEnvironmentVariable(name: String, value: String) {
    try {
        val result = nativeSetEnv(name, value, 1)
        if (result != 0) {
            throw RuntimeException("Failed to set environment variable: $name")
        }
    } catch (e: Exception) {
        Log.e(TAG, "Failed to set environment variable: $name", e)
        throw e
    }
}
```

`nativeSetEnv` is a JNI wrapper over POSIX `setenv()` with `overwrite = 1`. It successfully sets `DB_CONNECTION`, `LARAVEL_STORAGE_PATH`, `QUEUE_CONNECTION`, and ~30 other variables that the app depends on and which demonstrably work. **The plumbing is proven; only the variable name is wrong.**

### 3.5 Why `/tmp` fails on Android

An Android application process runs under a per-app UID inside an SELinux-confined sandbox. The filesystem root is largely read-only, and `/tmp`:

- does not exist on stock AOSP or most vendor ROMs;
- where a vendor has created it, it is typically not writable by app UIDs;
- is not part of any Android compatibility guarantee, so its presence and permissions vary by ROM, vendor, Android version, and SELinux policy.

The writable locations available to an app are `context.cacheDir`, `context.filesDir`, and the external app-specific dirs — none of which PHP can discover without being told.

### 3.6 Why it worked on Device A and not Device B

**This is not a network-speed or CPU difference.** The application depends on an unguaranteed filesystem path. Device A happened to run a ROM configuration in which `tempnam` on the fallback path succeeded; Device B does not. Because nothing in the codebase establishes a writable temp directory, **success on any device is coincidental and unreproducible**. Treat Device A's behaviour as luck, not as a baseline.

This also means: *any* future device, OS update, or ROM change can regress a device that currently works. The fix must be deterministic, not probabilistic.

### 3.7 The failure chain, step by step

```
JS: uploadHttp.post('/api/v1/chunk/chunk', FormData{upload_id, chunk_index, chunk})
  │
  ├─ WebViewManager.kt XHR shim serialises the body to a base64 multipart string
  │
  ├─ PHPBridge.consumePostData() hands the body to the native runtime
  │
  ├─ php_bridge.c:569  content_type forced to application/x-www-form-urlencoded
  ├─ php_bridge.c:637  $_FILES = []
  │
  ├─ Laravel boots → ParseMobileMultipartMiddleware::handle()   [prepended, runs first]
  │    │
  │    ├─ line 58:  $alreadyParsed = $request->files->count() > 0   → false
  │    ├─ line 60:  parseMultipartStream($request, $contentType)
  │    │    │
  │    │    ├─ parses headers, finds Content-Disposition with filename=
  │    │    │
  │    │    └─ line 188: tempnam(sys_get_temp_dir(), 'nphp_upl_')
  │    │         │
  │    │         ├─ sys_get_temp_dir() → "/tmp"   (no sys_temp_dir INI, no TMPDIR env)
  │    │         │
  │    │         ├─ php_do_open_temporary_file("/tmp", "nphp_upl_", ...) → -1  (ENOENT/EACCES)
  │    │         │
  │    │         ├─ php_error_docref(NULL, E_NOTICE,
  │    │         │       "file created in the system's temporary directory")
  │    │         │
  │    │         └─ retries php_get_temporary_directory() → also "/tmp" → also fails → returns false
  │    │
  │    │    ── the E_NOTICE is intercepted before tempnam even returns ──
  │    │
  │    ├─ Illuminate\Foundation\Bootstrap\HandleExceptions::handleError()
  │    │    error_reporting() & E_NOTICE  →  truthy   (error_reporting=E_ALL from php_bridge.c:139;
  │    │                                              Laravel additionally calls error_reporting(-1))
  │    │    throw new ErrorException("tempnam(): file created in the system's temporary directory", ...)
  │    │
  │    └─ line 188 is NOT inside try/catch → propagates
  │
  └─ bootstrap/app.php  $exceptions->render() closure
       ErrorException is not HttpExceptionInterface  →  $status = 500
       returns JSON { message: "tempnam(): file created in the system's temporary directory", ... }
```

**Secondary outcome:** if the E_NOTICE were suppressed (e.g. `@tempnam(...)`), `tempnam` returns `false`, and line 189 becomes `fopen(false, 'wb')` → `fopen("", "wb")` → **`ValueError: Path cannot be empty`** on PHP 8.3/8.4 → also a 500. Suppressing the notice is therefore *not* a fix.

**Tertiary outcome:** if both the notice and the ValueError were suppressed, `$tmpFp === false`, and line 246 `if ($tmpFp)` is false — the parser then silently treats the entire binary file part as a **text field**. `$request->file('chunk')` becomes `null`, validation fails with "The chunk field is required", and that validation failure is *also* rendered as a 500 (see Diagnosis 02). **All three degradation paths end in an unexplained 500.**

---

## 4. Evidence

### 4.1 The diagnostic string is present in the shipped binary

```
$ strings nativephp/android/app/build/intermediates/merged_native_libs/release/\
mergeReleaseNativeLibs/out/lib/arm64-v8a/libphp_wrapper.so | grep -i "temporary directory"

Phar entry is a temporary directory (not an actual entry in the archive), cannot delete metadata
file created in the system's temporary directory          ← ★ the observed message
Phar entry is a temporary directory (not an actual entry in the archive), cannot set metadata
Phar entry "%s" is a temporary directory (not an actual entry in the archive), cannot chmod
die('Could not locate temporary directory to extract phar');
```

Also present in `.../stripped_native_libs/release/stripReleaseDebugSymbols/out/lib/arm64-v8a/libphp_wrapper.so` — i.e. in the artifact that actually ships.

The string originates from PHP's `main/php_open_temporary_file.c`, in `php_open_temporary_fd_ex()`:

```c
fd = php_do_open_temporary_file(dir, pfx, opened_path_p);
if (fd == -1) {
    if (!(flags & PHP_TMP_FILE_SILENT)) {
        php_error_docref(NULL, E_NOTICE, "file created in the system's temporary directory");
    }
    goto def_tmp;    /* retry with php_get_temporary_directory() */
}
```

This confirms the semantics: **the notice is raised precisely when the directory handed to `tempnam` could not be used.** The UI text is truncated at the component's `max-w-[200px]` (`UploadManager.vue:181`), which is why it renders as `tempnam(): file created in the system's te...`.

### 4.2 Exhaustive negative evidence for TMPDIR

```
$ grep -rn 'TMPDIR' nativephp/android/app/src/
(no output — exit 1)
```

### 4.3 The env-setter works (positive control)

`LaravelEnvironment.kt:814–878` sets ~30 variables through the same `setEnvironmentVariable` path, including `DB_CONNECTION=sqlite`, `LARAVEL_STORAGE_PATH`, `QUEUE_CONNECTION=sync`, `SESSION_SAVE_PATH`. The application demonstrably runs on SQLite with the correct storage root, proving `nativeSetEnv` functions correctly.

### 4.4 Live confirmation on the failing device

```bash
adb shell run-as <application.id> ls -la /tmp
```
Expected on Device B: `ls: /tmp: No such file or directory`.

```bash
adb logcat -c && adb logcat | grep -iE "temporary directory|UPLOAD/API EXCEPTION|nphp_upl"
```
Then attempt any upload. Expected: `ErrorException` originating at `ParseMobileMultipartMiddleware.php:188`.

---

## 5. Second occurrence of the same defect

**File:** `app/Services/Sync/FileSyncService.php`
**Lines:** 211–213

```php
$tmpChunkPath = tempnam(sys_get_temp_dir(), 'chunk_');
file_put_contents($tmpChunkPath, $chunkData);
```

Context: `uploadLargeFileResumable()`, the device→production server sync path. Same root cause, same failure mode, and worse consequences on failure:

- `file_put_contents(false, $chunkData)` → `ValueError: Path cannot be empty`.
- The exception aborts the sync cycle, so **no queued file ever reaches the production server** on an affected device.
- Because `RunManualSyncJob` has `tries = 1` (`app/Jobs/RunManualSyncJob.php:45`), there is no retry.

**This must be fixed together with the middleware.** Fixing only the middleware produces a device that appears to upload correctly locally but silently never syncs.

---

## 6. Related infrastructure defects in the same layer

These are not the root cause but live in the same layer and will produce infrastructure-shaped failures once the primary defect is fixed.

### 6.1 Storage disk swallows all write failures

`config/filesystems.php:33–39`

```php
'local' => [
    'driver' => 'local',
    'root'   => storage_path('app/private'),
    'serve'  => true,
    'throw'  => false,     // ← failures return false instead of throwing
    'report' => false,     // ← and are not even logged
],
```

Combined with call sites that discard the return value:

| File:line | Code | Consequence |
|---|---|---|
| `app/Domains/Media/Services/UploadService.php:62` | `$disk->putFileAs("patients/{$patientUuid}", $file, $fileName);` | return discarded |
| `app/Domains/Media/Services/UploadService.php:79` | `'file_path' => $relPath` written unconditionally | DB row points at a file that may not exist |
| `app/Http/Controllers/Api/Mobile/FileController.php:158–162` | `$uploadedFile->storeAs(...)` | `false` can be written into a `NOT NULL VARCHAR(255)` column |
| `app/Services/Upload/ChunkUploadService.php:167–169` | `$chunk->storeAs(...)`, `$disk->move(...)` | both return values discarded |

A disk-full or permission failure therefore produces a `patient_files` row with `upload_status = 'ready'` pointing at nothing. **This is silent patient-data loss.** (Integrity consequences are elaborated in Diagnosis 02; the *configuration* decision belongs here.)

### 6.2 Storage root on device

`LaravelEnvironment.kt:821`

```kotlin
"LARAVEL_STORAGE_PATH" to "${appStorageDir.absolutePath}/persisted_data/storage",
```

So `storage_path('app/private')` resolves under `.../persisted_data/storage/app/private`. All upload targets resolve beneath it:

| Purpose | Relative path | Defined at |
|---|---|---|
| Final files | `patients/{patientUuid}/{fileUuid}.{ext}` | `UploadSessionService.php:33–35`, `ChunkMergeService.php:55`, `Mobile/FileController.php:159` |
| Legacy chunk staging | `tmp/chunks/{sessionUuid}` | `app/Domains/Media/Models/UploadSession.php:62–65` |
| Offline pending | `uploads/pending/{uuid}.{ext}` | `app/Services/OfflineUploadService.php:16` |

Note `tmp/chunks/...` is **inside the app's own storage** — the codebase already demonstrates the correct pattern for app-owned temporary data. The multipart middleware simply does not use it.

### 6.3 No request-size ceiling anywhere

- No `php.ini` or `.user.ini` in the repository (`find . -name "*.ini"` returns nothing outside `vendor/` and `node_modules/`).
- `public/.htaccess` (25 lines) contains no `LimitRequestBody` and no `php_value` directives.
- No body-size middleware is registered.
- Application-level limits are large: 5 GiB (`ChunkUploadController.php:65`), 500 MiB single-shot (`UploadController.php:20`, `Mobile/FileController.php:102`).

On a real web SAPI (production MySQL, or `php artisan serve` during development) exceeding `post_max_size` yields empty `$_POST` **and** empty `$_FILES`. The middleware then attempts to read `php://input`, which is not readable for `multipart/form-data` on a web SAPI, returns silently, and the request proceeds with no file — surfacing as a confusing validation error rather than a 413.

### 6.4 Third temp-dir failure mode — web SAPI only

`vendor/symfony/http-foundation/File/UploadedFile.php:287`

```php
\UPLOAD_ERR_NO_TMP_DIR => 'File could not be uploaded: missing temporary directory.',
```

Thrown as `NoTmpDirFileException` from `UploadedFile::move()` when `$_FILES[...]['error'] === 6`. This fires only where PHP's own rfc1867 parser runs — i.e. production / `artisan serve`, never on Android. Include it in the production-side check (§9.3).

### 6.5 Per-request logging cost in the middleware

`ParseMobileMultipartMiddleware.php:285–290`

```php
Log::error('[ParseMobileMultipartStream] Parsed multipart body', [
    'url' => $request->fullUrl(), 'boundary' => $boundary,
    'fields' => array_keys($textFields), 'files' => array_keys($fileFields),
]);
```

This is informational content logged at `error` level on **every** parsed multipart request — one synchronous disk write per chunk. It also pollutes the log channel that real errors use, making the actual `ErrorException` harder to find. (Throughput impact quantified in Diagnosis 03; the level/placement decision belongs here.)

---

## 7. Call graph — device upload request

```
WebView (JS)
  └── XMLHttpRequest.send  [patched: WebViewManager.kt:659]
        └── AndroidPOST.logPostData()             [JSBridge, WebViewManager.kt:757]
              └── PHPBridge.storePostData()       [PHPBridge.kt:323]
        └── originalXHRSend()
              └── WebViewClient.shouldInterceptRequest  [WebViewManager.kt:296]
                    └── PHPBridge.consumePostData()     [PHPBridge.kt:341]
                          └── native_persistent_dispatch  [php_bridge.c:465]
                                ├── pthread_mutex_lock(&g_php_request_mutex)   [php_bridge.c:271]
                                ├── setenv(REQUEST_URI / REQUEST_METHOD / ...) [php_bridge.c:525-531]
                                ├── SG(request_info).content_type = "application/x-www-form-urlencoded"
                                │                                              [php_bridge.c:574]
                                ├── $_FILES = []                               [php_bridge.c:637]
                                └── php_execute_script(public/index.php)
                                      └── Laravel kernel
                                            └── ParseMobileMultipartMiddleware::handle()   [:25]
                                                  └── parseMultipartStream()               [:60]
                                                        └── ★ tempnam(sys_get_temp_dir())  [:188]  ← FAILS
                                                              └── E_NOTICE
                                                                    └── HandleExceptions::handleError()
                                                                          └── throw ErrorException
                                                                                └── bootstrap/app.php render()
                                                                                      └── HTTP 500
```

Nothing downstream of line 188 ever executes. `ChunkUploadController`, `ChunkUploadService`, `UploadSessionService`, and the entire storage layer are never reached.

---

## 8. Affected files

| File | Lines | Role |
|---|---|---|
| `app/Http/Middleware/ParseMobileMultipartMiddleware.php` | 35, 57–61, 187–190, 246–254, 285–290 | **Primary defect.** Temp allocation, parse guard, `UploadedFile` construction, logging |
| `app/Services/Sync/FileSyncService.php` | 211–213 | **Secondary defect.** Identical `tempnam` pattern on the sync path |
| `nativephp/android/app/src/main/java/com/nativephp/mobile/bridge/LaravelEnvironment.kt` | 814–878, 885–900, 924–942 | Env var block (wrong name at 840); `php.ini` generation at 893–897; `setEnvironmentVariable` |
| `nativephp/android/app/src/main/cpp/php_bridge.c` | 132–141, 569–575, 637 | SAPI INI entries; content-type forcing; `$_FILES` clearing |
| `config/filesystems.php` | 33–39 | `throw => false`, `report => false` |
| `bootstrap/app.php` | `withMiddleware` prepend; `withExceptions` render closure | Middleware ordering; 500 conversion |
| `app/Domains/Media/Services/UploadService.php` | 62, 79 | Discarded `putFileAs` return |
| `app/Http/Controllers/Api/Mobile/FileController.php` | 158–162, 188 | Discarded `storeAs` return |
| `app/Services/Upload/ChunkUploadService.php` | 167–169 | Discarded `storeAs` / `move` returns |

---

## 9. Required fixes

### 9.1 FIX-INF-1 — Establish a guaranteed temp directory at the Android layer (P0)

**File:** `LaravelEnvironment.kt`, in the block at 828–841.

Set the variable name PHP actually reads, and ensure the directory exists before PHP boots:

- Create a dedicated subdirectory (e.g. `context.cacheDir/php_tmp`) with `mkdirs()` and verify `canWrite()`.
- Export it as **`TMPDIR`**. Keep `NATIVEPHP_TEMPDIR` if anything external expects it, but it must no longer be the only one set.
- Fail loudly at boot (`Log.e` + a visible init error) if the directory cannot be created — a silent failure here reproduces the current bug.

**File:** `LaravelEnvironment.kt:893–897` — extend the generated `php.ini`. INI directives take precedence over the environment and cover code paths that read the INI directly:

```
sys_temp_dir="<absolute path>"
upload_tmp_dir="<absolute path>"
```

While editing this template, also set explicit ceilings rather than inheriting build defaults: `upload_max_filesize`, `post_max_size`, `memory_limit`, `max_execution_time`. Choose values consistent with the on-device chunk size decided in Diagnosis 03 — **do not** set them to 5 GiB to match the application-level validation; the chunk endpoint only ever receives one chunk per request.

**Note on cache eviction:** Android may clear `cacheDir` under storage pressure. The directory must be re-created lazily, not only at first boot — see FIX-INF-2, which provides that guarantee on the PHP side.

### 9.2 FIX-INF-2 — Remove the dependency on `sys_get_temp_dir()` in PHP (P0)

**Files:** `ParseMobileMultipartMiddleware.php:188`, `FileSyncService.php:212`.

Do not trust the ambient temp directory. Introduce a single resolver used by both call sites that:

1. Resolves a path under `storage_path()` (e.g. `storage_path('app/private/tmp/uploads')`) — the same storage root the app already owns and already writes to for `tmp/chunks/...`.
2. Creates it with `mkdir(..., 0755, true)` guarded by an `is_dir()` recheck to avoid the TOCTOU warning pattern described in Diagnosis 02 §on `mkdir`.
3. Verifies writability with `is_writable()`.
4. On failure, throws `HttpException(507, 'Upload temporary storage unavailable: <path>')` — an explicit, actionable status, **not** a PHP notice.
5. Generates unique names without `tempnam` (e.g. `uniqid('nphp_upl_', true)` plus an `fopen($path, 'xb')` exclusive-create), so no fallback to the system temp dir is possible.

**Do not** solve this with `@tempnam(...)`. Suppression converts a diagnosable 500 into silent data corruption (see §3.7, tertiary outcome).

**Cleanup requirement:** these temp files are currently unlinked only on some paths (`ParseMobileMultipartMiddleware.php:243` handles the unnamed-field case). Because the files now live inside app storage rather than a system temp dir that the OS may sweep, add an explicit sweep of `tmp/uploads` older than N hours to the existing scheduled command in `bootstrap/app.php`:

```php
$schedule->command('uploads:purge-expired --hours=6')->hourly();
```

### 9.3 FIX-INF-3 — Make disk write failures observable (P0)

**File:** `config/filesystems.php:37–38`.

Either set `'throw' => true` on the `local` disk, or audit every call site to check the return value. Given the number of call sites that currently discard it (§6.1), `'throw' => true` is the safer default; it converts silent corruption into a catchable exception.

**Regression risk:** any code path that currently relies on `delete()`/`exists()` returning `false` for a missing file will begin throwing. Audit `Storage::disk('local')->delete(` and `->move(` call sites in `UploadCleanupService`, `ChunkUploadService`, `FileCacheService`, and `OfflineUploadService` before flipping this flag. If that audit is too broad for one change, set `'report' => true` first (restores logging without changing control flow), then convert call sites incrementally.

### 9.4 FIX-INF-4 — Correct the logging level in the middleware (P1)

**File:** `ParseMobileMultipartMiddleware.php:285–290`. Change `Log::error` to `Log::debug`, or gate it behind `config('app.debug')`. Do not log per-request parse success at error level.

### 9.5 FIX-INF-5 — Production-side temp directory verification (P1)

The production MySQL deployment uses PHP's native rfc1867 parser and can hit `UPLOAD_ERR_NO_TMP_DIR` (§6.4) independently. Verify on the production host:

```bash
php -i | grep -Ei "upload_tmp_dir|sys_temp_dir|post_max_size|upload_max_filesize|memory_limit"
```

Confirm `upload_tmp_dir` exists and is writable by the web-server user, and that `post_max_size` ≥ `upload_max_filesize` ≥ the largest single request the app can produce (chunk size + multipart overhead, **not** 5 GiB).

---

## 10. Risks and edge cases

| # | Risk / edge case | Mitigation |
|---|---|---|
| 1 | Android evicts `cacheDir` under storage pressure; the temp dir vanishes mid-session | FIX-INF-2 re-creates the directory on every request; do not rely solely on boot-time creation |
| 2 | Device storage genuinely full — `mkdir` succeeds, `fopen` fails, or writes truncate | FIX-INF-2 returns `507`; add a free-space precheck before `init` and surface a user-facing message |
| 3 | `TMPDIR` set but pointing at a path on an unmounted/encrypted volume before user unlock (Direct Boot) | Use `context.cacheDir` (credential-encrypted, available post-unlock) and confirm the app does not attempt uploads pre-unlock |
| 4 | Multiple PHP runtimes (main + background sync worker) writing the same temp dir | Filenames are unique per request; the `g_php_request_mutex` serialises the main runtime. Verify the background worker uses the same resolver and unique names |
| 5 | Setting `post_max_size` too low silently truncates bodies rather than erroring | Set it explicitly above the maximum chunk size + overhead, and add a server-side assertion that received chunk size matches `Content-Length` |
| 6 | `'throw' => true` breaks existing `delete()`-on-missing-file flows | Audit listed in §9.3; stage via `'report' => true` first |
| 7 | Fix applied to the middleware only — sync still broken | FIX-INF-2 explicitly covers `FileSyncService.php:212`; both must land together |
| 8 | Emulator has `/tmp` and masks the bug | **Never validate this fix on an emulator alone.** Require at least one physical device that currently reproduces the failure |
| 9 | `php.ini` regenerated only on version change | `LaravelEnvironment.kt:885–890` gates the certificate copy on debug/version. Verify the INI write is unconditional, or bump the version constant so existing installs pick up the new directives |
| 10 | Existing installs carry a stale `php.ini` after app update | Include an explicit INI-rewrite on every boot, or key it to a `PHP_INI_VERSION` constant that this change increments |

---

## 11. Acceptance criteria

A fix is complete only when **all** of the following hold.

### AC-1 — Environment is deterministic
On a device that currently fails, after the fix:
```bash
adb shell run-as <application.id> cat files/php.ini
```
shows `sys_temp_dir` and `upload_tmp_dir` pointing at an existing, writable, app-owned path.

### AC-2 — No PHP diagnostic reaches the client
Uploading any file produces **zero** occurrences of `temporary directory`, `tempnam`, or `ErrorException` in:
```bash
adb logcat | grep -iE "temporary directory|tempnam|ErrorException"
```

### AC-3 — Both file types succeed on the previously failing device
| Input | Expected |
|---|---|
| `IMG-20260806-WA0000.jpg` (144.8 KB) | HTTP 201/200; row in `patient_files`; file present on disk; byte size equals source |
| `VID-20260724-WA0005.mp4` (17.4 MB) | Progress reaches `4/4`; HTTP 200 from `/chunk/complete`; on-disk size **exactly** 17.4 MB (byte-for-byte equal to source) |

### AC-4 — Sync path works
A file uploaded on-device while offline reaches the production server after a manual sync, with no `tempnam`/`ValueError` in the sync log. Verify `patient_files.remote_uuid` is populated.

### AC-5 — Failure is now explicit
With the temp directory deliberately made unwritable:
```bash
adb shell run-as <application.id> chmod 000 cache/php_tmp
```
an upload returns **HTTP 507** with a message naming the path — not a 500, not a PHP notice.

### AC-6 — Disk write failures are not silent
With the storage volume artificially full (or the target directory made read-only), an upload does **not** create a `patient_files` row with `upload_status = 'ready'`. Verify:
```sql
SELECT COUNT(*) FROM patient_files pf
WHERE pf.upload_status = 'ready';
-- then confirm every returned file_path exists on disk
```

### AC-7 — Cross-device determinism
The same APK succeeds on **at least three physically distinct devices**, including:
- the device that currently fails (mandatory),
- one low-RAM device (≤ 3 GB),
- one device on a different Android major version.

---

## 12. Regression risks

| # | Change | Regression risk | Detection |
|---|---|---|---|
| 1 | Setting `TMPDIR` | Other native code (curl, openssl, PHP session handler, SQLite) begins using the new directory and can fill it | Monitor `du -sh` of the temp dir across a long session; ensure the purge command covers it |
| 2 | Adding `upload_max_filesize` / `post_max_size` to `php.ini` | If set below the actual chunk size, every chunk silently fails with an empty body — a *new* class of "mystery 500" | AC-3 must run with the real production chunk size; add an explicit server-side size assertion |
| 3 | Adding `memory_limit` to `php.ini` | Overrides `@ini_set('memory_limit','256M')` at `ParseMobileMultipartMiddleware.php:35` if lower, causing OOM on large chunks | Set the INI value ≥ 256M, or remove the `ini_set` and rely on the INI alone |
| 4 | `'throw' => true` on the local disk | Previously-tolerated missing-file deletes now throw; cleanup and cache code may break | Run the full offline→sync→cleanup cycle; watch for new exceptions in `UploadCleanupService`, `FileCacheService` |
| 5 | Moving temp files into `storage/` | Storage growth is now the app's responsibility — the OS will not sweep them | Purge command must cover the new directory; add a size assertion to AC-6 |
| 6 | Changing `Log::error` → `Log::debug` | Loses a signal currently used for debugging multipart issues | Keep the log, gated on `config('app.debug')`, rather than deleting it |
| 7 | Rebuilding the APK | A different NDK/PHP build could change the compiled temp default | AC-1 makes the path explicit, removing dependence on the build default |
| 8 | Editing `php_bridge.c` | Any change to `error_reporting=E_ALL` would mask *future* notices as well as this one | **Do not** change `error_reporting` to hide the symptom; fix the cause |

---

## 13. Testing plan

### 13.1 Pre-fix — confirm the diagnosis on the failing device

```bash
adb shell run-as <application.id> ls -la /tmp
```
```bash
adb logcat -c && adb logcat | grep -iE "temporary directory|UPLOAD/API EXCEPTION|nphp_upl"
```
Then upload a small JPEG. **Record the exact output.** This is the before-baseline.

### 13.2 Unit / feature tests (add to `tests/Feature/`)

Existing coverage is one test: `tests/Feature/ChunkUploadInitTest.php` (56 lines), which asserts only that `POST /api/v1/chunk/init` returns 200 and creates a stub patient for an unknown UUID. It does **not** exercise the multipart parser, temp-file allocation, chunk writes, or the merge. `app/Http/Requests` does not exist and there are no `FormRequest` classes. Add at minimum:

| Test | Assertion |
|---|---|
| `temp_dir_resolver_creates_directory` | Resolver returns an existing writable path on a clean install |
| `temp_dir_resolver_recreates_after_deletion` | Delete the directory, call again → recreated (covers cache eviction) |
| `temp_dir_resolver_throws_507_when_unwritable` | `chmod 000` the parent → `HttpException` with status 507, message contains the path |
| `multipart_parser_produces_uploaded_file` | POST a synthetic multipart body → `$request->file('chunk')` is an `UploadedFile` with the correct size and content hash |
| `multipart_parser_handles_base64_transfer_encoding` | Body with `Content-Transfer-Encoding: base64` decodes to identical bytes (exercises `writeBodyData`, `:307–337`) |
| `chunk_endpoint_returns_507_not_500_when_temp_unavailable` | Status assertion, guarding against regression to a generic 500 |
| `disk_write_failure_does_not_create_ready_row` | Mock a failing disk → no `patient_files` row with `upload_status = 'ready'` |

### 13.3 Device matrix — mandatory

Run on **at least three physical devices**. Emulator results do not count.

| Device class | Must include |
|---|---|
| The currently failing device | Yes — mandatory |
| Low-RAM (≤ 3 GB) | Yes |
| Different Android major version | Yes |

| # | Scenario | Pass criterion |
|---|---|---|
| 1 | JPEG 144.8 KB, online | Uploads; on-disk bytes identical to source |
| 2 | MP4 17.4 MB, online | `4/4`; on-disk size exactly equals source; file plays |
| 3 | MP4 17.4 MB, offline, then manual sync | Completes locally; `remote_uuid` populated after sync |
| 4 | Same MP4 twice consecutively | Both succeed; two distinct rows; no temp-file collision |
| 5 | Five files selected at once | All succeed; temp directory returns to its baseline file count afterwards |
| 6 | App killed mid-upload, relaunched | No orphaned temp files remain after the purge window; no `ready` row with a missing file |
| 7 | Temp directory deliberately `chmod 000` | HTTP 507 with the path in the message |
| 8 | Device storage filled to <50 MB free | Explicit error; **no** `ready` row pointing at a truncated or missing file |
| 9 | Airplane mode toggled mid-upload | Failure is a network error, not a temp-dir error |

### 13.4 Integrity verification after every scenario

```bash
adb shell run-as <application.id> \
  sqlite3 persisted_data/database/medical_plus.sqlite \
  "SELECT uuid, file_name, size, upload_status, file_path FROM patient_files ORDER BY id DESC LIMIT 10;"
```

For each row: confirm the file exists at `file_path`, that its on-disk byte size equals the `size` column, and that the `size` column equals the source file's size. **A row whose on-disk size differs from `size` is a failure even if the upload reported success.**

### 13.5 Temp-directory hygiene check

After the full matrix:

```bash
adb shell run-as <application.id> ls -la cache/php_tmp
adb shell run-as <application.id> du -sh cache/php_tmp
```

Expected: empty or near-empty. A directory that grows monotonically across the matrix indicates the cleanup path in FIX-INF-2 is incomplete.

### 13.6 Production-side check

```bash
php -i | grep -Ei "upload_tmp_dir|sys_temp_dir|post_max_size|upload_max_filesize|memory_limit"
```
Then upload one file through the production web path and confirm no `NoTmpDirFileException` (§6.4).

---

## 14. Definition of done

- [ ] `TMPDIR` exported from `LaravelEnvironment.kt` to a verified writable app-owned directory
- [ ] `sys_temp_dir` and `upload_tmp_dir` present in the generated `php.ini`, with explicit `post_max_size` / `upload_max_filesize` / `memory_limit`
- [ ] `NATIVEPHP_TEMPDIR` either removed or documented as unused
- [ ] `ParseMobileMultipartMiddleware.php:188` no longer calls `sys_get_temp_dir()`
- [ ] `FileSyncService.php:212` no longer calls `sys_get_temp_dir()`
- [ ] Both call sites share one resolver that creates, verifies, and fails explicitly with 507
- [ ] Temp files are swept by the scheduled purge command
- [ ] Disk write failures are observable (`throw` or checked returns)
- [ ] `Log::error` in the middleware downgraded or gated
- [ ] AC-1 through AC-7 pass on all three device classes
- [ ] Before/after `adb logcat` captures attached to the change record
