#!/bin/bash
# Build NativePHP app using .env.native config
# Usage: ./native-build.sh [android|ios]

set -e

ENV_BACKUP=".env.backup.$(date +%s)"

echo "Backing up current .env to $ENV_BACKUP"
cp .env "$ENV_BACKUP"

echo "Switching to .env.native for NativePHP build"
cp .env.native .env
touch database/database.sqlite

echo "Building NativePHP app..."
php artisan native:run "${1:-android}"

echo "Restoring original .env"
cp "$ENV_BACKUP" .env
rm "$ENV_BACKUP"

echo "Done."
