#!/bin/bash
set -euo pipefail

# =============================================================================
# KHWWC Production Deployment - Pre-configured for kirinyag server
# Run from: cd /home/kirinyag/public_html/.../khwwc && bash deploy-prod.sh
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
echo " KHWWC Production Deployment"
echo "============================================"
echo "Directory: $SCRIPT_DIR"
echo ""

# Step 1: Create .env with production credentials
info "Creating .env with production configuration..."

if [ -f .env ]; then
    warn ".env already exists. Backing up..."
    cp .env .env.backup.$(date +%Y%m%d_%H%M%S)
fi

{
echo 'APP_NAME="KHWWC Welfare System"'
echo 'APP_ENV=production'
echo 'APP_KEY='
echo 'APP_DEBUG=false'
echo 'APP_URL=https://app1.kirinyagahealthcareworkerswelfare.co.ke'
echo ''
echo 'APP_LOCALE=en'
echo 'APP_FALLBACK_LOCALE=en'
echo 'APP_FAKER_LOCALE=en_US'
echo 'APP_MAINTENANCE_DRIVER=file'
echo ''
echo 'BCRYPT_ROUNDS=12'
echo ''
echo 'LOG_CHANNEL=stack'
echo 'LOG_STACK=single'
echo 'LOG_DEPRECATIONS_CHANNEL=null'
echo 'LOG_LEVEL=error'
echo ''
echo 'DB_CONNECTION=mysql'
echo 'DB_HOST=127.0.0.1'
echo 'DB_PORT=3306'
echo 'DB_DATABASE=kirinyag_health'
echo 'DB_USERNAME=kirinyag_Deno'
echo 'DB_PASSWORD=Neibas2022'
echo 'DB_SOCKET='
echo ''
echo 'SESSION_DRIVER=database'
echo 'SESSION_LIFETIME=120'
echo 'SESSION_ENCRYPT=false'
echo 'SESSION_PATH=/'
echo 'SESSION_DOMAIN=.kirinyagahealthcareworkerswelfare.co.ke'
echo ''
echo 'BROADCAST_CONNECTION=log'
echo 'FILESYSTEM_DISK=local'
echo 'QUEUE_CONNECTION=sync'
echo 'CACHE_STORE=file'
echo ''
echo 'REDIS_CLIENT=phpredis'
echo 'REDIS_HOST=127.0.0.1'
echo 'REDIS_PASSWORD=null'
echo 'REDIS_PORT=6379'
echo ''
echo 'JWT_SECRET='
echo 'JWT_ISSUER=khcww-welfare'
echo 'JWT_TTL=3600'
echo 'REFRESH_TTL=2592000'
echo ''
echo 'MAIL_MAILER=log'
echo 'MAIL_SCHEME=null'
echo 'MAIL_HOST=127.0.0.1'
echo 'MAIL_PORT=2525'
echo 'MAIL_USERNAME=null'
echo 'MAIL_PASSWORD=null'
echo 'MAIL_FROM_ADDRESS="no-reply@kirinyagahealthcareworkerswelfare.co.ke"'
echo 'MAIL_FROM_NAME="KHCWWC Welfare"'
echo ''
echo 'COOP_ENVIRONMENT=production'
echo 'COOP_ENABLE_REAL_CALLS=true'
echo 'COOP_CONSUMER_KEY='
echo 'COOP_CONSUMER_SECRET='
echo 'COOP_TOKEN_URL=https://openapi.co-opbank.co.ke/token'
echo 'COOP_PRODUCTION_BASE_URL=https://api.co-opbank.co.ke'
echo 'COOP_SANDBOX_BASE_URL=https://openapi.co-opbank.co.ke'
echo 'COOP_ACCOUNT_NUMBER='
echo 'COOP_ACCOUNT_NAME="KIRINYAGA HEALTH CARE"'
echo 'COOP_USER_ID=KirinyagaHealthCare'
echo 'COOP_OPERATOR_CODE=KIRINYAGA'
echo 'COOP_CALLBACK_BASE_URL=https://app1.kirinyagahealthcareworkerswelfare.co.ke/api/banking/coop/callback'
echo 'COOP_TOKEN_REFRESH_BUFFER=60'
echo 'COOP_API_TIMEOUT=30'
echo 'COOP_CONNECT_TIMEOUT=10'
echo 'COOP_CALLBACK_VERIFY_SIGNATURE=false'
echo 'COOP_CALLBACK_SIGNATURE_SECRET='
echo 'COOP_WEBHOOK_IPS='
echo ''
echo 'SMS_GATEWAY=log'
echo 'SMS_FROM=KHCWWC'
echo 'SMS_API_KEY='
echo 'SMS_API_URL='
echo ''
echo 'VITE_APP_NAME="KHWWC Welfare System"'
} > .env

success ".env created with production credentials"

# Step 2: Generate JWT
info "Generating JWT secret..."
JWT_SECRET=$(php -r "echo bin2hex(random_bytes(32));")
sed -i "s/^JWT_SECRET=.*/JWT_SECRET=${JWT_SECRET}/" .env
success "JWT_SECRET set"

# Step 3: Generate APP_KEY
info "Generating APP_KEY..."
php artisan key:generate --no-interaction --force
success "APP_KEY generated"

# Step 4: Permissions
info "Setting permissions..."
chmod -R 755 storage bootstrap/cache
chmod -R 775 storage
mkdir -p storage/app/public storage/app/backups storage/framework/{cache,sessions,views} storage/logs
success "Permissions set"

# Step 5: Migrations
info "Running migrations..."
php artisan migrate --no-interaction --force
success "Migrations complete"

# Step 6: Seed admin
info "Seeding admin users..."
php artisan db:seed --class=ProductionAdminSeeder --no-interaction --force || warn "Seeder failed or already seeded"

# Step 7: Optimize
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
