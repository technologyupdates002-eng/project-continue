#!/bin/bash
set -euo pipefail

# =============================================================================
# KHWWC Targeted Deploy - STK Push Fixes (Backend + Frontend)
# =============================================================================
# Upload ALL files in this script's directory to production, then run:
#   cd /home/kirinyag/public_html/app1.kirinyagahealthcareworkerswelfare.co.ke
#   bash deploy-stk-fixes.sh
# =============================================================================

RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m'

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
KHWWC_DIR="${SCRIPT_DIR}/khwwc"
STAGING_DIR="${SCRIPT_DIR}/staging"

info() { echo -e "${BLUE}[INFO]${NC} $1"; }
success() { echo -e "${GREEN}[OK]${NC} $1"; }
warn() { echo -e "${YELLOW}[WARN]${NC} $1"; }
error() { echo -e "${RED}[ERROR]${NC} $1"; }

echo "============================================"
echo " KHWWC STK Push Fix Deployment"
echo "============================================"
echo "Script dir: $SCRIPT_DIR"
echo ""

# Verify khwwc directory exists
if [ ! -d "$KHWWC_DIR" ]; then
    error "khwwc directory not found at: $KHWWC_DIR"
    error "Run this from the public_html root where khwwc/ lives"
    exit 1
fi

# =============================================================================
# STEP 1: Backend file deployment
# =============================================================================
info "Deploying backend fixes..."

# Look for php-fixes.zip in staging/ or script directory
PHP_ZIP=""
if [ -f "${STAGING_DIR}/php-fixes.zip" ]; then
    PHP_ZIP="${STAGING_DIR}/php-fixes.zip"
elif [ -f "${SCRIPT_DIR}/php-fixes.zip" ]; then
    PHP_ZIP="${SCRIPT_DIR}/php-fixes.zip"
fi

if [ -z "$PHP_ZIP" ] || [ ! -f "$PHP_ZIP" ]; then
    error "php-fixes.zip not found in staging/ or script directory"
    error "Upload it alongside this script"
    exit 1
fi

DEST_RECONCILE="${KHWWC_DIR}/app/Console/Commands/CoopReconcileTransfers.php"
DEST_STK_SERVICE="${KHWWC_DIR}/app/Services/CoopBank/CoopBankTransactionService.php"

# Backup existing files
timestamp=$(date +%Y%m%d_%H%M%S)

if [ -f "$DEST_RECONCILE" ]; then
    cp "$DEST_RECONCILE" "${DEST_RECONCILE}.backup.${timestamp}"
    success "Backed up CoopReconcileTransfers.php"
fi

if [ -f "$DEST_STK_SERVICE" ]; then
    cp "$DEST_STK_SERVICE" "${DEST_STK_SERVICE}.backup.${timestamp}"
    success "Backed up CoopBankTransactionService.php"
fi

# Unzip PHP fixes to staging then copy
mkdir -p "${STAGING_DIR}/php"
unzip -q -o "$PHP_ZIP" -d "${STAGING_DIR}/php"

cp "${STAGING_DIR}/php/CoopReconcileTransfers.php" "$DEST_RECONCILE"
success "Deployed CoopReconcileTransfers.php"

cp "${STAGING_DIR}/php/CoopBankTransactionService.php" "$DEST_STK_SERVICE"
success "Deployed CoopBankTransactionService.php"

# =============================================================================
# STEP 2: Frontend deployment
# =============================================================================
info "Deploying frontend build..."

FRONTEND_ZIP=""
if [ -f "${STAGING_DIR}/frontend-build.zip" ]; then
    FRONTEND_ZIP="${STAGING_DIR}/frontend-build.zip"
elif [ -f "${SCRIPT_DIR}/frontend-build.zip" ]; then
    FRONTEND_ZIP="${SCRIPT_DIR}/frontend-build.zip"
fi

if [ -z "$FRONTEND_ZIP" ] || [ ! -f "$FRONTEND_ZIP" ]; then
    error "frontend-build.zip not found in staging/ or script directory"
    error "Upload it alongside this script"
    exit 1
fi

PUBLIC_DIR="${KHWWC_DIR}/public"

# Backup existing build
if [ -d "${PUBLIC_DIR}/build" ]; then
    mv "${PUBLIC_DIR}/build" "${PUBLIC_DIR}/build.backup.${timestamp}"
    success "Backed up existing build/"
fi

# Unzip new build
unzip -q "$FRONTEND_ZIP" -d "$PUBLIC_DIR"
success "Deployed frontend build to public/build/"

# =============================================================================
# STEP 3: Laravel optimization
# =============================================================================
info "Running Laravel optimization..."

cd "$KHWWC_DIR"

php artisan config:clear --no-interaction || true
php artisan route:clear --no-interaction || true
php artisan view:clear --no-interaction || true
php artisan cache:clear --no-interaction || true

php artisan config:cache --no-interaction
php artisan route:cache --no-interaction
success "Laravel optimization complete"

echo ""
echo "============================================"
echo " Deployment Complete"
echo "============================================"
echo ""
echo "Deployed files:"
echo "  Backend:"
echo "    - $DEST_RECONCILE"
echo "    - $DEST_STK_SERVICE"
echo "  Frontend:"
echo "    - ${PUBLIC_DIR}/build/ (from frontend-build.zip)"
echo ""
echo "Verify:"
echo "  https://app1.kirinyagahealthcareworkerswelfare.co.ke/api/health"
echo ""
