#!/bin/bash
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_ROOT="$(dirname "$SCRIPT_DIR")"
cd "$PROJECT_ROOT"

echo "============================================"
echo " KHWWC Production Deployment"
echo "============================================"
echo ""

# 1. Verify PHP
echo "[1/8] Verifying PHP 8.4..."
PHP_VERSION=$(php -r 'echo PHP_VERSION;' 2>/dev/null || echo "none")
if [[ "$PHP_VERSION" != 8.4.* ]]; then
    echo "ERROR: PHP 8.4 required. Found: $PHP_VERSION"
    exit 1
fi
echo "PHP version: $PHP_VERSION"

# 2. Verify Composer
echo ""
echo "[2/8] Verifying Composer..."
if ! command -v composer &> /dev/null; then
    echo "ERROR: Composer not found"
    exit 1
fi
composer --version

# 3. Verify .env exists
echo ""
echo "[3/8] Verifying .env configuration..."
if [ ! -f .env ]; then
    echo "ERROR: .env file not found. Copy .env.example to .env and configure it."
    exit 1
fi
echo ".env exists"

# 4. Verify database connectivity
echo ""
echo "[4/8] Verifying database connectivity..."
php artisan about --no-interaction 2>/dev/null || {
    echo "ERROR: Cannot connect to database. Check .env DB_* settings."
    exit 1
}
echo "Database connection OK"

# 5. Run pre-deployment backup
echo ""
echo "[5/8] Creating pre-deployment database backup..."
bash "$SCRIPT_DIR/backup-db.sh" pre-deployment
echo "Backup complete"

# 6. Run migrations
echo ""
echo "[6/8] Running database migrations..."
bash "$SCRIPT_DIR/migrate.sh"
echo "Migrations complete"

# 7. Clear caches
echo ""
echo "[7/8] Clearing application caches..."
php artisan config:clear --no-interaction
php artisan route:clear --no-interaction
php artisan view:clear --no-interaction
php artisan cache:clear --no-interaction
echo "Caches cleared"

# 8. Optimize for production
echo ""
echo "[8/8] Optimizing for production..."
php artisan config:cache --no-interaction
php artisan route:cache --no-interaction
php artisan view:cache --no-interaction
echo "Optimization complete"

echo ""
echo "============================================"
echo " Deployment Complete"
echo "============================================"
echo ""
echo "Next steps:"
echo "  1. Verify health endpoint: /api/health"
echo "  2. Test admin login"
echo "  3. Test member login"
echo "  4. Check logs: storage/logs/laravel.log"
echo ""
