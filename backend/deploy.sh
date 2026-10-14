#!/bin/bash
set -e

echo "=== KHWWC Production Deployment ==="
echo "Timestamp: $(date -u '+%Y-%m-%d %H:%M:%S UTC')"

# 1. Pre-flight checks
echo "[1/6] Pre-flight checks..."
php -v
php artisan --version
php artisan migrate:status

# 2. Run migrations
echo "[2/6] Running migrations..."
php artisan migrate --force --no-interaction

# 3. Clear and rebuild caches
echo "[3/6] Rebuilding caches..."
php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan optimize:clear

# 4. Restart queue workers
echo "[4/6] Restarting queue workers..."
php artisan queue:restart || true

# 5. Verify deployment
echo "[5/6] Verifying deployment..."
php artisan tinker --execute="echo 'PHP: ' . phpversion() . PHP_EOL; echo 'Laravel: ' . app()->version() . PHP_EOL; echo 'Environment: ' . app()->environment() . PHP_EOL;"

# 6. Final checks
echo "[6/6] Final checks..."
ls -la public/dist/ | head -5
ls -la public/assets/ | head -5

echo "=== Deployment Complete ==="
