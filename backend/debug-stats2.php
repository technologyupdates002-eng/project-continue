<?php
require __DIR__ . '/vendor/autoload.php';
$app = require __DIR__ . '/bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

use Illuminate\Support\Facades\Cache;

$memberId = '11111111-1111-1111-1111-111111111111';

echo "=== CLEAR CACHE ===\n";
Cache::store('redis')->forget('member:stats:' . $memberId);
Cache::store('redis')->forget('member:stats:all');
Cache::store('redis')->forget('member:stats:summary');
echo "Cache cleared\n";

echo "\n=== GET FRESH STATS ===\n";
$service = app(\App\Services\MemberStatsService::class);
$stats = $service->getStats($memberId);
echo "Total contributions: " . ($stats['total_contributions'] ?? 0) . "\n";
echo "Contribution count: " . ($stats['contribution_count'] ?? 0) . "\n";
echo "Contribution outstanding: " . ($stats['contribution_outstanding'] ?? 0) . "\n";
echo "Next due: " . ($stats['next_due_date'] ?? 'N/A') . "\n";
echo "Is overdue: " . ($stats['is_overdue'] ? 'YES' : 'NO') . "\n";
echo "Pending months: " . ($stats['pending_months'] ?? 0) . "\n";
echo "Overdue months: " . ($stats['overdue_months'] ?? 0) . "\n";

echo "\n=== DEBUG OBLIGATIONS ===\n";
if (!empty($stats['obligations'])) {
    foreach ($stats['obligations'] as $ob) {
        echo "  " . $ob['year'] . "-" . str_pad($ob['month'], 2, '0', STR_PAD_LEFT) . " | expected: " . $ob['expected'] . " | paid: " . ($ob['is_paid'] ? 'YES' : 'NO') . " | pending: " . ($ob['is_pending'] ? 'YES' : 'NO') . " | overdue: " . ($ob['is_overdue'] ? 'YES' : 'NO') . " | due: " . $ob['due_date'] . " | grace: " . $ob['grace_deadline'] . "\n";
    }
} else {
    echo "  No obligations\n";
}

echo "\n=== CLEANUP ===\n";
unlink(__FILE__);
echo "Done\n";