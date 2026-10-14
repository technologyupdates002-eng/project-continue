<?php
require 'vendor/autoload.php';
$app = require 'bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;

// Search for the wallet_fixup receipt
$receipt = 'donation-350c6ca6-c31d-4125-a22c-7d682a24edc5-ws_CO_d80e0b411a484f865507b5af';
echo "=== Search for wallet_fixup receipt: {$receipt} ===\n";
$tables = [
    'donation_payment_records' => ['mpesa_transaction_id','payment_ref','id'],
    'payments' => ['transaction_ref','id'],
    'coop_transfer_requests' => ['internal_reference','message_reference','id'],
    'coop_transfer_responses' => ['transaction_id','message_reference','id'],
];
foreach ($tables as $t => $cols) {
    foreach ($cols as $c) {
        $r = DB::table($t)->where($c, 'like', "%{$receipt}%")->get();
        if ($r->isNotEmpty()) {
            echo "Found in {$t}.{$c}:\n";
            foreach ($r as $row) echo json_encode($row, JSON_PRETTY_PRINT) . "\n";
        }
    }
}

// Search for the original checkout request
$origCheckout = 'ws_CO_2904026c877d72f0409e9e25';
echo "\n=== Search for checkout request: {$origCheckout} ===\n";
foreach ($tables as $t => $cols) {
    foreach ($cols as $c) {
        $r = DB::table($t)->where($c, 'like', "%{$origCheckout}%")->get();
        if ($r->isNotEmpty()) {
            echo "Found in {$t}.{$c}:\n";
            foreach ($r as $row) echo json_encode($row, JSON_PRETTY_PRINT) . "\n";
        }
    }
}

// Check campaign_contributions for this member/campaign
echo "\n=== Campaign contributions for member 350c6ca6-c31d-4125-a22c-7d682a24edc5 ===\n";
$cc = DB::table('campaign_contributions')->where('member_id', '350c6ca6-c31d-4125-a22c-7d682a24edc5')->get();
foreach ($cc as $r) echo json_encode($r, JSON_PRETTY_PRINT) . "\n";
if ($cc->isEmpty()) echo "(none)\n";

// Check all donation_payment_records for this member
echo "\n=== All donation_payment_records for member 350c6ca6-c31d-4125-a22c-7d682a24edc5 ===\n";
$dpr = DB::table('donation_payment_records')->where('member_id', '350c6ca6-c31d-4125-a22c-7d682a24edc5')->get();
foreach ($dpr as $r) echo json_encode($r, JSON_PRETTY_PRINT) . "\n";
if ($dpr->isEmpty()) echo "(none)\n";

// Check donation_wallet for this member
echo "\n=== Donation wallet transactions for member 350c6ca6-c31d-4125-a22c-7d682a24edc5 ===\n";
$dw = DB::table('wallet_transactions')->where('created_by', '350c6ca6-c31d-4125-a22c-7d682a24edc5')->orWhere('reference_id', '350c6ca6-c31d-4125-a22c-7d682a24edc5')->get();
foreach ($dw as $r) echo json_encode($r, JSON_PRETTY_PRINT) . "\n";
if ($dw->isEmpty()) echo "(none)\n";

// Search for both checkout IDs together
echo "\n=== All CoopTransferRequests for member 350c6ca6 ===\n";
$memberTrans = DB::table('coop_transfer_requests')->where('internal_reference', 'like', '%350c6ca6%')->get();
foreach ($memberTrans as $r) echo json_encode($r, JSON_PRETTY_PRINT) . "\n";
if ($memberTrans->isEmpty()) echo "(none)\n";

// Check bank_transactions for this member
echo "\n=== Bank transactions for member 350c6ca6-c31d-4125-a22c-7d682a24edc5 ===\n";
$bt = DB::table('bank_transactions')->where('member_id', '350c6ca6-c31d-4125-a22c-7d682a24edc5')->get(['id','transaction_reference','bank_transaction_reference','amount','narration','transaction_date','reconciliation_status','transaction_state','bank_fingerprint','mpesa_code','created_at']);
foreach ($bt as $r) echo json_encode($r, JSON_PRETTY_PRINT) . "\n";
if ($bt->isEmpty()) echo "(none)\n";
