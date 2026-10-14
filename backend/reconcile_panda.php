<?php
require 'vendor/autoload.php';
$app = require 'bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;
use App\Services\Statement\ContributionAllocationService;
use App\Services\MemberFinancialProjectionService;

$memberId = '11111111-1111-1111-1111-111111111111';
$totalReceived = 30.00;
$txRef = 'RECON-PANDA-20260908';

echo "=== PANDA DATA RECONCILIATION ===\n\n";

// Step 1: Reset all contributions to unpaid/partial with amount 0
echo "=== Step 1: Resetting contributions ===\n";
$contribs = DB::table('contributions')->where('member_id', $memberId)->get();
foreach ($contribs as $c) {
    echo "Reset: {$c->year}-{$c->month} amount={$c->amount} status={$c->status} => 0 partial\n";
    DB::table('contributions')
        ->where('id', $c->id)
        ->update([
            'amount' => 0,
            'status' => 'partial',
            'paid_date' => null,
            'payment_id' => null,
            'updated_at' => now(),
        ]);
}

// Step 2: Update or create payment record
echo "\n=== Step 2: Payment record ===\n";
$payment = DB::table('payments')->where('member_id', $memberId)->first();
if ($payment) {
    echo "Update payment {$payment->id} amount={$payment->amount} => {$totalReceived}\n";
    DB::table('payments')
        ->where('id', $payment->id)
        ->update([
            'amount' => $totalReceived,
            'transaction_ref' => $txRef,
        ]);
} else {
    echo "Create payment for KES {$totalReceived}\n";
    DB::table('payments')->insert([
        'id' => DB::raw('UUID()'),
        'member_id' => $memberId,
        'amount' => $totalReceived,
        'transaction_ref' => $txRef,
        'source' => 'contribution_allocation',
        'matched' => 1,
        'received_at' => now(),
        'created_at' => now(),
    ]);
}

// Step 3: Run allocation engine
echo "\n=== Step 3: Running allocation engine ===\n";
$obligationService = new ContributionObligationService();
$allocation = app(ContributionAllocationService::class);
try {
    $result = $allocation->allocatePayment(
        $memberId,
        $totalReceived,
        date('Y-m-d'),
        $txRef,
        null
    );
    echo "Allocation result:\n";
    echo json_encode($result, JSON_PRETTY_PRINT) . "\n";
} catch (\Throwable $e) {
    echo "Allocation error: " . $e->getMessage() . "\n";
}

// Step 4: Recalculate total_contributions
echo "\n=== Step 4: Recalculating total_contributions ===\n";
app(MemberFinancialProjectionService::class)->syncMember($memberId);
$newTotal = DB::table('members')->where('id', $memberId)->value('total_contributions');
echo "Projected total_contributions: KES " . number_format((float) $newTotal, 2) . "\n";

// Step 5: Final state
echo "\n=== Step 5: Final state ===\n";
$member = DB::table('members')->where('id', $memberId)->first();
echo "total_contributions: " . $member->total_contributions . "\n";

$contribs = DB::table('contributions')->where('member_id', $memberId)->orderBy('year', 'desc')->orderBy('month', 'desc')->get();
foreach ($contribs as $c) {
    echo "Contribution: {$c->year}-{$c->month} => KES {$c->amount} ({$c->status})\n";
}

$payment = DB::table('payments')->where('member_id', $memberId)->first();
echo "Payment: KES {$payment->amount} ({$payment->transaction_ref})\n";

echo "\n=== RECONCILIATION COMPLETE ===\n";
echo "Old total_contributions: 605.00\n";
echo "New total_contributions: " . number_format($newTotal, 2) . "\n";
echo "Actual STK received: KES " . number_format($totalReceived, 2) . "\n";
