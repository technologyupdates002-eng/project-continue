<?php
require __DIR__ . '/vendor/autoload.php';
$app = require __DIR__ . '/bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

$checkoutId = 'ws_CO_2a58157adcdb7bccb936d1fe';
$messageReference = 'STK2609062252322803';

echo "=== CHECK PAYMENTS TABLE STRUCTURE ===\n";
$columns = DB::select('DESCRIBE payments');
foreach ($columns as $col) {
    echo "  " . $col->Field . " (" . $col->Type . ")\n";
}

echo "\n=== CHECK PAYMENTS FOR THIS TRANSACTION ===\n";
$payment = DB::table('payments')->where('transaction_id', $messageReference)->orWhere('transaction_id', $checkoutId)->first();
if ($payment) {
    echo "Found payment:\n";
    echo "  ID: " . $payment->id . "\n";
    echo "  Member: " . ($payment->member_id ?? 'N/A') . "\n";
    echo "  Status: " . $payment->status . "\n";
    echo "  Amount: " . $payment->amount . "\n";
    echo "  Type: " . ($payment->type ?? 'N/A') . "\n";
} else {
    echo "No payment found in payments table\n";
}

echo "\n=== CHECK CAMPAIGN_CONTRIBUTIONS ===\n";
$campaign = DB::table('campaign_contributions')->where('member_id', '11111111-1111-1111-1111-111111111111')->orderByDesc('created_at')->limit(5)->get();
foreach ($campaign as $c) {
    echo "  Campaign: " . $c->id . " | " . ($c->status ?? 'N/A') . " | " . ($c->amount ?? 0) . " | " . $c->created_at . "\n";
}

echo "\n=== CHECK UNMATCHED PAYMENTS ===\n";
$unmatched = DB::table('unmatched_payments')->where('bank_reference', $messageReference)->orWhere('bank_reference', $checkoutId)->first();
if ($unmatched) {
    echo "Found unmatched payment:\n";
    echo "  ID: " . $unmatched->id . "\n";
    echo "  Amount: " . $unmatched->amount . "\n";
    echo "  Status: " . $unmatched->status . "\n";
} else {
    echo "No unmatched payment found\n";
}

echo "\n=== CLEANUP ===\n";
unlink(__DIR__ . '/investigate-stk.php');
unlink(__DIR__ . '/investigate-stk2.php');
unlink(__DIR__ . '/investigate-stk3.php');
unlink(__DIR__ . '/investigate-stk4.php');
unlink(__DIR__ . '/investigate-stk5.php');
echo "Cleaned up investigation files\n";