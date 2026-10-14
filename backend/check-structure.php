<?php
require __DIR__ . '/vendor/autoload.php';
$app = require __DIR__ . '/bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

use Illuminate\Support\Facades\Cache;

echo "=== CHECK AUTH_USERS STRUCTURE ===\n";
$columns = DB::select('DESCRIBE auth_users');
foreach ($columns as $col) {
    echo "  " . $col->Field . " (" . $col->Type . ")\n";
}

echo "\n=== CHECK MEMBERS STRUCTURE ===\n";
$columns = DB::select('DESCRIBE members');
foreach ($columns as $col) {
    echo "  " . $col->Field . " (" . $col->Type . ")\n";
}

echo "\n=== CHECK ADMIN USERS ===\n";
$admins = DB::table('auth_users')->whereIn('role', ['admin', 'super_admin'])->get(['id', 'email', 'role']);
foreach ($admins as $admin) {
    echo "  ID: " . $admin->id . " | Email: " . $admin->email . " | Role: " . $admin->role . "\n";
}

echo "\n=== CHECK MEMBERS ===\n";
$members = DB::table('members')->get(['id', 'user_id', 'member_id', 'status']);
foreach ($members as $m) {
    echo "  Member ID: " . $m->member_id . " | User ID: " . $m->user_id . " | Status: " . $m->status . "\n";
}

echo "\n=== DONE ===\n";
unlink(__FILE__);