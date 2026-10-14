<?php
require __DIR__ . '/vendor/autoload.php';
$app = require __DIR__ . '/bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

$messageReference = 'STK2609062252322803';
$mpesaRef = 'UI7LA5CRUY';
$checkoutId = 'ws_CO_2a58157adcdb7bccb936d1fe';

echo "=== CHECK CONTRIBUTION DETAILS ===\n";
$contribution = DB::table('contributions')->where('stk_checkout_request_id', $checkoutId)->first();
if ($contribution) {
    echo "Found contribution:\n";
    echo "  ID: " . $contribution->id . "\n";
    echo "  Member: " . $contribution->member_id . "\n";
    echo "  Status: " . $contribution->status . "\n";
    echo "  Amount: " . $contribution->amount . "\n";
    echo "  Checkout ID: " . $contribution->stk_checkout_request_id . "\n";
    echo "  Payment ref: " . ($contribution->payment_ref ?? 'None') . "\n";
    echo "  Paid date: " . ($contribution->paid_date ?? 'None') . "\n";
    echo "  Month: " . $contribution->month . "\n";
    echo "  Year: " . $contribution->year . "\n";
    echo "  Created: " . $contribution->created_at . "\n";
    echo "  Updated: " . $contribution->updated_at . "\n";
} else {
    echo "No contribution found with checkout ID: $checkoutId\n";
}

echo "\n=== CHECK PAYMENTS TABLE ===\n";
$payment = DB::table('payments')->where('mpesa_transaction_id', 'like', '%' . $messageReference . '%')->orWhere('mpesa_transaction_id', 'like', '%' . $mpesaRef . '%')->first();
if ($payment) {
    echo "Found payment:\n";
    echo "  ID: " . $payment->id . "\n";
    echo "  Member: " . $payment->member_id . "\n";
    echo "  Status: " . $payment->status . "\n";
    echo "  Amount: " . $payment->amount . "\n";
    echo "  Type: " . ($payment->payment_type ?? 'N/A') . "\n";
} else {
    echo "No payment found in payments table\n";
}

echo "\n=== CHECK MEMBER STATS ===\n";
$memberId = '11111111-1111-1111-1111-111111111111';
$stats = DB::table('member_stats')->where('member_id', $memberId)->first();
if ($stats) {
    echo "Member stats found:\n";
    echo "  Total contributions: " . ($stats->total_contributions ?? 0) . "\n";
    echo "  Pending months: " . ($stats->pending_months ?? 0) . "\n";
    echo "  Overdue months: " . ($stats->overdue_months ?? 0) . "\n";
    echo "  Contribution outstanding: " . ($stats->contribution_outstanding ?? 0) . "\n";
} else {
    echo "No member stats found\n";
}

echo "\n=== CHECK WALLET TRANSACTIONS ===\n";
$wallet = DB::table('wallet_transactions')->where('mpesa_receipt', $mpesaRef)->orWhere('mpesa_receipt', $messageReference)->first();
if ($wallet) {
    echo "Found wallet transaction:\n";
    echo "  ID: " . $wallet->id . "\n";
    echo "  Amount: " . $wallet->amount . "\n";
    echo "  Type: " . $wallet->type . "\n";
    echo "  Direction: " . $wallet->direction . "\n";
} else {
    echo "No wallet transaction found\n";
}

echo "\n=== CHECK TRANSFER REQUEST ERROR ===\n";
$transfer = DB::table('coop_transfer_requests')->where('message_reference', $messageReference)->first();
if ($transfer) {
    echo "Transfer status: " . $transfer->status . "\n";
    echo "Transfer error_message: " . ($transfer->error_message ?? 'None') . "\n";
    echo "Transfer updated_at: " . $transfer->updated_at . "\n";
}