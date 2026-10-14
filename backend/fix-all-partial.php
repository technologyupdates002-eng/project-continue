<?php
require __DIR__ . '/vendor/autoload.php';
$app = require __DIR__ . '/bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

echo "=== FIND ALL UNDERPAID CONTRIBUTIONS MARKED AS PAID ===\n";

$monthly = (float) (DB::table('welfare_settings')->value('monthly_contribution_amount') ?? 300);

$contributions = DB::table('contributions')
    ->where('status', 'paid')
    ->where('amount', '<', $monthly)
    ->get(['id', 'member_id', 'amount', 'status', 'month', 'year', 'paid_date', 'payment_ref']);

if ($contributions->isEmpty()) {
    echo "No underpaid contributions found marked as paid.\n";
    exit;
}

echo "Found " . $contributions->count() . " contributions that are marked as 'paid' but amount < $monthly:\n";
foreach ($contributions as $c) {
    echo "  ID: " . $c->id . "\n";
    echo "    Member: " . $c->member_id . "\n";
    echo "    Amount: " . $c->amount . " (monthly: $monthly)\n";
    echo "    Month: " . $c->month . "/" . $c->year . "\n";
    echo "    Paid date: " . ($c->paid_date ?? 'NULL') . "\n";
    echo "    Payment ref: " . ($c->payment_ref ?? 'NULL') . "\n";
    echo "\n";
}

echo "=== FIXING ===\n";
foreach ($contributions as $c) {
    DB::table('contributions')
        ->where('id', $c->id)
        ->update([
            'status' => 'partial',
            'paid_date' => null,
            'updated_at' => now(),
        ]);
    echo "  Fixed: " . $c->id . " (member: " . $c->member_id . ", amount: " . $c->amount . ")\n";
}

echo "\n=== VERIFY ===\n";
$remaining = DB::table('contributions')
    ->where('status', 'paid')
    ->where('amount', '<', $monthly)
    ->count();
echo "Remaining underpaid 'paid' contributions: " . $remaining . "\n";

echo "\n=== CLEAR ALL MEMBER STATS CACHE ===\n";
$members = DB::table('members')->get(['id']);
$redis = new Redis();
$redis->connect('127.0.0.1', 6379, 2);
foreach ($members as $m) {
    $redis->del('member:stats:' . $m->id);
}
$redis->del('member:stats:all');
$redis->del('member:stats:summary');
$redis->close();
echo "Cleared cache for " . $members->count() . " members\n";

echo "\n=== DONE ===\n";
unlink(__FILE__);