<?php
require __DIR__ . '/vendor/autoload.php';
$app = require __DIR__ . '/bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

$messageReference = 'STK2609062252322803';
$mpesaRef = 'UI7LA5CRUY';

echo "=== CHECK PAYMENT_RECORDS TABLE ===\n";
$payment = DB::table('payment_records')->where('mpesa_transaction_id', 'like', '%' . $messageReference . '%')->orWhere('mpesa_transaction_id', 'like', '%' . $mpesaRef . '%')->first();
if ($payment) {
    echo "Found payment record:\n";
    echo "  ID: " . $payment->id . "\n";
    echo "  Member: " . $payment->member_id . "\n";
    echo "  Status: " . $payment->status . "\n";
    echo "  Amount: " . $payment->amount . "\n";
    echo "  Type: " . ($payment->payment_type ?? 'N/A') . "\n";
} else {
    echo "No payment record found\n";
}

echo "\n=== CHECK BANK_API_LOGS ===\n";
$log = DB::table('bank_api_logs')->where('message_reference', $messageReference)->orderByDesc('created_at')->first();
if ($log) {
    echo "Found bank log:\n";
    echo "  Status: " . $log->status . "\n";
    echo "  HTTP Code: " . $log->http_status_code . "\n";
    echo "  Created: " . $log->created_at . "\n";
    echo "  Response: " . substr($log->response_payload ?? '', 0, 300) . "\n";
} else {
    echo "No bank log found\n";
}

echo "\n=== CHECK LARAVEL LOG FOR THIS TRANSACTION ===\n";
$logContent = file_get_contents(storage_path('logs/laravel.log'));
$matches = [];
preg_match_all('/STK2609062252322803|UI7LA5CRUY/i', $logContent, $matches);
if (!empty($matches[0])) {
    echo "Found " . count($matches[0]) . " log entries for this transaction\n";
    foreach (array_slice($matches[0], -10) as $match) {
        echo "  - $match\n";
    }
} else {
    echo "No logs found for this transaction\n";
}

echo "\n=== CHECK IF TRANSFER STATUS WAS UPDATED ===\n";
$transfer = DB::table('coop_transfer_requests')->where('message_reference', $messageReference)->first();
if ($transfer) {
    echo "Transfer status: " . $transfer->status . "\n";
    echo "Transfer error: " . ($transfer->error_message ?? 'None') . "\n";
}