#!/bin/bash
set -euo pipefail

# =============================================================================
# KHWWC cPanel Deployment Script
# =============================================================================
# Usage:
#   1. Upload KHWWC_PRODUCTION_DEPLOYMENT_FINAL_20260831.zip to public_html or home
#   2. Run: bash deploy.sh
# =============================================================================

RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m'

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ZIP_FILE="${SCRIPT_DIR}/KHWWC_PRODUCTION_DEPLOYMENT_FINAL_20260831.zip"
EXTRACT_DIR="${SCRIPT_DIR}/khwwc"

# Detect if running from inside public/ directory
if [[ "$SCRIPT_DIR" == */public ]]; then
    APP_DIR="$SCRIPT_DIR"
    info "Detected: running from public/ directory"
    info "App root: $APP_DIR"
else
    APP_DIR="${SCRIPT_DIR}/khwwc"
fi

# =============================================================================
# Helper Functions
# =============================================================================

info() {
    echo -e "${BLUE}[INFO]${NC} $1"
}

success() {
    echo -e "${GREEN}[OK]${NC} $1"
}

warn() {
    echo -e "${YELLOW}[WARN]${NC} $1"
}

error() {
    echo -e "${RED}[ERROR]${NC} $1"
}

check_command() {
    if ! command -v "$1" &> /dev/null; then
        error "$1 is not installed or not in PATH"
        return 1
    fi
    return 0
}

# =============================================================================
# Step 1: Verify Prerequisites
# =============================================================================

echo "============================================"
echo " KHWWC Production Deployment"
echo "============================================"
echo ""

info "Step 1: Verifying prerequisites..."

check_command php || exit 1
check_command unzip || exit 1
check_command mysql || warn "mysql client not found - backup script may fail"

PHP_VERSION=$(php -r 'echo PHP_VERSION;' 2>/dev/null || echo "unknown")
info "PHP version: $PHP_VERSION"

if [[ "$PHP_VERSION" != 8.4.* ]]; then
    warn "PHP 8.4 is recommended. Found: $PHP_VERSION"
fi

# =============================================================================
# Step 2: Extract ZIP
# =============================================================================

echo ""
info "Step 2: Extracting deployment package..."

if [ ! -f "$ZIP_FILE" ]; then
    error "ZIP file not found: $ZIP_FILE"
    error "Please upload KHWWC_PRODUCTION_DEPLOYMENT_FINAL_20260831.zip first"
    exit 1
fi

if [ -d "$APP_DIR" ]; then
    warn "Directory $APP_DIR already exists. Backing up..."
    mv "$APP_DIR" "${APP_DIR}_backup_$(date +%Y%m%d_%H%M%S)"
fi

mkdir -p "$APP_DIR"
unzip -q "$ZIP_FILE" -d "$SCRIPT_DIR"

if [ ! -d "$APP_DIR" ]; then
    error "Extraction failed"
    exit 1
fi

success "Extracted to $APP_DIR"

# =============================================================================
# Step 3: Set Document Root
# =============================================================================

echo ""
info "Step 3: Document root configuration..."
info "Your document root is already set to: $APP_DIR"
info "If not, set cPanel document root to: $APP_DIR"

# =============================================================================
# Step 4: Setup Environment
# =============================================================================

echo ""
info "Step 4: Setting up environment..."

cd "$APP_DIR"

if [ ! -f .env ]; then
    if [ -f .env.example ]; then
        cp .env.example .env
        success "Created .env from .env.example"
    else
        error ".env.example not found"
        exit 1
    fi
else
    warn ".env already exists. Backing up..."
    cp .env .env.backup.$(date +%Y%m%d_%H%M%S)
fi

# =============================================================================
# Step 5: Generate JWT Secret
# =============================================================================

echo ""
info "Step 5: Generating JWT secret..."

JWT_SECRET=$(php -r "echo bin2hex(random_bytes(32));")

if grep -q "^JWT_SECRET=" .env; then
    CURRENT_JWT=$(grep "^JWT_SECRET=" .env | cut -d'=' -f2)
    if [ -z "$CURRENT_JWT" ] || [ "$CURRENT_JWT" = "" ]; then
        sed -i "s/^JWT_SECRET=.*/JWT_SECRET=${JWT_SECRET}/" .env
        success "Generated and set JWT_SECRET"
    else
        info "JWT_SECRET already set. Keeping existing value."
    fi
else
    echo "JWT_SECRET=${JWT_SECRET}" >> .env
    success "Added JWT_SECRET to .env"
fi

# =============================================================================
# Step 6: Generate APP_KEY
# =============================================================================

echo ""
info "Step 6: Generating Laravel APP_KEY..."

APP_KEY=$(php artisan key:generate --no-interaction --force 2>&1 | grep -o "base64:[A-Za-z0-9+/=]*" | head -1)

if [ -n "$APP_KEY" ]; then
    success "APP_KEY generated: ${APP_KEY}"
else
    warn "Could not generate APP_KEY automatically. Run: php artisan key:generate"
fi

# =============================================================================
# Step 7: Fix Permissions
# =============================================================================

echo ""
info "Step 7: Fixing permissions..."

chmod -R 755 storage bootstrap/cache
chmod -R 775 storage

# Create storage directories if missing
mkdir -p storage/app/public storage/app/backups storage/framework/{cache,sessions,views} storage/logs

success "Permissions set"

# =============================================================================
# Step 8: Install Composer Dependencies (if vendor missing)
# =============================================================================

echo ""
info "Step 8: Checking vendor directory..."

if [ ! -d "vendor" ]; then
    warn "vendor/ directory missing. Running composer install..."
    if check_command composer; then
        composer install --no-dev --no-interaction --prefer-dist
        success "Composer dependencies installed"
    else
        error "composer not found. Please install dependencies manually."
        exit 1
    fi
else
    success "vendor/ directory exists"
fi

# =============================================================================
# Step 9: Run Migrations
# =============================================================================

echo ""
info "Step 9: Running database migrations..."

bash "$SCRIPT_DIR/migrate.sh"

# =============================================================================
# Step 10: Seed Admin (if needed)
# =============================================================================

echo ""
info "Step 10: Seeding admin users..."

php artisan db:seed --class=ProductionAdminSeeder --no-interaction --force 2>&1 || warn "Admin seeder failed or already seeded"

# =============================================================================
# Step 11: Clear and Cache
# =============================================================================

echo ""
info "Step 11: Optimizing application..."

php artisan config:clear --no-interaction
php artisan route:clear --no-interaction
php artisan view:clear --no-interaction
php artisan cache:clear --no-interaction

php artisan config:cache --no-interaction
php artisan route:cache --no-interaction
php artisan view:cache --no-interaction

success "Application optimized"

# =============================================================================
# Step 12: Health Check
# =============================================================================

echo ""
info "Step 12: Running health check..."

bash "$SCRIPT_DIR/health-check.sh" || warn "Health check failed - verify manually"

# =============================================================================
# Complete
# =============================================================================

echo ""
echo "============================================"
echo " Deployment Complete"
echo "============================================"
echo ""
echo "Next steps:"
echo "  1. Verify domain loads: https://app1.kirinyagahealthcareworkerswelfare.co.ke"
echo "  2. Test health endpoint: /api/health"
echo "  3. Login with:"
echo "     - Super Admin: superadmin@khwwc.local / Super2026"
echo "     - Admin: admin@khwwc.local / Admin2026"
echo "  4. Check logs: tail -f storage/logs/laravel.log"
echo "  5. CHANGE DEFAULT PASSWORDS in production!"
echo ""
echo "Files:"
echo "  - App root: $APP_DIR"
echo "  - Public: $APP_DIR"
echo "  - .env: $APP_DIR/.env"
echo ""
