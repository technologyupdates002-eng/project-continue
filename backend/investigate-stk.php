<?php
require __DIR__ . '/vendor/autoload.php';
$app = require __DIR__ . '/bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

$messageReference = 'STK2609062252322803';
$mpesaRef = 'UI7LA5CRUY';

echo "=== INVESTIGATE SPECIFIC FAILED STK ===\n";
echo "MessageReference: $messageReference\n";
echo "M-Pesa Ref: $mpesaRef\n";
echo "Amount: KES 5.00\n\n";

echo "=== CHECK COOP_TRANSFER_REQUESTS ===\n";
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
} else {
    echo "NOT FOUND in coop_transfer_requests\n";
}

echo "\n=== CHECK COOP_TRANSFER_RESPONSES ===\n";
$response = DB::table('coop_transfer_responses')->where('message_reference', $messageReference)->orderByDesc('received_at')->first();
if ($response) {
    echo "Found response:\n";
    echo "  Status: " . $response->status . "\n";
    echo "  Transaction ID: " . $response->transaction_id . "\n";
    echo "  Received: " . $response->received_at . "\n";
    echo "  Payload: " . substr($response->response_payload, 0, 500) . "\n";
} else {
    echo "NOT FOUND in coop_transfer_responses\n";
}

echo "\n=== CHECK CONTRIBUTIONS WITH THIS CHECKOUT ID ===\n";
$contribution = DB::table('contributions')->where('stk_checkout_request_id', 'like', '%' . $messageReference . '%')->orWhere('stk_checkout_request_id', 'like', '%' . $mpesaRef . '%')->first();
if ($contribution) {
    echo "Found contribution:\n";
    echo "  ID: " . $contribution->id . "\n";
    echo "  Member: " . $contribution->member_id . "\n";
    echo "  Status: " . $contribution->status . "\n";
    echo "  Amount: " . $contribution->amount . "\n";
    echo "  Checkout ID: " . $contribution->stk_checkout_request_id . "\n";
} else {
    echo "No contribution found with this checkout ID\n";
}

echo "\n=== CHECK PENALTY PAYMENTS ===\n";
$penalty = DB::table('penalty_payment_records')->where('mpesa_transaction_id', 'like', '%' . $messageReference . '%')->orWhere('mpesa_transaction_id', 'like', '%' . $mpesaRef . '%')->first();
if ($penalty) {
    echo "Found penalty:\n";
    echo "  ID: " . $penalty->id . "\n";
    echo "  Member: " . $penalty->member_id . "\n";
    echo "  Status: " . $penalty->status . "\n";
    echo "  Amount: " . $penalty->amount . "\n";
} else {
    echo "No penalty found\n";
}

echo "\n=== CHECK DONATION PAYMENTS ===\n";
$donation = DB::table('donation_payment_records')->where('mpesa_transaction_id', 'like', '%' . $messageReference . '%')->orWhere('mpesa_transaction_id', 'like', '%' . $mpesaRef . '%')->first();
if ($donation) {
    echo "Found donation:\n";
    echo "  ID: " . $donation->id . "\n";
    echo "  Member: " . $donation->member_id . "\n";
    echo "  Status: " . $donation->status . "\n";
    echo "  Amount: " . $donation->amount . "\n";
} else {
    echo "No donation found\n";
}
