<?php
require 'vendor/autoload.php';
$app = require 'bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;

$memberId = '11111111-1111-1111-1111-111111111111';
$today = date('Y-m-d');

echo "=== Generating penalties for past-due months ===\n";

// Get all unpaid contributions that are past due date
$overdueContribs = DB::table('contributions')
    ->where('member_id', $memberId)
    ->where('status', '!=', 'paid')
    ->where('due_date', '<', $today)
    ->orderBy('year', 'asc')
    ->orderBy('month', 'asc')
    ->get();

echo "Found " . $overdueContribs->count() . " past-due contributions\n";

foreach ($overdueContribs as $c) {
    $existingPenalty = DB::table('penalties')
        ->where('member_id', $memberId)
        ->where('year', $c->year)
        ->where('month', $c->month)
        ->first();
    
    if ($existingPenalty) {
        echo "Penalty already exists for {$c->year}-{$c->month}\n";
        continue;
    }
    
    $penaltyAmount = 50.00; // From welfare_settings
    $reason = "Late payment for " . date('F Y', mktime(0, 0, 0, $c->month, 1, $c->year));
    
    echo "Creating penalty for {$c->year}-{$c->month}: KES $penaltyAmount ($reason)\n";
    
    DB::table('penalties')->insert([
        'id' => DB::raw('UUID()'),
        'member_id' => $memberId,
        'year' => $c->year,
        'month' => $c->month,
        'contribution_id' => $c->id,
        'amount' => $penaltyAmount,
        'reason' => $reason,
        'is_paid' => 0,
        'created_at' => now(),
    ]);
}

echo "\n=== Penalties after generation ===\n";
$penalties = DB::table('penalties')->where('member_id', $memberId)->get();
foreach ($penalties as $p) {
    echo json_encode($p) . "\n";
}
if ($penalties->isEmpty()) {
    echo "(none)\n";
}

echo "\n=== Member summary after penalty generation ===\n";
$obligation = new App\Services\ContributionObligationService();
$summary = $obligation->memberSummary($memberId);
echo "penalty_months: " . $summary["penalty_months"] . "\n";
echo "penalty_amount: " . $summary["penalty_amount"] . "\n";
echo "penalty_outstanding: " . $summary["penalty_outstanding"] . "\n";
echo "total_outstanding: " . $summary["total_outstanding"] . "\n";
