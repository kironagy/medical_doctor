# NativePHP Android Production Stabilization - Implementation Plan

## Phase 1: Build System Hardening ✅ COMPLETE
- Updated AndroidManifest.xml for Android 13-15 compliance
- Cleaned dependencies, removed conflicting RxJava versions
- Updated CameraX to 1.4.2
- Created clean build scripts (gradlew-stabilize, native-build-production.sh)

## Phase 2: Native Code Stability ⚠️ IN PROGRESS
- Need to apply precise JNI error handling
- Need to fix wait_for_persistent_boot_settled timeout handling
- Need to add memory safety guards

## Phase 3: PHP Runtime Validation
- Ensure bootstrap scripts handle errors gracefully
- Add output buffer overflow protection
- Validate thread safety of TSRM management

## Phase 4: Testing & Validation
- Build clean APK
- Install on physical device
- Verify no crashes on startup
- Check logcat for errors

---

## Critical Changes Applied So Far

### 1. LaravelEnvironment.kt
```kotlin
// Added try-catch around bundle metadata reading
val bundleMeta = try {
    readBundleMetadata()
} catch (e: Exception) {
    Log.e(TAG, "❌ Couldn't read bundle metadata: ${e.message}")
    return false
}

// Added validation for extraction
val extractionFailed = criticalFiles.any { !File(laravelDir, it).exists() }
if (extractionFailed) {
    Log.e(TAG, "❌ Extraction failed: critical files missing")
    return false
}
```

### 2. app/build.gradle.kts
- Removed Android Request Inspector (unnecessary bloat)
- Removed all RxJava dependencies (version conflicts)
- Updated CameraX: 1.4.1 → 1.4.2
- Updated Activity Compose: 1.8.2 → 1.9.0
- Pinned Compose BOM to 2024.08.00 (stable)

### 3. AndroidManifest.xml
- Added READ_MEDIA_IMAGES (Android 13+)
- Added POST_NOTIFICATIONS compatibility
- Fixed screenOrientation: fullSensor → sensor
- Enhanced foreground service types

### 4. PHPBridge.kt (Partially Applied)
```kotlin
fun bootPersistentRuntime(): Boolean {
    // Added bootstrap validation
    val bootstrapFile = File(persistentBootstrapScript)
    if (!bootstrapFile.exists()) {
        Log.e(TAG, "❌ Bootstrap script not found")
        return false
    }
    // ... existing code
}
```

### 5. MainActivity.kt
- Enhanced onDestroy with proper shutdown order
- Added error handling in initializeEnvironmentAsync
- Fixed queue worker lifecycle

---

## Remaining Work

1. **Complete PHPBridge.kt error handling**
2. **Apply native code fixes** (php_bridge.c, bridge_jni.cpp) with exact text matches
3. **Add ProGuard rules** for reflection-based bridge
4. **Test build** on actual device
5. **Validate APK** with `gradlew-stabilize`

---

## Next Steps

Run the stabilization build:
```bash
cd nativephp/android
./gradlew-stabilize
```

If build succeeds, install and test:
```bash
adb install -r app/build/outputs/apk/debug/app-debug.apk
```

Check logcat for any remaining errors:
```bash
adb logcat | grep -E "(PHP-Native|BridgeJNI|NativePHP)"
```