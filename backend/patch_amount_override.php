<?php
$file = 'app/Services/PaymentService.php';
$content = file_get_contents($file);

$search = <<<'CODE'
                if ($isBankSuccess && in_array($contribution->status, ['pending', 'processing', 'partial'])) {
                    $monthly = $this->obligation
                        ? (float) $this->obligation->monthly()
                        : (float) (DB::table('welfare_settings')->value('monthly_contribution_amount') ?? 300);
                    $actualAmount = (float) $contribution->amount;

                    DB::table('contributions')
                        ->where('id', $contribution->id)
                        ->update([
                            'amount' => $monthly,
                            'updated_at' => now(),
                        ]);

                    try {
                        $this->allocation->allocatePayment(
                            $contribution->member_id,
                            $actualAmount,
                            now()->format('Y-m-d'),
                            $contribution->payment_ref ?? $checkoutRequestId,
                            null
                        );
                    } catch (\Throwable $e) {
                        Log::warning('PaymentService: allocation failed during status check', [
                            'member_id' => $contribution->member_id,
                            'amount' => $actualAmount,
                            'error' => $e->getMessage(),
                        ]);
                    }

                    if ($contribution->member_id) {
                        $this->sendStkSuccessSms($contribution->member_id, $actualAmount, $contribution->payment_ref ?? $checkoutRequestId);
                    }

                    $contribution->refresh();
CODE;

$replace = <<<'CODE'
                if ($isBankSuccess && in_array($contribution->status, ['pending', 'processing', 'partial'])) {
                    $actualAmount = (float) $contribution->amount;

                    try {
                        $this->allocation->allocatePayment(
                            $contribution->member_id,
                            $actualAmount,
                            now()->format('Y-m-d'),
                            $contribution->payment_ref ?? $checkoutRequestId,
                            null
                        );
                    } catch (\Throwable $e) {
                        Log::warning('PaymentService: allocation failed during status check', [
                            'member_id' => $contribution->member_id,
                            'amount' => $actualAmount,
                            'error' => $e->getMessage(),
                        ]);
                    }

                    if ($contribution->member_id) {
                        $this->sendStkSuccessSms($contribution->member_id, $actualAmount, $contribution->payment_ref ?? $checkoutRequestId);
                    }

                    $contribution->refresh();
CODE;

$count = substr_count($content, $search);
echo 'Found ' . $count . ' occurrences' . PHP_EOL;
if ($count > 0) {
    $content = str_replace($search, $replace, $content);
    file_put_contents($file, $content);
    echo 'Patched ' . $file . PHP_EOL;
} else {
    echo 'Pattern not found' . PHP_EOL;
}
