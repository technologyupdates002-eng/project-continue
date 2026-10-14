<?php
require __DIR__ . '/vendor/autoload.php';
$app = require __DIR__ . '/bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

$memberId = '11111111-1111-1111-1111-111111111111';

echo "=== GET FRESH STATS FOR ADMIN ===\n";
$stats = app(\App\Services\MemberStatsService::class)->getStats($memberId);
echo "Total contributions: " . ($stats['total_contributions'] ?? 0) . "\n";
echo "Contribution count: " . ($stats['contribution_count'] ?? 0) . "\n";
echo "Contribution outstanding: " . ($stats['contribution_outstanding'] ?? 0) . "\n";
echo "Next due: " . ($stats['next_due_date'] ?? 'N/A') . "\n";
echo "Is overdue: " . ($stats['is_overdue'] ? 'YES' : 'NO') . "\n";
echo "Pending months: " . ($stats['pending_months'] ?? 0) . "\n";
echo "Overdue months: " . ($stats['overdue_months'] ?? 0) . "\n";

echo "\n=== CLEANUP ===\n";
unlink(__FILE__);
echo "Done\n";