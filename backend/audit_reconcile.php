<?php
require 'vendor/autoload.php';
$app = require 'bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;
use App\Models\CoopTransferRequest;
use App\Models\CoopTransferResponse;

// PHASE 2: Reconcile Denis bf885e30 through the application's processCallback mechanism
// The STK transfer 6c759df3 failed with MessageCode 2001 (initiator invalid).
// No callback was received from Co-op Bank (as confirmed - they don't send STK callbacks).
// The donation_payment_record bf885e30 is still in 'pending' status.
// We need to reconcile it using the bank status we already queried.

echo "=== PHASE 2: Denis Transaction Reconciliation ===\n\n";

// First, let's verify the current state
$donation = DB::table('donation_payment_records')->where('id', 'bf885e30')->first();
$transfer = CoopTransferRequest::find('6c759df3');
$response = CoopTransferResponse::where('transfer_request_id', '6c759df3')->first();

echo "Donation record bf885e30 status: " . ($donation->status ?? 'N/A') . "\n";
echo "Transfer 6c759df3 status: " . ($transfer->status ?? 'N/A') . "\n";
echo "Bank response status: " . ($response->status ?? 'N/A') . "\n";
echo "Bank response payload: " . ($response->response_payload ?? 'N/A') . "\n\n";

// The bank already told us this failed (MessageCode 2001).
// We need to use the application's reconciliation path.
// The safest way is to call reconcileStkTransfers which uses processCallback internally.

echo "=== Running reconcileStkTransfers for the date range covering this transfer ===\n";
try {
    $paymentService = app(\App\Services\PaymentService::class);
    $result = $paymentService->reconcileStkTransfers(
        ['roles' => ['admin', 'super_admin']],
        '2026-09-07',
        '2026-09-07'
    );
    echo json_encode($result, JSON_PRETTY_PRINT) . "\n";
} catch (\Throwable $e) {
    echo "reconcileStkTransfers error: " . $e->getMessage() . "\n";
}

// Check if bf885e30 was updated
echo "\n=== Post-reconciliation state ===\n";
$donationAfter = DB::table('donation_payment_records')->where('id', 'bf885e30')->first();
echo "Donation bf885e30 status after reconciliation: " . ($donationAfter->status ?? 'N/A') . "\n";
echo "Donation bf885e30 payment_ref after reconciliation: " . ($donationAfter->payment_ref ?? 'N/A') . "\n";

// Check for any new wallet transactions
echo "\n=== Wallet transactions after reconciliation ===\n";
$wallets = DB::table('wallet_transactions')->get();
foreach ($wallets as $w) {
    echo json_encode($w, JSON_PRETTY_PRINT) . "\n";
}
