#!/bin/bash
# NativePHP Production Build Script
# Creates stabilized, production-ready builds

set -e

# Validate platform argument
if [ -z "$1" ]; then
    echo "Usage: $0 <android|ios> [--debug]"
    exit 1
fi

PLATFORM=$1
MODE=${2:-production}
DATE=$(date +%Y%m%d-%H%M%S)
ENV_BACKUP=".env.backup.$DATE"
BUILD_TYPE="release"

if [ "$MODE" == "--debug" ] || [[ "$MODE" == *debug* ]]; then
    BUILD_TYPE="debug"
    echo "⚠️  DEBUG/DEVELOPMENT BUILD — NOT FOR PRODUCTION DISTRIBUTION"
else
    echo "🔒 PRODUCTION BUILD — READY FOR RELEASE"
fi

# Backup current environment
if [ -f ".env" ]; then
    echo "🔄 Backing up current environment to $ENV_BACKUP"
    cp ".env" "$ENV_BACKUP"
fi

# Switch to production environment
PROD_ENV=".env.native-$BUILD_TYPE"
if [ ! -f "$PROD_ENV" ]; then
    echo "❌ Production environment file $PROD_ENV not found"
    echo "Create one with production-specific settings"
    exit 1
fi

echo "🔧 Switching to $BUILD_TYPE environment..."
cp "$PROD_ENV" ".env"

# Clear cached config NOW, before the build boots Laravel, so nothing built
# in this run can read a stale config.php cached from a previous .env
echo "🧹 Clearing config cache so the build picks up $PROD_ENV values..."
php artisan config:clear --no-interaction 2>/dev/null || true

# Production preview hook
if [ -f ".nativephp/production-preload.php" ]; then
    echo "📦 Running production preload..."
    php .nativephp/production-preload.php
fi

# Strip dev-only composer packages (pint, phpunit, faker, psysh, mockery...)
# before packaging so they don't bloat the shipped app. Restored after build.
echo "📦 Installing production-only composer dependencies..."
composer install --no-dev --optimize-autoloader --no-interaction

# Bake config/routes/views into single cached files so the shipped app
# boots Laravel once at build time instead of re-parsing everything on
# every request (this is what makes tab navigation feel instant).
echo "⚡ Caching config/routes/views for production boot speed..."
php artisan config:cache --no-interaction
php artisan route:cache --no-interaction
php artisan view:cache --no-interaction

# Build NativePHP application
BUILD_LOG="nativephp/build-$PLATFORM-$DATE.log"
echo "🚀 Building NativePHP for $PLATFORM ($BUILD_TYPE mode)..."
echo "    Log: $BUILD_LOG"

case $PLATFORM in
    android)
        # Run stabilization script first
        if [ -f "nativephp/android/gradlew-stabilize" ]; then
            echo "🔧 Running Android stabilization validation..."
            cd nativephp/android && ./gradlew-stabilize && cd ../..
        fi
        
        # Build Android
        if [ "$BUILD_TYPE" == "release" ]; then
            php artisan native:build android --release --no-tty | tee "$BUILD_LOG"
        else
            php artisan native:build android --no-tty | tee "$BUILD_LOG"
        fi
        ;;
    ios)
        if [ "$BUILD_TYPE" == "release" ]; then
            php artisan native:build ios --release --no-tty | tee "$BUILD_LOG"
        else
            php artisan native:build ios --no-tty | tee "$BUILD_LOG"
        fi
        ;;
    *)
        echo "❌ Unsupported platform: $PLATFORM"
        exit 1
        ;;
esac

# Build result validation
if [ $? -eq 0 ]; then
    echo "✅ $PLATFORM build completed successfully"
    
    # Signing coordination
    if [ -f ".nativephp/production-signing.sh" ]; then
        echo "🔐 Running production signing..."
        chmod +x .nativephp/production-signing.sh
        ./.nativephp/production-signing.sh "$PLATFORM"
    fi
    
    # Final integrity check
    if [ -f ".nativephp/production-validate.sh" ]; then
        echo "🔍 Running final validation..."
        chmod +x .nativephp/production-validate.sh
        ./.nativephp/production-validate.sh "$PLATFORM"
    fi
else
    echo "❌ $PLATFORM build FAILED — check $BUILD_LOG for details"
    exit 1
fi

# Ready for distribution
OUTPUT_FILE="nativephp/android/app/build/outputs/apk/$BUILD_TYPE/$(ls -t nativephp/android/app/build/outputs/apk/$BUILD_TYPE/ 2>/dev/null | grep '\.apk$' | head -1)"
if [ -f "$OUTPUT_FILE" ]; then
    echo ""
    echo "🎉 Build ready:"
    echo "    APK: $OUTPUT_FILE"
    echo "    Size: $(du -sh "$OUTPUT_FILE" | cut -f1)"
    echo "    MD5: $( (md5sum "$OUTPUT_FILE" 2>/dev/null || md5 -q "$OUTPUT_FILE" || echo "N/A") | cut -d' ' -f1)"
    echo ""
    echo "📤 This build is production-ready for distribution"
else
    echo "⚠️  APK not found — production build may have failed"
fi

# Cleanup
echo "🔄 Restoring original environment..."
if [ -f "$ENV_BACKUP" ]; then
    cp "$ENV_BACKUP" ".env"
    rm "$ENV_BACKUP"
fi

# Clear stale config/route/view cache
php artisan config:clear --no-interaction 2>/dev/null || true
php artisan route:clear --no-interaction 2>/dev/null || true
php artisan view:clear --no-interaction 2>/dev/null || true

# Restore dev composer dependencies for local development
echo "🔄 Restoring development composer dependencies..."
composer install --no-interaction

echo "✅ Production build process complete"