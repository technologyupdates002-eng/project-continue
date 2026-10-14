#!/bin/bash
set -euo pipefail

# =============================================================================
# Fix File Permissions for cPanel
# =============================================================================
# Usage: bash fix-permissions.sh
# =============================================================================

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

# Detect if running from inside public/ directory
if [[ "$SCRIPT_DIR" == */public ]]; then
    APP_DIR="$SCRIPT_DIR"
else
    APP_DIR="${SCRIPT_DIR}/khwwc"
fi

echo "============================================"
echo " Fix Permissions"
echo "============================================"
echo ""

if [ ! -d "$APP_DIR" ]; then
    echo "ERROR: App directory not found: $APP_DIR"
    exit 1
fi

cd "$APP_DIR"

echo "Setting Laravel permissions from: $APP_DIR"

# Directories
chmod 755 app bootstrap config database public resources routes routes/Middleware routes/Console routes/Channels routes/api.php routes/web.php routes/console.php storage vendor

# Make storage writable
chmod -R 775 storage
chmod -R 775 bootstrap/cache

# Create required storage directories
mkdir -p storage/app/public
mkdir -p storage/app/backups
mkdir -p storage/framework/{cache,sessions,views}
mkdir -p storage/logs

# Make public disk link if needed
php artisan storage:link --no-interaction 2>/dev/null || true

# Ensure artisan is executable
chmod +x artisan

echo ""
echo "Permissions fixed."
echo ""
echo "Verify:"
echo "  ls -la storage"
echo "  ls -la bootstrap/cache"
