#!/bin/bash
set -euo pipefail

# =============================================================================
# KHWWC Quick Deploy - Run from INSIDE khwwc directory
# Usage: cd /home/kirinyag/.../khwwc && bash quick-deploy.sh
# =============================================================================

RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m'

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
cd "$SCRIPT_DIR"

info() { echo -e "${BLUE}[INFO]${NC} $1"; }
success() { echo -e "${GREEN}[OK]${NC} $1"; }
warn() { echo -e "${YELLOW}[WARN]${NC} $1"; }
error() { echo -e "${RED}[ERROR]${NC} $1"; }

echo "============================================"
echo " KHWWC Quick Deploy"
echo "============================================"
echo "Directory: $SCRIPT_DIR"
echo ""

# Step 1: Check PHP
info "Checking PHP..."
php -v | head -1

# Step 2: Create .env if missing
if [ ! -f .env ]; then
    info "Creating .env from .env.example..."
    cp .env.example .env
    
    echo ""
    warn "CONFIGURE DATABASE CREDENTIALS NOW!"
    echo ""
    read -p "DB_DATABASE: " DB_DATABASE
    read -p "DB_USERNAME: " DB_USERNAME
    read -p "DB_PASSWORD: " DB_PASSWORD
    read -p "COOP_CONSUMER_KEY: " COOP_KEY
    read -p "COOP_CONSUMER_SECRET: " COOP_SECRET
    read -p "COOP_ACCOUNT_NUMBER: " COOP_ACCOUNT
    
    sed -i "s/^DB_DATABASE=.*/DB_DATABASE=${DB_DATABASE}/" .env
    sed -i "s/^DB_USERNAME=.*/DB_USERNAME=${DB_USERNAME}/" .env
    sed -i "s/^DB_PASSWORD=.*/DB_PASSWORD=${DB_PASSWORD}/" .env
    sed -i "s/^COOP_CONSUMER_KEY=.*/COOP_CONSUMER_KEY=${COOP_KEY}/" .env
    sed -i "s/^COOP_CONSUMER_SECRET=.*/COOP_CONSUMER_SECRET=${COOP_SECRET}/" .env
    sed -i "s/^COOP_ACCOUNT_NUMBER=.*/COOP_ACCOUNT_NUMBER=${COOP_ACCOUNT}/" .env
    
    success ".env configured"
else
    info ".env already exists"
fi

# Step 3: Generate JWT
info "Generating JWT secret..."
JWT_SECRET=$(php -r "echo bin2hex(random_bytes(32));")
sed -i "s/^JWT_SECRET=.*/JWT_SECRET=${JWT_SECRET}/" .env
success "JWT_SECRET set"

# Step 4: Generate APP_KEY
info "Generating APP_KEY..."
php artisan key:generate --no-interaction --force
success "APP_KEY generated"

# Step 5: Permissions
info "Setting permissions..."
chmod -R 755 storage bootstrap/cache
chmod -R 775 storage
mkdir -p storage/app/public storage/app/backups storage/framework/{cache,sessions,views} storage/logs
success "Permissions set"

# Step 6: Migrations
info "Running migrations..."
php artisan migrate --no-interaction --force
success "Migrations complete"

# Step 7: Seed admin
info "Seeding admin users..."
php artisan db:seed --class=ProductionAdminSeeder --no-interaction --force || warn "Seeder failed or already seeded"

# Step 8: Optimize
info "Optimizing..."
php artisan config:cache --no-interaction
php artisan route:cache --no-interaction
success "Optimization complete"

echo ""
echo "============================================"
echo " Deployment Complete"
echo "============================================"
echo ""
echo "Verify:"
echo "  https://app1.kirinyagahealthcareworkerswelfare.co.ke/api/health"
echo ""
echo "Login:"
echo "  Super Admin: superadmin@khwwc.local / Super2026"
echo "  Admin: admin@khwwc.local / Admin2026"
echo ""
echo "CHANGE DEFAULT PASSWORDS!"
echo ""
