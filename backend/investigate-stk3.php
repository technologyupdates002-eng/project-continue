<?php
require __DIR__ . '/vendor/autoload.php';
$app = require __DIR__ . '/bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

$messageReference = 'STK2609062252322803';
$mpesaRef = 'UI7LA5CRUY';

echo "=== CHECK ALL TABLES WITH STK/PAYMENT ===\n";
$tables = DB::select('SHOW TABLES');
foreach ($tables as $table) {
    $tableName = array_values((array) $table)[0];
    if (stripos($tableName, 'payment') !== false || stripos($tableName, 'stk') !== false || stripos($tableName, 'transfer') !== false || stripos($tableName, 'contribution') !== false || stripos($tableName, 'penalty') !== false || stripos($tableName, 'donation') !== false || stripos($tableName, 'wallet') !== false || stripos($tableName, 'operational') !== false) {
        echo "  - $tableName\n";
    }
}

echo "\n=== CHECK COOP_TRANSFER_REQUESTS ===\n";
$transfer = DB::table('coop_transfer_requests')->where('message_reference', $messageReference)->first();
if ($transfer) {
    echo "Found transfer:\n";
    echo "  ID: " . $transfer->id . "\n";
    echo "  Status: " . $transfer->status . "\n";
    echo "  Amount: " . $transfer->amount . "\n";
    echo "  Destination: " . $transfer->destination_account . "\n";
    echo "  Internal ref: " . $transfer->internal_reference . "\n";
    echo "  Internal table: " . $transfer->internal_table . "\n";
    echo "  Created: " . $transfer->created_at . "\n";
    echo "  Error: " . ($transfer->error_message ?? 'None') . "\n";
} else {
    echo "NOT FOUND\n";
}

echo "\n=== CHECK CONTRIBUTIONS ===\n";
$contributions = DB::table('contributions')->where('member_id', '11111111-1111-1111-1111-111111111111')->orderByDesc('created_at')->limit(5)->get();
foreach ($contributions as $c) {
    echo "  Contribution: " . $c->id . " | " . $c->status . " | " . $c->amount . " | " . $c->stk_checkout_request_id . "\n";
}

echo "\n=== CHECK BANK_API_LOGS ===\n";
$log = DB::table('bank_api_logs')->where('message_reference', $messageReference)->orderByDesc('created_at')->first();
if ($log) {
    echo "Found bank log:\n";
    echo "  Status: " . $log->status . "\n";
    echo "  HTTP Code: " . $log->http_status_code . "\n";
    echo "  Created: " . $log->created_at . "\n";
    echo "  Response: " . substr($log->response_payload ?? '', 0, 500) . "\n";
} else {
    echo "No bank log found\n";
}

echo "\n=== CHECK LARAVEL LOG FOR THIS TRANSACTION ===\n";
$logContent = @file_get_contents(storage_path('logs/laravel.log'));
if ($logContent) {
    $lines = explode("\n", $logContent);
    $found = 0;
    foreach ($lines as $line) {
        if (stripos($line, $messageReference) !== false || stripos($line, $mpesaRef) !== false) {
            echo "  " . substr($line, 0, 200) . "\n";
            $found++;
            if ($found > 20) break;
        }
    }
    if ($found == 0) echo "No logs found for this transaction\n";
} else {
    echo "Cannot read laravel.log\n";
}