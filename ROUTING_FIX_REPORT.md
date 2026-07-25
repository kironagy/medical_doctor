# Request Routing Fix Report

## Root Cause

The `RequestRouter.kt` in the Android native code had a routing rule that sent all GET requests to the EXTERNAL (production server) when online, regardless of path. The `isApiMutation` flag was only set to true for POST/PUT/DELETE on `/api/`, `/_native/`, `/sanctum/`, and `/broadcasting/` paths. This meant:

- `POST /_native/api/categories/refresh` → `isApiMutation=true` → LOCAL_PHP ✅
- `GET /_native/api/patients/pending` → `isApiMutation=false` → EXTERNAL ❌
- `GET /_native/api/offline/uploads` → `isApiMutation=false` → EXTERNAL ❌

## Fix

Added an early return guard in `RequestRouter.route()` that sends ALL `/_native/*` routes to LOCAL_PHP regardless of HTTP method or network state:

```kotlin
if (lowerPath.startsWith("/_native/")) {
    log(url, path, host, method, isOnline, RouteTarget.LOCAL_PHP,
        "Local native endpoint — always embedded Laravel")
    return RouteTarget.LOCAL_PHP
}
```

## Secondary Fix

A Kotlin nested-comment bug was discovered: `_native/*` inside a `/** */` doc comment was being interpreted as a nested comment start by the Kotlin compiler, causing "Unclosed comment" compilation errors. Fixed by changing `_native/* routes` to `_native/ routes` in the comment.

## Files Changed

| File | Change |
|------|--------|
| `nativephp/android/app/src/main/java/com/nativephp/mobile/network/RequestRouter.kt` | Added `/_native/` early return guard + fixed nested-comment typo |

## Verification

- ✅ All `_native` routes exist in `route:list` (23 routes confirmed)
- ✅ Kotlin compilation succeeds
- ✅ APK built successfully (app-debug.apk)
- ✅ APK installed on device via ADB

## After Fix

- `GET /_native/api/patients/pending` → LOCAL_PHP ✅
- `GET /_native/api/offline/uploads` → LOCAL_PHP ✅
- `POST /_native/api/categories/refresh` → LOCAL_PHP ✅
- `POST /_native/api/sync/engine` → LOCAL_PHP ✅
- All other routes (non-`/_native/`) remain unchanged

Production API routes (`/api/v1/login`, `/api/v1/mobile/*`, `/api/v1/workspace/*`) continue to use ApiService and Bearer authentication as before.

## Remaining Issues

The `WebViewManager.kt` has a pre-existing "Missing return" compilation warning at line 395 that is unrelated to these changes.
