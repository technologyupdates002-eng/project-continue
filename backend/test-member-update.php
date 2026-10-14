<?php
require __DIR__ . '/vendor/autoload.php';
$app = require __DIR__ . '/bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

echo "=== TEST MEMBER UPDATE VIA REST API ===\n";

// Simulate a PATCH request to update a member
$request = Request::create('/rest/v1/members?id=eq.11111111-1111-1111-1111-111111111111', 'PATCH', [], [], [], [], json_encode(['name' => 'Admin User Updated Test']));
$request->headers->set('Content-Type', 'application/json');

// Get the identity for admin
$identity = [
    'user_id' => '05ade1a2-2aba-425c-b298-3b27f1d2c13b',
    'roles' => ['admin', 'super_admin'],
];

// Manually test the update
try {
    $controller = new App\Http\Controllers\RestApiController();
    $response = $controller->update($request, 'members', DB::table('members'), $identity);
    echo "Update succeeded!\n";
    echo "Response: " . $response->getContent() . "\n";
} catch (Exception $e) {
    echo "Update failed: " . $e->getMessage() . "\n";
}

echo "\n=== DONE ===\n";
unlink(__FILE__);