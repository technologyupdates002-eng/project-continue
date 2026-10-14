#!/bin/bash
set -euo pipefail

cd "/home/laban/Documents/kirinyaga App/khwwc"

echo "Running tests sequentially..."

FAILED=0
PASSED=0

for f in tests/Feature/*.php tests/Feature/**/*.php tests/Unit/*.php tests/Unit/**/*.php; do
  [ -f "$f" ] || continue
  echo "--- Running $f ---"
  if php artisan test "$f" --no-interaction 2>&1 | tail -5; then
    PASSED=$((PASSED + 1))
  else
    FAILED=$((FAILED + 1))
  fi
done

echo ""
echo "Results: $PASSED passed, $FAILED failed"
