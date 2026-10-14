<?php
require 'vendor/autoload.php';
$app = require 'bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;
use App\Models\CoopTransferRequest;
use App\Services\CoopBank\CoopBankAccountService;

echo "=== All STK transfers for Panda ===\n";
$stk = CoopTransferRequest::where('internal_reference', 'like', '%11111111-1111-1111-1111-111111111111%')->orderBy('created_at', 'desc')->get();
foreach ($stk as $t) {
    echo json_encode([
        'id' => $t->id,
        'message_reference' => $t->message_reference,
        'status' => $t->status,
        'amount' => (float) $t->amount,
        'internal_reference' => $t->internal_reference,
        'created_at' => (string) $t->created_at,
    ]) . "\n";
}

echo "\n=== Bank status for each STK ===\n";
$svc = app(App\Services\CoopBank\CoopBankAccountService::class);
foreach ($stk as $t) {
    try {
        $result = $svc->queryStkStatus($t->message_reference);
        echo $t->message_reference . ' => ' . ($result['status'] ?? 'null') . ' (' . ($result['message_code'] ?? '') . ')' . "\n";
    } catch (\Throwable $e) {
        echo $t->message_reference . ' => ERROR: ' . $e->getMessage() . "\n";
    }
}
