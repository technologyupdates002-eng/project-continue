#!/bin/bash
set -euo pipefail

# =============================================================================
# Set Co-op Bank Credentials
# Run from: cd /home/kirinyag/.../khwwc && bash set-bank-env.sh
# =============================================================================

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
cd "$SCRIPT_DIR"

if [ ! -f .env ]; then
    echo "ERROR: .env not found in $SCRIPT_DIR"
    exit 1
fi

echo "Setting Co-op Bank credentials..."

sed -i "s/^COOP_CONSUMER_KEY=.*/COOP_CONSUMER_KEY=3YnotVI68KuOUoCbyqP_j2GuMsYa/" .env
sed -i "s/^COOP_CONSUMER_SECRET=.*/COOP_CONSUMER_SECRET=Sq_9v9CGuCHkWQa_X9LNAvShZBIa/" .env
sed -i "s/^COOP_ACCOUNT_NUMBER=.*/COOP_ACCOUNT_NUMBER=01134568843700/" .env

php artisan config:clear --no-interaction

echo "Done."
echo "Verify: https://app1.kirinyagahealthcareworkerswelfare.co.ke/admin/bank-diagnostics"
