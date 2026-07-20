#!/usr/bin/env bash

# .nativephp/production-signing.sh
#
# Signs the unsigned release APK with apksigner (Android SDK).
# Produces a distinct signed APK rather than overwriting the unsigned build.
# Credentials are loaded from .nativephp/signing.env (gitignored).

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_ROOT="$(cd "$SCRIPT_DIR/.." && pwd)"

# ── Locate apksigner dynamically ────────────────────────────────────────
find_apksigner() {
    # 1. Respect explicit ANDROID_HOME / ANDROID_SDK_ROOT
    local sdk_root=""
    if [[ -n "${ANDROID_HOME:-}" ]]; then
        sdk_root="$ANDROID_HOME"
    elif [[ -n "${ANDROID_SDK_ROOT:-}" ]]; then
        sdk_root="$ANDROID_SDK_ROOT"
    elif [[ -n "${ANDROID_HOME:-}" ]]; then
        sdk_root="$ANDROID_HOME"
    fi

    # 2. Fall back to common macOS locations
    if [[ -z "$sdk_root" ]]; then
        for candidate in \
            "$HOME/Library/Android/sdk" \
            "$HOME/Android/Sdk" \
            "/usr/local/opt/android-sdk" \
            "/opt/android-sdk"; do
            if [[ -d "$candidate" ]]; then
                sdk_root="$candidate"
                break
            fi
        done
    fi

    if [[ -z "$sdk_root" ]]; then
        echo "❌ Android SDK not found. Set ANDROID_HOME or install Android SDK." >&2
        return 1
    fi

    # 3. Pick the highest available build-tools version
    local build_tools_dir="$sdk_root/build-tools"
    if [[ ! -d "$build_tools_dir" ]]; then
        echo "❌ No build-tools found at $build_tools_dir. Install Android SDK Build-Tools." >&2
        return 1
    fi

    local apk_signer=""
    local latest_version=""
    for version_dir in "$build_tools_dir"/*; do
        local version_name
        version_name="$(basename "$version_dir")"
        local candidate="$version_dir/apksigner"
        if [[ -x "$candidate" ]]; then
            if [[ -z "$latest_version" ]] || [[ "$version_name" > "$latest_version" ]]; then
                latest_version="$version_name"
                apk_signer="$candidate"
            fi
        fi
    done

    if [[ -z "$apk_signer" ]]; then
        echo "❌ apksigner not found in any build-tools version under $build_tools_dir." >&2
        return 1
    fi

    echo "$apk_signer"
}

APKSIGNER="$(find_apksigner)"
echo "🔧 apksigner: $APKSIGNER"

# ── Load signing credentials ────────────────────────────────────────────
SIGNING_ENV="$SCRIPT_DIR/signing.env"
if [[ -f "$SIGNING_ENV" ]]; then
    # shellcheck source=.nativephp/signing.env
    source "$SIGNING_ENV"
else
    echo "⚠️ signing.env not found at $SIGNING_ENV"
    echo "   Copy signing.env.example and fill in your credentials."
    exit 1
fi

# ── Validate required variables ─────────────────────────────────────────
if [[ -z "${SIGNING_KEYSTORE_PATH:-}" || -z "${SIGNING_KEY_ALIAS:-}" ]]; then
    echo "❌ Missing signing config. Check $SIGNING_ENV"
    echo "   Required: SIGNING_KEYSTORE_PATH, SIGNING_KEY_ALIAS"
    echo "   Optional: SIGNING_KEY_STORE_PASSWORD, SIGNING_KEY_PASSWORD"
    exit 1
fi

if [[ ! -f "$SIGNING_KEYSTORE_PATH" ]]; then
    echo "❌ Keystore not found: $SIGNING_KEYSTORE_PATH"
    exit 1
fi

# ── Locate unsigned APK ─────────────────────────────────────────────────
UNSIGNED_APK="$PROJECT_ROOT/nativephp/android/app/build/outputs/apk/release/app-release-unsigned.apk"

if [[ ! -f "$UNSIGNED_APK" ]]; then
    echo "⚠️ Unsigned APK not found at expected path:"
    echo "   $UNSIGNED_APK"
    echo "   Signing will be skipped. Ensure the build completed first."
    exit 0
fi

UNSIGNED_SIZE=$(du -sh "$UNSIGNED_APK" | cut -f1)
echo "📦 Unsigned APK: $UNSIGNED_APK ($UNSIGNED_SIZE)"

# ── Sign ────────────────────────────────────────────────────────────────
SIGNED_APK="$PROJECT_ROOT/nativephp/android/app/build/outputs/apk/release/app-release-signed.apk"

echo "🔐 Signing APK..."
echo "   Keystore: $SIGNING_KEYSTORE_PATH"
echo "   Alias:    $SIGNING_KEY_ALIAS"

KS_PASS_ARG=""
KEY_PASS_ARG=""
if [[ -n "${SIGNING_KEY_STORE_PASSWORD:-}" ]]; then
    KS_PASS_ARG="--ks-pass pass:${SIGNING_KEY_STORE_PASSWORD}"
fi
if [[ -n "${SIGNING_KEY_PASSWORD:-}" ]]; then
    KEY_PASS_ARG="--key-pass pass:${SIGNING_KEY_PASSWORD}"
fi

# v1 (JAR signing) + v2 (APK Signature Scheme v2) — max compatibility
"$APKSIGNER" sign \
    --ks "$SIGNING_KEYSTORE_PATH" \
    --ks-key-alias "$SIGNING_KEY_ALIAS" \
    $KS_PASS_ARG \
    $KEY_PASS_ARG \
    --v1-signing-enabled true \
    --v2-signing-enabled true \
    --v3-signing-enabled false \
    --out "$SIGNED_APK" \
    "$UNSIGNED_APK"

# ── Verify signature ────────────────────────────────────────────────────
echo "🔍 Verifying signature..."
if "$APKSIGNER" verify --verbose "$SIGNED_APK" > /dev/null 2>&1; then
    echo "✅ Signature verified"
else
    echo "⚠️ Signature verification returned warnings (may still be valid)"
fi

SIGNED_SIZE=$(du -sh "$SIGNED_APK" | cut -f1)
echo ""
echo "✅ Signed APK: $SIGNED_APK"
echo "   Size: $SIGNED_SIZE"
echo "   MD5:  $(md5sum "$SIGNED_APK" | cut -d' ' -f1)"
