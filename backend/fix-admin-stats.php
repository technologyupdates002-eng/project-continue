<?php
require __DIR__ . '/vendor/autoload.php';
$app = require __DIR__ . '/bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

use Illuminate\Support\Facades\Cache;

echo "=== FIND ADMIN MEMBER ID ===\n";

// Find admin user via user_roles
$adminRole = DB::table('user_roles')->where('role', 'super_admin')->first();
if ($adminRole) {
    echo "Admin user_id from user_roles: " . $adminRole->user_id . "\n";
    
    $member = DB::table('members')->where('user_id', $adminRole->user_id)->first();
    if ($member) {
        echo "Admin member ID: " . $member->id . "\n";
        echo "Admin member number: " . $member->member_id . "\n";
        echo "Admin name: " . $member->name . "\n";
        echo "Admin total_contributions (members table): " . $member->total_contributions . "\n";
        echo "Admin total_penalties (members table): " . $member->total_penalties . "\n";
    } else {
        echo "No member record for admin user\n";
    }
} else {
    echo "No super_admin role found\n";
    // Check all roles
    $roles = DB::table('user_roles')->get();
    foreach ($roles as $r) {
        echo "  Role: " . $r->role . " | User: " . $r->user_id . "\n";
    }
}

echo "\n=== CHECK ALL MEMBER IDs ===\n";
$members = DB::table('members')->get(['id', 'user_id', 'name', 'member_id']);
foreach ($members as $m) {
    echo "  Member ID: " . $m->id . " | User ID: " . $m->user_id . " | Name: " . $m->name . " | Member#: " . $m->member_id . "\n";
}

echo "\n=== CLEAR CACHE FOR ALL MEMBERS ===\n";
foreach ($members as $m) {
    Cache::store('redis')->forget('member:stats:' . $m->id);
    echo "  Cleared cache for member: " . $m->id . " (" . $m->name . ")\n";
}
Cache::store('redis')->forget('member:stats:all');
Cache::store('redis')->forget('member:stats:summary');
echo "  Cleared summary cache\n";

echo "\n=== DONE ===\n";
unlink(__FILE__);