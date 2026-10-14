<?php
require 'vendor/autoload.php';
$app = require 'bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;

function searchRef(string $ref): void {
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
        'penalty_payments' => ['id','reference_number'],
        'penalty_payment_records' => ['id','payment_ref','mpesa_transaction_id'],
        'operational_payment_records' => ['id','mpesa_transaction_id','payment_ref'],
        'b2c_transactions' => ['id','transaction_ref','mpesa_receipt'],
    ];
    $found = false;
    foreach ($tables as $table => $cols) {
        foreach ($cols as $col) {
            try {
                $rows = DB::table($table)->where($col, 'like', '%' . $ref . '%')->get();
                if ($rows->isNotEmpty()) {
                    $found = true;
                    echo "Found in {$table}.{$col}:\n";
                    foreach ($rows as $r) echo json_encode($r, JSON_PRETTY_PRINT) . "\n";
                }
            } catch (\Throwable $e) {
                // column doesn't exist, skip
            }
        }
    }
    if (!$found) echo "NOT FOUND in any table.\n";
    echo "\n";
}

searchRef('bf885e30');
searchRef('6c759df3');

echo "=== Wallet Transactions (all) ===\n";
$wallets = DB::table('wallet_transactions')->get();
foreach ($wallets as $w) echo json_encode($w, JSON_PRETTY_PRINT) . "\n";
if ($wallets->isEmpty()) echo "(empty)\n";

echo "\n=== Campaign/Donations lookup for bf885e30 campaign_id ===\n";
$record = DB::table('donation_payment_records')->where('id', 'like', '%bf885e30%')->first();
if ($record) {
    echo json_encode($record, JSON_PRETTY_PRINT) . "\n";
    $campaign = DB::table('donation_campaigns')->where('id', $record->campaign_id)->first();
    echo "Campaign: " . ($campaign ? json_encode($campaign, JSON_PRETTY_PRINT) : 'NOT FOUND') . "\n";
    $transfer = DB::table('coop_transfer_requests')->where('internal_reference', 'like', '%' . $record->mpesa_transaction_id . '%')->orWhere('message_reference', $record->mpesa_transaction_id)->first();
    echo "STK Transfer Request: " . ($transfer ? json_encode($transfer, JSON_PRETTY_PRINT) : 'NOT FOUND') . "\n";
    $response = DB::table('coop_transfer_responses')->where('transaction_id', $record->mpesa_transaction_id)->orWhere('message_reference', $record->mpesa_transaction_id)->first();
    echo "STK Transfer Response: " . ($response ? json_encode($response, JSON_PRETTY_PRINT) : 'NOT FOUND') . "\n";
}

echo "\n=== Payments table sample (last 10) ===\n";
$payments = DB::table('payments')->orderBy('created_at', 'desc')->limit(10)->get();
foreach ($payments as $p) echo json_encode($p, JSON_PRETTY_PRINT) . "\n";

echo "\n=== Contributions with payment_ref matching bf885e30 or 6c759df3 ===\n";
$contribs = DB::table('contributions')->whereIn('stk_checkout_request_id', ['bf885e30','6c759df3'])->orWhereIn('payment_ref', ['bf885e30','6c759df3'])->get();
foreach ($contribs as $c) echo json_encode($c, JSON_PRETTY_PRINT) . "\n";
if ($contribs->isEmpty()) echo "(none found)\n";
