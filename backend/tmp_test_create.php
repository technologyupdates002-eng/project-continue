<?php
require '/home/kirinyag/public_html/app1.kirinyagahealthcareworkerswelfare.co.ke/khwwc/vendor/autoload.php';
$app = require '/home/kirinyag/public_html/app1.kirinyagahealthcareworkerswelfare.co.ke/khwwc/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$controller = new App\Http\Controllers\Api\MemberController();
$request = Illuminate\Http\Request::create('/api/functions/v1/create-member', 'POST', [], [], [], [
    'CONTENT_TYPE' => 'application/json',
], json_encode(['name' => 'Test Member Smoke', 'phone_number' => '+254700000099', 'role' => 'member']));

try {
    $response = $controller->createMember($request);
    echo 'Status: ' . $response->getStatusCode() . PHP_EOL;
    echo $response->getContent();
} catch (Exception $e) {
    echo 'Error: ' . $e->getMessage() . PHP_EOL;
}
