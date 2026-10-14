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
        'bank_transactions' => ['id','transaction_ref','bank_transaction_reference','bank_fingerprint','mpesa_code'],
        'bank_unmatched_transactions' => ['id','bank_reference'],
        'donation_payment_records' => ['id','mpesa_transaction_id','payment_ref','campaign_id'],
        'donation_wallet' => ['id','member_id','reference'],
        'campaign_contributions' => ['id','member_id','payment_ref','campaign_id'],
        'penalty_payments' => ['id','reference_number'],
        'penalty_payment_records' => ['id','payment_ref','mpesa_transaction_id'],
        'operational_payment_records' => ['id','mpesa_transaction_id','payment_ref'],
        'b2c_transactions' => ['id','transaction_ref','mpesa_receipt'],
        'audit_logs' => ['id','reference'],
        'bank_api_logs' => ['id','reference'],
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
}

echo "========================================\n";
echo "PRODUCTION FINANCIAL SNAPSHOT\n";
echo "========================================\n";
$counts = DB::select("
    SELECT
        (SELECT COUNT(*) FROM payments) AS payments_count,
        (SELECT COUNT(*) FROM wallet_transactions) AS wallet_txns_count,
        (SELECT COUNT(*) FROM contributions WHERE status='paid') AS paid_contributions,
        (SELECT COUNT(*) FROM contributions WHERE status='pending') AS pending_contributions,
        (SELECT COUNT(*) FROM contributions WHERE status='partial') AS partial_contributions,
        (SELECT COUNT(*) FROM contributions WHERE status='failed') AS failed_contributions,
        (SELECT COUNT(*) FROM coop_transfer_requests WHERE transfer_type='STK') AS stk_requests,
        (SELECT COUNT(*) FROM coop_transfer_responses) AS stk_responses,
        (SELECT COUNT(*) FROM bank_transactions) AS bank_txns,
        (SELECT COUNT(*) FROM bank_unmatched_transactions) AS unmatched_bank_txns,
        (SELECT COUNT(*) FROM b2c_transactions) AS b2c_transactions,
        (SELECT COUNT(*) FROM donation_payment_records) AS donation_records,
        (SELECT COUNT(*) FROM campaign_contributions) AS campaign_contributions,
        (SELECT COUNT(*) FROM penalty_payments) AS penalty_payments,
        (SELECT COUNT(*) FROM penalty_payment_records) AS penalty_records,
        (SELECT COUNT(*) FROM operational_payment_records) AS operational_records,
        (SELECT COALESCE(SUM(net_amount),0) FROM wallet_transactions) AS wallet_total,
        (SELECT COALESCE(SUM(amount),0) FROM payments) AS payments_total
");
foreach ($counts[0] as $k => $v) {
    echo $k . ' = ' . $v . "\n";
}

echo "\n========================================\n";
searchAllTables('bf885e30');
echo "\n========================================\n";
searchAllTables('6c759df3');
