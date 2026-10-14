<?php
require __DIR__ . '/vendor/autoload.php';
$app = require __DIR__ . '/bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

$memberId = '11111111-1111-1111-1111-111111111111';

echo "=== MEMBER STATS INVESTIGATION ===\n";

echo "\n1. CHECK MEMBER_STATS TABLE:\n";
$stats = DB::table('member_stats')->where('member_id', $memberId)->first();
if ($stats) {
    echo "Found member stats:\n";
    foreach ($stats as $key => $value) {
        echo "  $key: " . (is_null($value) ? 'NULL' : $value) . "\n";
    }
} else {
    echo "NO MEMBER STATS FOUND for this member\n";
}

echo "\n2. CHECK ALL CONTRIBUTIONS FOR THIS MEMBER:\n";
$contributions = DB::table('contributions')->where('member_id', $memberId)->orderByDesc('created_at')->get();
echo "Total contributions: " . $contributions->count() . "\n";
foreach ($contributions as $c) {
    echo "  ID: " . $c->id . "\n";
    echo "    Status: " . $c->status . "\n";
    echo "    Amount: " . $c->amount . "\n";
    echo "    Month: " . $c->month . ", Year: " . $c->year . "\n";
    echo "    Paid date: " . ($c->paid_date ?? 'NULL') . "\n";
    echo "    Payment ref: " . ($c->payment_ref ?? 'NULL') . "\n";
    echo "    Checkout ID: " . ($c->stk_checkout_request_id ?? 'NULL') . "\n";
    echo "    Created: " . $c->created_at . "\n";
    echo "    Updated: " . $c->updated_at . "\n";
    echo "\n";
}

echo "\n3. CHECK CONTRIBUTION SUMMARY QUERY:\n";
$totalPaid = DB::table('contributions')->where('member_id', $memberId)->where('status', 'paid')->sum('amount');
$totalPending = DB::table('contributions')->where('member_id', $memberId)->whereIn('status', ['pending', 'partial'])->sum('amount');
$paidCount = DB::table('contributions')->where('member_id', $memberId)->where('status', 'paid')->count();
$pendingCount = DB::table('contributions')->where('member_id', $memberId)->whereIn('status', ['pending', 'partial'])->count();

echo "  Total PAID: KES " . $totalPaid . " (" . $paidCount . " contributions)\n";
echo "  Total PENDING: KES " . $totalPending . " (" . $pendingCount . " contributions)\n";

echo "\n4. CHECK MEMBER TABLE:\n";
$member = DB::table('members')->where('user_id', $memberId)->first();
if ($member) {
    echo "  Name: " . $member->name . "\n";
    echo "  Phone: " . $member->phone . "\n";
    echo "  Member ID: " . $member->member_id . "\n";
    echo "  Status: " . $member->status . "\n";
    echo "  First contribution: " . ($member->first_contribution_date ?? 'NULL') . "\n";
} else {
    echo "  No member record found\n";
}

echo "\n5. CHECK AUTH_USERS TABLE:\n";
$user = DB::table('auth_users')->where('id', $memberId)->first();
if ($user) {
    echo "  Email: " . $user->email . "\n";
    echo "  Role: " . $user->role . "\n";
} else {
    echo "  No auth user found\n";
}

echo "\n6. CHECK IF MEMBER_STATS REFRESH IS NEEDED:\n";
echo "  Member stats table exists: " . (DB::table('member_stats')->where('member_id', $memberId)->exists() ? 'YES' : 'NO') . "\n";
echo "  Contributions total paid: KES " . $totalPaid . "\n";
echo "  This should match 'Total Contributed' on dashboard\n";

echo "\n=== END OF REPORT ===\n";