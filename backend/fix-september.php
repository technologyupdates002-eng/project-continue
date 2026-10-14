<?php
require __DIR__ . '/vendor/autoload.php';
$app = require __DIR__ . '/bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

echo "=== FIX EXISTING PARTIAL PAYMENT DATA ===\n";
$september = DB::table('contributions')->where('id', 'eacf454a-f059-442d-8c43-ff12baa7fbf2')->first();
if ($september && $september->amount < 300 && $september->status === 'paid') {
    DB::table('contributions')->where('id', 'eacf454a-f059-442d-8c43-ff12baa7fbf2')->update([
        'status' => 'partial',
        'paid_date' => null,
        'updated_at' => now(),
    ]);
    echo "Fixed September contribution: changed from paid to partial\n";
} else {
    echo "September contribution already partial or amount >= 300\n";
    echo "Current status: " . $september->status . "\n";
    echo "Current amount: " . $september->amount . "\n";
}

echo "\n=== VERIFY ===";
$c = DB::table('contributions')->where('id', 'eacf454a-f059-442d-8c43-ff12baa7fbf2')->first();
echo "September status: " . $c->status . "\n";
echo "September amount: " . $c->amount . "\n";
echo "September paid_date: " . ($c->paid_date ?? 'NULL') . "\n";

echo "\n=== CLEANUP ===\n";
unlink(__FILE__);
echo "Done\n";