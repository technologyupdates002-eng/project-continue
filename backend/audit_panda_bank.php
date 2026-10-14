<?php
require 'vendor/autoload.php';
$app = require 'bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;

echo "=== Panda member record ===\n";
$panda = DB::table('members')->where('id', '11111111-1111-1111-1111-111111111111')->first();
echo json_encode($panda, JSON_PRETTY_PRINT) . "\n";

echo "\n=== All contributions for Panda ===\n";
$contribs = DB::table('contributions')->where('member_id', '11111111-1111-1111-1111-111111111111')->orderBy('year', 'desc')->orderBy('month', 'desc')->get();
foreach ($contribs as $c) {
    echo json_encode($c) . "\n";
}
echo "Total from contributions: " . $contribs->sum('amount') . "\n";

echo "\n=== All payments for Panda ===\n";
$payments = DB::table('payments')->where('member_id', '11111111-1111-1111-1111-111111111111')->get();
foreach ($payments as $p) {
    echo json_encode($p) . "\n";
}
echo "Total from payments: " . $payments->sum('amount') . "\n";

echo "\n=== All wallet transactions for Panda ===\n";
$wallets = DB::table('wallet_transactions')->where('created_by', '11111111-1111-1111-1111-111111111111')->orWhere('reference_id', 'like', '%11111111-1111-1111-1111-111111111111%')->get();
foreach ($wallets as $w) {
    echo json_encode($w) . "\n";
}
echo "Total from wallet: " . $wallets->sum('net_amount') . "\n";

echo "\n=== Bank transactions matching Panda ===\n";
$bankTxns = DB::table('bank_transactions')->where('narration', 'like', '%0717562660%')->orWhere('narration', 'like', '%Panda%')->orWhere('member_id', '11111111-1111-1111-1111-111111111111')->get();
foreach ($bankTxns as $bt) {
    echo json_encode($bt) . "\n";
}
if ($bankTxns->isEmpty()) echo "(none)\n";

echo "\n=== Unmatched bank transactions ===\n";
$unmatched = DB::table('bank_unmatched_transactions')->where('narration', 'like', '%0717562660%')->orWhere('bank_reference', 'like', '%0717562660%')->get();
foreach ($unmatched as $u) {
    echo json_encode($u) . "\n";
}
if ($unmatched->isEmpty()) echo "(none)\n";
