#!/bin/bash
set -euo pipefail

# =============================================================================
# JWT Secret Generator
# =============================================================================
# Usage: bash generate_jwt.sh
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
echo " JWT Secret Generator"
echo "============================================"
echo ""

if [ ! -f .env ]; then
    echo "ERROR: .env file not found in $APP_DIR"
    exit 1
fi

JWT_SECRET=$(php -r "echo bin2hex(random_bytes(32));")

echo "Generated JWT_SECRET:"
echo ""
echo "  ${JWT_SECRET}"
echo ""

if grep -q "^JWT_SECRET=" .env; then
    CURRENT=$(grep "^JWT_SECRET=" .env | cut -d'=' -f2)
    if [ -z "$CURRENT" ]; then
        sed -i "s/^JWT_SECRET=.*/JWT_SECRET=${JWT_SECRET}/" .env
        echo "Updated .env with new JWT_SECRET"
    else
        echo "JWT_SECRET already set in .env"
        echo "Current: ${CURRENT}"
        echo ""
        read -p "Replace with new value? (y/N): " -n 1 -r
        echo
        if [[ $REPLY =~ ^[Yy]$ ]]; then
            sed -i "s/^JWT_SECRET=.*/JWT_SECRET=${JWT_SECRET}/" .env
            echo "Updated .env with new JWT_SECRET"
        fi
    fi
else
    echo "JWT_SECRET=${JWT_SECRET}" >> .env
    echo "Added JWT_SECRET to .env"
fi

echo ""
echo "Done."
