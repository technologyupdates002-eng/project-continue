<?php
require __DIR__ . '/vendor/autoload.php';
$app = require __DIR__ . '/bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

echo "=== CHECK PENDING STK TRANSFERS ===\n";
$transfers = DB::table('coop_transfer_requests')
    ->where('transfer_type', 'STK')
    ->whereIn('status', ['processing', 'submitted', 'pending'])
    ->get(['id', 'message_reference', 'status', 'created_at']);

foreach ($transfers as $t) {
    echo $t->message_reference . ' | ' . $t->status . ' | ' . $t->created_at . "\n";
}
if ($transfers->isEmpty()) {
    echo "No pending STK transfers found\n";
}

echo "\n=== CHECK SPECIFIC REFERENCE STK2609021727520447 ===\n";
$t = DB::table('coop_transfer_requests')->where('message_reference', 'STK2609021727520447')->first();
if ($t) {
    echo 'Found: ' . $t->message_reference . ' | ' . $t->status . ' | ' . $t->created_at . "\n";
} else {
    echo "NOT FOUND\n";
}

echo "\n=== CHECK PENDING CONTRIBUTIONS WITH STK ===\n";
$contributions = DB::table('contributions')
    ->whereNotNull('stk_checkout_request_id')
    ->whereIn('status', ['pending', 'failed'])
    ->get(['id', 'member_id', 'status', 'stk_checkout_request_id', 'amount']);

foreach ($contributions as $c) {
    echo $c->id . ' | ' . $c->member_id . ' | ' . $c->status . ' | ' . $c->stk_checkout_request_id . ' | ' . $c->amount . "\n";
}
if ($contributions->isEmpty()) {
    echo "No pending contributions with STK IDs\n";
}

echo "\n=== CHECK PENDING PENALTIES ===\n";
$penalties = DB::table('penalty_payment_records')
    ->where('status', 'pending')
    ->get(['id', 'member_id', 'status', 'mpesa_transaction_id', 'amount']);

foreach ($penalties as $p) {
    echo $p->id . ' | ' . $p->member_id . ' | ' . $p->status . ' | ' . $p->mpesa_transaction_id . ' | ' . $p->amount . "\n";
}
if ($penalties->isEmpty()) {
    echo "No pending penalties\n";
}
