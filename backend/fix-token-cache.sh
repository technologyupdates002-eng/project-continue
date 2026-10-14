#!/bin/bash
set -euo pipefail

cd "$(dirname "$0")/khwwc" 2>/dev/null || cd "$(dirname "$0")"

echo "=== Clearing Co-op Bank token cache ==="

# Clear DB cached tokens
php artisan tinker --execute="\App\Models\CoopToken::query()->delete();" 2>/dev/null || \
php artisan tinker --execute="\DB::table('coop_tokens')->delete();" 2>/dev/null || \
echo "Could not clear DB tokens"

# Clear config cache
php artisan config:clear --no-interaction 2>/dev/null || true

echo ""
echo "=== Requesting fresh token ==="

php artisan tinker --execute="
    \$client = app(\App\Services\CoopBank\CoopBankClient::class);
    \$token = \$client->getAccessToken();
    echo 'Token acquired: ' . substr(\$token, 0, 20) . '...' . PHP_EOL;
    echo 'Length: ' . strlen(\$token) . PHP_EOL;
" 2>/dev/null

echo ""
echo "=== Testing with fresh token ==="

TOKEN=$(php artisan tinker --execute="echo app(\App\Services\CoopBank\CoopBankClient::class)->getAccessToken();" 2>/dev/null)

echo "Token length: ${#TOKEN}"

MSG_REF="ENQDIAG$(date +%s)"

curl -s -X POST https://openapi.co-opbank.co.ke/Enquiry/AccountBalance_v2/2.0.0/ \
  -H "Authorization: Bearer $TOKEN" \
  -H "Content-Type: application/json" \
  -d '{
    "MessageReference": "'"$MSG_REF"'",
    "UserId": "KirinyagaHealthCare",
    "AccountNumber": "01134568843700"
  }' | head -c 1000

echo ""
echo ""
echo "Done."
