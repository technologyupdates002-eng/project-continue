#!/bin/bash
set -euo pipefail

PROJECT_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$PROJECT_ROOT"

echo "============================================"
echo " Health Check"
echo "============================================"
echo ""

APP_URL=${APP_URL:-http://localhost}
HEALTH_URL="$APP_URL/api/health"

echo "Checking: $HEALTH_URL"
echo ""

RESPONSE=$(curl -s -o /dev/null -w "%{http_code}" "$HEALTH_URL" 2>/dev/null || echo "000")

if [ "$RESPONSE" = "200" ]; then
    echo "✓ Health endpoint returned 200 OK"
    echo ""
    echo "Response body:"
    curl -s "$HEALTH_URL" 2>/dev/null | head -20 || echo "(empty response)"
    echo ""
    echo "============================================"
    echo " Health Check: PASSED"
    echo "============================================"
    exit 0
else
    echo "✗ Health endpoint returned HTTP $RESPONSE"
    echo ""
    echo "Response body:"
    curl -s "$HEALTH_URL" 2>/dev/null || echo "(no response)"
    echo ""
    echo "============================================"
    echo " Health Check: FAILED"
    echo "============================================"
    exit 1
fi
