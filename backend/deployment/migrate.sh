#!/bin/bash
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_ROOT="$(dirname "$SCRIPT_DIR")"
cd "$PROJECT_ROOT"

echo "============================================"
echo " Database Migration"
echo "============================================"
echo ""

# Safety check: never allow migrate:fresh in production
echo "Verifying migration safety..."
if [[ "${1:-}" == "--fresh" ]]; then
    echo "ERROR: --fresh flag is not allowed in production deployment."
    echo "This would destroy all data."
    exit 1
fi

echo "Running migrations..."
php artisan migrate --no-interaction --force

echo ""
echo "Migration complete."
echo "============================================"
