<?php
require __DIR__ . '/vendor/autoload.php';
$app = require __DIR__ . '/bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

use Illuminate\Support\Facades\Cache;

$memberId = '11111111-1111-1111-1111-111111111111';

echo "=== CLEAR MEMBER STATS CACHE ===\n";
Cache::store('redis')->forget('member:stats:' . $memberId);
Cache::store('redis')->forget('member:stats:all');
Cache::store('redis')->forget('member:stats:summary');
echo "Cache cleared for member: $memberId\n";

echo "\n=== VERIFY CACHE CLEARED ===\n";
$cacheKey = 'member:stats:' . $memberId;
$exists = Cache::store('redis')->has($cacheKey);
echo "Cache key exists: " . ($exists ? 'YES' : 'NO') . "\n";

echo "\n=== GET FRESH STATS ===\n";
$service = app(\App\Services\MemberStatsService::class);
$stats = $service->getStats($memberId);
if (!empty($stats)) {
    echo "Fresh stats:\n";
    echo "  Total contributions: " . ($stats['total_contributions'] ?? 0) . "\n";
    echo "  Contribution count: " . ($stats['contribution_count'] ?? 0) . "\n";
    echo "  Unpaid count: " . ($stats['unpaid_count'] ?? 0) . "\n";
    echo "  Total penalties: " . ($stats['total_penalties'] ?? 0) . "\n";
    echo "  Penalty outstanding: " . ($stats['penalty_outstanding'] ?? 0) . "\n";
    echo "  Contribution outstanding: " . ($stats['contribution_outstanding'] ?? 0) . "\n";
    echo "  Next due date: " . ($stats['next_due_date'] ?? 'N/A') . "\n";
    echo "  Is overdue: " . ($stats['is_overdue'] ? 'YES' : 'NO') . "\n";
    echo "  Days remaining: " . ($stats['days_remaining'] ?? 0) . "\n";
    echo "  Pending months: " . ($stats['pending_months'] ?? 0) . "\n";
    echo "  Overdue months: " . ($stats['overdue_months'] ?? 0) . "\n";
} else {
    echo "No stats returned\n";
}

echo "\n=== CLEANUP ===\n";
unlink(__FILE__);
echo "Script removed\n";