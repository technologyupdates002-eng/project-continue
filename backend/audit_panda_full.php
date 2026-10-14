<?php
require 'vendor/autoload.php';
$app = require 'bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;
use App\Models\CoopTransferRequest;
use App\Services\CoopBank\CoopBankAccountService;
use App\Services\ContributionObligationService;

$memberId = '11111111-1111-1111-1111-111111111111';
$phone = '0717562660';

echo "=== PANDA FULL AUDIT ===\n\n";

echo "=== 1. MEMBER RECORD ===\n";
$member = DB::table('members')->where('id', $memberId)->first();
echo json_encode($member, JSON_PRETTY_PRINT) . "\n";

echo "\n=== 2. WELFARE SETTINGS ===\n";
$settings = DB::table('welfare_settings')->first();
echo json_encode($settings, JSON_PRETTY_PRINT) . "\n";

echo "\n=== 3. ALL CONTRIBUTIONS ===\n";
$contribs = DB::table('contributions')->where('member_id', $memberId)->orderBy('year', 'desc')->orderBy('month', 'desc')->get();
foreach ($contribs as $c) {
    echo json_encode($c) . "\n";
}
echo "Sum of all contributions: " . $contribs->sum('amount') . "\n";
echo "Sum of paid contributions: " . $contribs->where('status', 'paid')->sum('amount') . "\n";
echo "Sum of partial contributions: " . $contribs->where('status', 'partial')->sum('amount') . "\n";

echo "\n=== 4. ALL PAYMENTS ===\n";
$payments = DB::table('payments')->where('member_id', $memberId)->get();
foreach ($payments as $p) {
    echo json_encode($p) . "\n";
}
echo "Sum of payments: " . $payments->sum('amount') . "\n";

echo "\n=== 5. ALL STK TRANSFERS ===\n";
$stk = CoopTransferRequest::where('internal_reference', 'like', '%' . $memberId . '%')->orderBy('created_at', 'desc')->get();
foreach ($stk as $t) {
    echo json_encode([
        'message_reference' => $t->message_reference,
        'status' => $t->status,
        'amount' => (float) $t->amount,
        'created_at' => (string) $t->created_at,
    ]) . "\n";
}
echo "Successful STK count: " . $stk->where('status', 'successful')->count() . "\n";
echo "Successful STK total: " . $stk->where('status', 'successful')->sum('amount') . "\n";

echo "\n=== 6. BANK STATUS FOR ALL STK ===\n";
$svc = app(App\Services\CoopBank\CoopBankAccountService::class);
foreach ($stk as $t) {
    try {
        $result = $svc->queryStkStatus($t->message_reference);
        echo $t->message_reference . ' => ' . ($result['status'] ?? 'null') . ' (' . ($result['message_code'] ?? '') . ')' . "\n";
    } catch (\Throwable $e) {
        echo $t->message_reference . ' => ERROR: ' . $e->getMessage() . "\n";
    }
}

echo "\n=== 7. PENALTIES ===\n";
$penalties = DB::table('penalties')->where('member_id', $memberId)->get();
foreach ($penalties as $p) {
    echo json_encode($p) . "\n";
}
if ($penalties->isEmpty()) echo "(none)\n";

echo "\n=== 8. MEMBER SUMMARY FROM OBLIGATION SERVICE ===\n";
$obligation = new ContributionObligationService();
$summary = $obligation->memberSummary($memberId);
echo json_encode($summary, JSON_PRETTY_PRINT) . "\n";

echo "\n=== 9. BANK TRANSACTIONS FOR PHONE ===\n";
$bankTxns = DB::table('bank_transactions')->where('narration', 'like', '%' . $phone . '%')->get();
foreach ($bankTxns as $bt) {
    echo json_encode($bt) . "\n";
}
if ($bankTxns->isEmpty()) echo "(none)\n";
