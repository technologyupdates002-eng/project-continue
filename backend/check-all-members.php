<?php
require __DIR__ . '/vendor/autoload.php';
$app = require __DIR__ . '/bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

use Illuminate\Support\Facades\Cache;

echo "=== FIND ADMIN MEMBER ===\n";
$admins = DB::table('user_roles')->where('role', 'super_admin')->get(['user_id']);
foreach ($admins as $admin) {
    echo "Admin user_id: " . $admin->user_id . "\n";
    $member = DB::table('members')->where('user_id', $admin->user_id)->first();
    if ($member) {
        echo "  Member ID: " . $member->id . "\n";
        echo "  Member number: " . $member->member_id . "\n";
        echo "  Name: " . $member->name . "\n";
        echo "  Status: " . $member->status . "\n";
        echo "  Total contributions (members table): " . $member->total_contributions . "\n";
        echo "  Total penalties (members table): " . $member->total_penalties . "\n";
    } else {
        echo "  No member record found\n";
    }
}

echo "\n=== CHECK ALL MEMBERS ===\n";
$members = DB::table('members')->get(['id', 'user_id', 'name', 'member_id', 'total_contributions', 'total_penalties', 'status']);
foreach ($members as $m) {
    echo "  Member: " . $m->member_id . " | User: " . $m->user_id . " | Name: " . $m->name . " | TotalContrib: " . $m->total_contributions . " | TotalPen: " . $m->total_penalties . " | Status: " . $m->status . "\n";
}

echo "\n=== GET FRESH STATS FOR EACH MEMBER ===\n";
$service = app(\App\Services\MemberStatsService::class);
foreach ($members as $m) {
    $stats = $service->getStats($m->id);
    echo "  Member " . $m->member_id . " (" . $m->name . "):\n";
    echo "    Total contrib: " . ($stats['total_contributions'] ?? 0) . "\n";
    echo "    Contrib count: " . ($stats['contribution_count'] ?? 0) . "\n";
    echo "    Contrib outstanding: " . ($stats['contribution_outstanding'] ?? 0) . "\n";
    echo "    Next due: " . ($stats['next_due_date'] ?? 'N/A') . "\n";
    echo "    Overdue: " . ($stats['is_overdue'] ? 'YES' : 'NO') . "\n";
    echo "    Days remaining: " . ($stats['days_remaining'] ?? 0) . "\n";
    echo "    Pending months: " . ($stats['pending_months'] ?? 0) . "\n";
    echo "    Overdue months: " . ($stats['overdue_months'] ?? 0) . "\n";
    echo "\n";
}

echo "\n=== CLEANUP ===\n";
unlink(__FILE__);
echo "Done\n";