<?php
require 'vendor/autoload.php';
$app = require 'bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;

function searchAllTables(string $ref): void {
    echo "=== Searching for: {$ref} ===\n";
    $tables = [
        'payments' => ['id','transaction_ref'],
        'wallet_transactions' => ['id','mpesa_receipt','reference_id'],
        'contributions' => ['id','stk_checkout_request_id','payment_ref','payment_id'],
        'coop_transfer_requests' => ['id','message_reference','internal_reference'],
        'coop_transfer_responses' => ['id','transfer_request_id','message_reference','transaction_id'],
        'bank_transactions' => ['id','transaction_reference','bank_transaction_reference','bank_fingerprint','mpesa_code'],
        'bank_unmatched_transactions' => ['id','bank_reference'],
        'donation_payment_records' => ['id','mpesa_transaction_id','payment_ref','campaign_id'],
        'campaign_contributions' => ['id','member_id','payment_ref'],
        'penalty_payments' => ['id','reference_number'],
        'penalty_payment_records' => ['id','payment_ref','mpesa_transaction_id'],
        'operational_payment_records' => ['id','mpesa_transaction_id','payment_ref'],
        'b2c_transactions' => ['id','transaction_ref','mpesa_receipt'],
        'audit_logs' => ['id','reference'],
    ];
    foreach ($tables as $table => $cols) {
        foreach ($cols as $col) {
            $rows = DB::table($table)->where($col, 'like', '%' . $ref . '%')->get();
            if ($rows->isNotEmpty()) {
                echo "Found in {$table}.{$col}:\n";
                foreach ($rows as $r) {
                    echo json_encode($r, JSON_PRETTY_PRINT) . "\n";
                }
            }
        }
    }
    echo "Search complete for {$ref}\n\n";
}

searchAllTables('bf885e30');
searchAllTables('6c759df3');

echo "=== Wallet Transactions ===\n";
$wallets = DB::table('wallet_transactions')->get();
foreach ($wallets as $w) {
    echo json_encode($w, JSON_PRETTY_PRINT) . "\n";
}

echo "\n=== All STK transfer requests ===\n";
$stk = DB::table('coop_transfer_requests')->where('transfer_type','STK')->orderBy('created_at','desc')->get(['id','message_reference','status','amount','internal_reference','created_at','updated_at']);
foreach ($stk as $r) {
    echo json_encode($r, JSON_PRETTY_PRINT) . "\n";
}

echo "\n=== Transfer responses ===\n";
$resp = DB::table('coop_transfer_responses')->orderBy('received_at','desc')->get();
foreach ($resp as $r) {
    echo json_encode($r, JSON_PRETTY_PRINT) . "\n";
}
