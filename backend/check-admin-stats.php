<?php
require __DIR__ . '/vendor/autoload.php';
$app = require __DIR__ . '/bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

use Illuminate\Support\Facades\Cache;

echo "=== CHECK WHAT MEMBER IS BEING VIEWED ===\n";

// Check auth_users for Admin User
$user = DB::table('auth_users')->where('email', 'admin@welfare.local')->first();
if (!$user) {
    $user = DB::table('auth_users')->where('name', 'like', '%Admin%')->first();
}
if ($user) {
    echo "Admin user found:\n";
    echo "  ID: " . $user->id . "\n";
    echo "  Email: " . $user->email . "\n";
    echo "  Name: " . ($user->name ?? 'N/A') . "\n";
    echo "  Role: " . ($user->role ?? 'N/A') . "\n";
} else {
    echo "No admin user found\n";
}

echo "\n=== CHECK MEMBERS TABLE FOR ADMIN ===\n";
$members = DB::table('members')->get(['id', 'user_id', 'name', 'member_id', 'status']);
foreach ($members as $m) {
    echo "  Member: " . $m->member_id . " | User: " . $m->user_id . " | Name: " . $m->name . " | Status: " . $m->status . "\n";
}

echo "\n=== CHECK CONTRIBUTIONS FOR EACH MEMBER ===\n";
$members = DB::table('members')->get(['id', 'user_id', 'name', 'member_id']);
foreach ($members as $m) {
    $paid = DB::table('contributions')->where('member_id', $m->id)->where('status', 'paid')->sum('amount');
    $pending = DB::table('contributions')->where('member_id', $m->id)->whereIn('status', ['pending', 'partial'])->sum('amount');
    echo "  Member " . $m->member_id . " (" . $m->name . "): Paid=KES " . $paid . ", Pending=KES " . $pending . "\n";
}

echo "\n=== CHECK MEMBER STATS CACHE FOR ALL ===\n";
$keys = Cache::store('redis')->keys('member:stats:*');
echo "Cached stats keys: " . count($keys) . "\n";
foreach (array_slice($keys, 0, 10) as $key) {
    echo "  - $key\n";
}

echo "\n=== GET STATS FOR ADMIN MEMBER ===\n";
if ($user) {
    $member = DB::table('members')->where('user_id', $user->id)->first();
    if ($member) {
        echo "Admin member ID: " . $member->id . "\n";
        $service = app(\App\Services\MemberStatsService::class);
        $stats = $service->getStats($member->id);
        echo "Stats for admin:\n";
        echo "  Total contributions: " . ($stats['total_contributions'] ?? 0) . "\n";
        echo "  Contribution count: " . ($stats['contribution_count'] ?? 0) . "\n";
        echo "  Contribution outstanding: " . ($stats['contribution_outstanding'] ?? 0) . "\n";
        echo "  Next due date: " . ($stats['next_due_date'] ?? 'N/A') . "\n";
        echo "  Is overdue: " . ($stats['is_overdue'] ? 'YES' : 'NO') . "\n";
        echo "  Days remaining: " . ($stats['days_remaining'] ?? 0) . "\n";
        echo "  Pending months: " . ($stats['pending_months'] ?? 0) . "\n";
        echo "  Overdue months: " . ($stats['overdue_months'] ?? 0) . "\n";
    } else {
        echo "No member record for admin user\n";
    }
}

echo "\n=== CLEANUP ===\n";
unlink(__FILE__);
echo "Done\n";