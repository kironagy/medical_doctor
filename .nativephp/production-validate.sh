#!/usr/bin/env bash

# .nativephp/production-validate.sh
#
# This script is invoked after a production build completes.
# It verifies the generated APK / IPA meets basic integrity requirements
# before the build is considered ready for distribution.
#
# It is referenced by native-build-production.sh.

set -euo pipefail

PLATFORM="${1:-android}"
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_ROOT="$(cd "$SCRIPT_DIR/.." && pwd)"

echo "=== NativePHP Production Validation ($PLATFORM) ==="
echo "Project root: $PROJECT_ROOT"

case $PLATFORM in
    android)
        # Locate the built APK
        APK_DIR="$PROJECT_ROOT/nativephp/android/app/build/outputs/apk/release"
        if [[ ! -d "$APK_DIR" ]]; then
            echo "ERROR: APK output directory not found: $APK_DIR"
            exit 1
        fi

# Prefer the signed APK (post-signing), fall back to any APK
APK_FILE="$APK_DIR/app-release-signed.apk"
if [[ ! -f "$APK_FILE" ]]; then
    APK_FILE=$(find "$APK_DIR" -name "*.apk" -type f -print -quit 2>/dev/null || echo "")
fi
if [[ -z "$APK_FILE" ]]; then
            echo "ERROR: No APK file found in $APK_DIR"
            exit 1
        fi

        echo "APK found: $APK_FILE"
        APK_SIZE=$(stat -f%z "$APK_FILE" 2>/dev/null || stat -c%s "$APK_FILE" 2>/dev/null)
        echo "APK size: ${APK_SIZE} bytes"

        # Basic sanity checks
        if [[ "$APK_SIZE" -lt 1048576 ]]; then
            echo "ERROR: APK is suspiciously small ($APK_SIZE bytes) — likely incomplete"
            exit 1
        fi

        if ! unzip -l "$APK_FILE" >/dev/null 2>&1; then
            echo "ERROR: APK is not a valid zip archive"
            exit 1
        fi

        echo "APK is a valid zip archive"
        echo "APK contains $(unzip -l "$APK_FILE" 2>/dev/null | tail -1 | awk '{print $1}') entries"

        # Check for critical native libraries
        if ! unzip -l "$APK_FILE" 2>/dev/null | grep -q "lib/arm64-v8a/"; then
            echo "WARNING: No arm64-v8a native libraries found in APK"
        else
            echo "Native libraries (arm64-v8a) present"
        fi

        # Check for PHP binaries
        if ! unzip -l "$APK_FILE" 2>/dev/null | grep -q "php"; then
            echo "WARNING: No PHP binaries found in APK"
        else
            echo "PHP binaries present"
        fi

# Check APK signature — must be signed for production distribution
if command -v apksigner >/dev/null 2>&1; then
    echo "Verifying APK signature..."
    if apksigner verify --verbose "$APK_FILE" > /dev/null 2>&1; then
        echo "APK signature: VALID"
    else
        echo "❌ APK signature: MISSING or INVALID — unsigned APKs cannot be installed on production devices"
        exit 1
    fi
else
    echo "⚠️ apksigner not found — skipping signature verification (install Android SDK Build-Tools)"
fi
        ;;

    ios)
        # Locate the built IPA
        IPA_DIR="$PROJECT_ROOT/nativephp/ios/build/outputs"
        if [[ ! -d "$IPA_DIR" ]]; then
            echo "ERROR: iOS output directory not found: $IPA_DIR"
            exit 1
        fi

        IPA_FILE=$(find "$IPA_DIR" -name "*.ipa" -type f | head -1)
        if [[ -z "$IPA_FILE" ]]; then
            echo "ERROR: No IPA file found in $IPA_DIR"
            exit 1
        fi

        echo "IPA found: $IPA_FILE"
        IPA_SIZE=$(stat -f%z "$IPA_FILE" 2>/dev/null || stat -c%s "$IPA_FILE" 2>/dev/null)
        echo "IPA size: ${IPA_SIZE} bytes"

        if [[ "$IPA_SIZE" -lt 1048576 ]]; then
            echo "ERROR: IPA is suspiciously small — likely incomplete"
            exit 1
        fi

        if ! unzip -l "$IPA_FILE" >/dev/null 2>&1; then
            echo "ERROR: IPA is not a valid zip archive"
            exit 1
        fi

        echo "IPA is a valid zip archive"
        echo "IPA contains $(unzip -l "$IPA_FILE" 2>/dev/null | tail -1 | awk '{print $1}') entries"
        ;;

    *)
        echo "ERROR: Unsupported platform: $PLATFORM"
        exit 1
        ;;
esac

echo "=== Validation complete ==="
