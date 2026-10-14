<?php
/**
 * KHWWC STK Push Fix Script
 * Run from khwwc directory: php fix-stk.php
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);

$base = __DIR__;
$app_dir = $base;
$ok = true;

// =============================================================================
// Fix 1: Deploy CoopReconcileTransfers.php
// =============================================================================
echo "=== Fix 1: CoopReconcileTransfers.php ===\n";

$reconcile_path = $app_dir . '/app/Console/Commands/CoopReconcileTransfers.php';
$timestamp = date('Ymd_His');

if (file_exists($reconcile_path)) {
    copy($reconcile_path, $reconcile_path . '.backup.' . $timestamp);
    echo "Backed up existing CoopReconcileTransfers.php\n";
}

$reconcile_content = <<<'PHPEOF'
<?php

namespace App\Console\Commands;

use App\Models\CoopTransferRequest;
use App\Services\CoopBank\CoopBankAccountService;
use App\Services\CoopBank\CoopBankTransferService;
use Illuminate\Console\Command;

class CoopReconcileTransfers extends Command
{
    protected $signature = 'coop:reconcile-transfers {--limit=100}';
    protected $description = 'Check status of pending transfers from Co-operative Bank';

    public function handle(CoopBankTransferService $transferService, CoopBankAccountService $accountService): int
    {
        $limit = (int) $this->option('limit');
        $pending = CoopTransferRequest::whereIn('status', ['processing', 'submitted'])
            ->where('retry_count', '<', 5)
            ->orderBy('created_at', 'desc')
            ->limit($limit)
            ->get();

        $this->info("Found {$pending->count()} pending transfers to check");

        $checked = 0;
        $updated = 0;

        foreach ($pending as $transfer) {
            try {
                if ($transfer->transfer_type === 'STK') {
                    $result = $accountService->queryStkStatus($transfer->message_reference);
                } else {
                    $result = $transferService->queryTransactionStatus($transfer->message_reference);
                }
                $checked++;

                $status = strtoupper($result['status'] ?? '');

                if ($status === 'SUCCESS') {
                    $transfer->update(['status' => 'successful']);
                    $updated++;
                    $this->line("  ✓ Transfer {$transfer->message_reference}: successful");
                } elseif ($status === 'FAILED') {
                    $transfer->update([
                        'status' => 'failed',
                        'error_message' => $result['response']['StatusDescription'] ?? 'Failed',
                    ]);
                    $updated++;
                    $this->line("  ✗ Transfer {$transfer->message_reference}: failed");
                } else {
                    $this->line("  … Transfer {$transfer->message_reference}: {$status}");
                }
            } catch (\Throwable $e) {
                $this->warn("  ! Transfer {$transfer->message_reference}: error - " . $e->getMessage());
            }
        }

        $this->info("Checked {$checked} transfers, updated {$updated}");

        return 0;
    }
}
PHPEOF;

file_put_contents($reconcile_path, $reconcile_content);
echo "Wrote CoopReconcileTransfers.php\n";

// =============================================================================
// Fix 2: Patch CoopBankTransactionService.php
// =============================================================================
echo "\n=== Fix 2: CoopBankTransactionService.php ===\n";

$stk_service_path = $app_dir . '/app/Services/CoopBank/CoopBankTransactionService.php';
$content = file_get_contents($stk_service_path);

// The original code has:
//            $checkoutRequestId = $body['CheckoutRequestID'] ?? $body['response']['CheckoutRequestID'] ?? null;
//
//            CoopTransferResponse::create([
// We need to insert before CoopTransferResponse::create([

$search = "            CoopTransferResponse::create([";
$replace = "            CoopTransferRequest::where('id', \$transferRecord->id)->update([\n                'status' => 'processing',\n                'updated_at' => now(),\n            ]);\n\n            CoopTransferResponse::create([";

// Find the first occurrence (only one in this file, inside initiateStkPush)
$pos = strpos($content, $search);
if ($pos === false) {
    echo "ERROR: Pattern not found in CoopBankTransactionService.php\n";
    $ok = false;
} elseif (strpos($content, "status.*processing") !== false) {
    echo "Already patched - skipping\n";
} else {
    copy($stk_service_path, $stk_service_path . '.backup.' . $timestamp);
    echo "Backed up existing CoopBankTransactionService.php\n";

    // Insert the replacement
    $content = substr($content, 0, $pos) . $replace . substr($content, $pos + strlen($search));
    file_put_contents($stk_service_path, $content);
    echo "Patched CoopBankTransactionService.php\n";
}

// =============================================================================
// Fix 3: Laravel optimization
// =============================================================================
echo "\n=== Fix 3: Laravel optimization ===\n";

chdir($app_dir);
system('php artisan config:clear --no-interaction 2>&1', $ret);
system('php artisan route:clear --no-interaction 2>&1', $ret);
system('php artisan view:clear --no-interaction 2>&1', $ret);
system('php artisan config:cache --no-interaction 2>&1', $ret);
system('php artisan route:cache --no-interaction 2>&1', $ret);

echo "\n=== All fixes applied ===\n";
if ($ok) {
    echo "Verify: https://app1.kirinyagahealthcareworkerswelfare.co.ke/api/health\n";
} else {
    echo "Some fixes failed - check output above\n";
}
