<?php
require 'vendor/autoload.php';
$app = require 'bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;

$memberId = '11111111-1111-1111-1111-111111111111';

// Get welfare settings
$settings = DB::table('welfare_settings')->first();
$dueDay = (int) ($settings->contribution_due_day ?? 15);
echo "Welfare settings: due_day=$dueDay, penalty_amount=" . ($settings->penalty_amount ?? 50) . ", grace_days=" . ($settings->penalty_grace_days ?? 30) . "\n\n";

// Fix due dates for all contributions
echo "=== Fixing due dates ===\n";
$contribs = DB::table('contributions')->where('member_id', $memberId)->get();
foreach ($contribs as $c) {
    $newDueDate = sprintf('%04d-%02d-%02d', $c->year, $c->month, $dueDay);
    echo "Update: {$c->year}-{$c->month} due_date={$c->due_date} => $newDueDate\n";
    DB::table('contributions')
        ->where('id', $c->id)
        ->update(['due_date' => $newDueDate]);
}

echo "\n=== Updated contributions ===\n";
$updated = DB::table('contributions')->where('member_id', $memberId)->get();
foreach ($updated as $c) {
    echo json_encode($c) . "\n";
}
