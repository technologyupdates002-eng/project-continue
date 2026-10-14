#!/bin/bash
set -euo pipefail

# =============================================================================
# Extract Co-op Bank credentials from Postman collection and update .env
# Usage: bash setup-bank-config.sh /path/to/postman-collection.json
# =============================================================================

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
APP_DIR="$SCRIPT_DIR"

info() { echo -e "\033[0;34m[INFO]\033[0m $1"; }
success() { echo -e "\033[0;32m[OK]\033[0m $1"; }
warn() { echo -e "\033[1;33m[WARN]\033[0m $1"; }
error() { echo -e "\033[0;31m[ERROR]\033[0m $1"; }

POSTMAN_FILE="${1:-}"

if [ -z "$POSTMAN_FILE" ]; then
    echo "Usage: bash setup-bank-config.sh /path/to/postman-collection.json"
    echo ""
    echo "Or enter credentials manually:"
    read -p "COOP_CONSUMER_KEY: " CONSUMER_KEY
    read -p "COOP_CONSUMER_SECRET: " CONSUMER_SECRET
    read -p "COOP_ACCOUNT_NUMBER: " ACCOUNT_NUMBER
else
    if [ ! -f "$POSTMAN_FILE" ]; then
        error "Postman file not found: $POSTMAN_FILE"
        exit 1
    fi

    info "Extracting credentials from Postman collection..."

    # Extract Basic Auth header from "Generate Token" request
    AUTH_HEADER=$(grep -o '"Authorization": "[^"]*"' "$POSTMAN_FILE" | head -1 | sed 's/"Authorization": "//;s/"$//')

    if [ -z "$AUTH_HEADER" ]; then
        error "Could not find Authorization header in Postman collection"
        exit 1
    fi

    # Extract Base64 consumer_key:consumer_secret
    BASE64_CREDS=$(echo "$AUTH_HEADER" | sed 's/Basic //')

    # Decode Base64
    CONSUMER_CREDS=$(echo "$BASE64_CREDS" | base64 -d 2>/dev/null || echo "")

    if [ -z "$CONSUMER_CREDS" ]; then
        error "Failed to decode Base64 credentials"
        exit 1
    fi

    CONSUMER_KEY=$(echo "$CONSUMER_CREDS" | cut -d: -f1)
    CONSUMER_SECRET=$(echo "$CONSUMER_CREDS" | cut -d: -f2-)

    # Extract account number from any request body
    ACCOUNT_NUMBER=$(grep -o '"AccountNumber": "[^"]*"' "$POSTMAN_FILE" | head -1 | sed 's/"AccountNumber": "//;s/"$//')

    if [ -z "$ACCOUNT_NUMBER" ]; then
        warn "Could not find AccountNumber in Postman collection"
        read -p "Enter COOP_ACCOUNT_NUMBER manually: " ACCOUNT_NUMBER
    fi

    success "Extracted credentials:"
    echo "  COOP_CONSUMER_KEY: ${CONSUMER_KEY:0:8}..."
    echo "  COOP_CONSUMER_SECRET: ${CONSUMER_SECRET:0:8}..."
    echo "  COOP_ACCOUNT_NUMBER: $ACCOUNT_NUMBER"
fi

# Update .env
cd "$APP_DIR"

if [ ! -f .env ]; then
    error ".env file not found in $APP_DIR"
    exit 1
fi

info "Updating .env..."

sed -i "s/^COOP_CONSUMER_KEY=.*/COOP_CONSUMER_KEY=${CONSUMER_KEY}/" .env
sed -i "s/^COOP_CONSUMER_SECRET=.*/COOP_CONSUMER_SECRET=${CONSUMER_SECRET}/" .env
sed -i "s/^COOP_ACCOUNT_NUMBER=.*/COOP_ACCOUNT_NUMBER=${ACCOUNT_NUMBER}/" .env

success "Updated .env with bank credentials"

# Clear config cache
info "Clearing config cache..."
php artisan config:clear --no-interaction
success "Config cache cleared"

echo ""
echo "============================================"
echo " Bank Configuration Complete"
echo "============================================"
echo ""
echo "Verify at:"
echo "  https://app1.kirinyagahealthcareworkerswelfare.co.ke/admin/bank-diagnostics"
echo ""
