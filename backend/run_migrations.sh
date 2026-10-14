#!/bin/bash
set -euo pipefail

# =============================================================================
# Database Migration Script
# =============================================================================
# Usage: bash run_migrations.sh
# =============================================================================

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

# Detect if running from inside public/ directory
if [[ "$SCRIPT_DIR" == */public ]]; then
    APP_DIR="$SCRIPT_DIR"
else
    APP_DIR="${SCRIPT_DIR}/khwwc"
fi

cd "$APP_DIR"

echo "============================================"
echo " Database Migration"
echo "============================================"
echo ""

# Safety check
echo "Verifying migration safety..."
if [[ "${1:-}" == "--fresh" ]]; then
    echo "ERROR: --fresh flag is not allowed in production."
    echo "This would destroy all data."
    exit 1
fi

echo "Running migrations from: $APP_DIR"
php artisan migrate --no-interaction --force

echo ""
echo "Migration complete."
echo "============================================"
