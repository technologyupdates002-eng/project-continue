<?php
require __DIR__ . '/vendor/autoload.php';
$app = require __DIR__ . '/bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

echo "=== CHECK WELFARE SETTINGS ===\n";
$settings = DB::table('welfare_settings')->first();
if ($settings) {
    echo "Monthly contribution: " . ($settings->monthly_contribution_amount ?? 'N/A') . "\n";
    echo "Penalty amount: " . ($settings->penalty_amount ?? 'N/A') . "\n";
    echo "Grace days: " . ($settings->contribution_grace_days ?? 'N/A') . "\n";
} else {
    echo "No welfare settings found\n";
}

echo "\n=== CHECK CONTRIBUTION STATUSES ===\n";
$statuses = DB::table('contributions')->distinct()->pluck('status');
foreach ($statuses as $status) {
    echo "  Status: $status\n";
}

echo "\n=== CHECK ADMIN CONTRIBUTIONS ===\n";
$contributions = DB::table('contributions')->where('member_id', '11111111-1111-1111-1111-111111111111')->get();
foreach ($contributions as $c) {
    echo "  ID: " . $c->id . "\n";
    echo "    Amount: " . $c->amount . "\n";
    echo "    Status: " . $c->status . "\n";
    echo "    Paid date: " . ($c->paid_date ?? 'NULL') . "\n";
    echo "    Payment ref: " . ($c->payment_ref ?? 'NULL') . "\n";
    echo "    Month: " . $c->month . ", Year: " . $c->year . "\n";
    echo "    Checkout ID: " . ($c->stk_checkout_request_id ?? 'NULL') . "\n";
    echo "\n";
}

echo "\n=== CALCULATE EXPECTED OUTSTANDING ===\n";
$monthly = $settings->monthly_contribution_amount ?? 300;
$totalPaid = (float) $contributions->where('status', 'paid')->sum('amount');
$paidMonths = $contributions->where('status', 'paid')->count();
echo "Monthly amount: $monthly\n";
echo "Total paid: $totalPaid\n";
echo "Paid months: $paidMonths\n";
echo "Expected for paid months: " . ($paidMonths * $monthly) . "\n";
echo "Difference: " . (($paidMonths * $monthly) - $totalPaid) . "\n";

echo "\n=== CLEANUP ===\n";
unlink(__FILE__);
echo "Done\n";