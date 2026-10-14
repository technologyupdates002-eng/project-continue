#!/bin/bash
set -euo pipefail

# =============================================================================
# Set Co-op Bank Whitelisted IP
# Run from: cd /home/kirinyag/.../khwwc && bash set-bank-ip.sh
# =============================================================================

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
cd "$SCRIPT_DIR"

if [ ! -f .env ]; then
    echo "ERROR: .env not found in $SCRIPT_DIR"
    exit 1
fi

echo "Setting Co-op Bank whitelisted IP..."

# Set the whitelisted IP from cPanel/server
sed -i "s/^COOP_WEBHOOK_IPS=.*/COOP_WEBHOOK_IPS=169.58.129.32/" .env

php artisan config:clear --no-interaction

echo "Done."
echo ""
echo "Whitelisted IP: 169.58.129.32"
echo ""
echo "Make sure this IP is registered with Co-op Bank for:"
echo "  - Outbound API calls"
echo "  - Callback/webhook URLs"
echo ""
