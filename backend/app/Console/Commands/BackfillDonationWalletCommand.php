<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\WalletService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class BackfillDonationWalletCommand extends Command
{
    protected $signature = 'donations:backfill-wallet
        {--apply : Credit verified donations that have no matching wallet transaction}
        {--limit=0 : Limit verified donation records inspected; zero means all}';

    protected $description = 'Backfill missing donation wallet credits for verified donation records.';

    public function handle(WalletService $walletService): int
    {
        $apply = (bool) $this->option('apply');
        $limit = (int) $this->option('limit');
        if ($limit < 0) {
            $this->error('--limit must be zero or a positive integer.');

            return self::INVALID;
        }

        $report = [
            'mode' => $apply ? 'apply' : 'dry-run',
            'verified_donations_inspected' => 0,
            'already_credited' => 0,
            'missing_credit' => 0,
            'credited' => 0,
            'conflicts' => 0,
            'failures' => 0,
        ];

        $donations = DB::table('donation_payment_records')
            ->where('status', 'verified')
            ->orderBy('id');
        if ($limit > 0) {
            $donations->limit($limit);
        }

        $donations->chunkById(200, function ($records) use (&$report, $apply, $walletService): void {
            foreach ($records as $donation) {
                $report['verified_donations_inspected']++;

                $existing = DB::table('wallet_transactions')
                    ->where('wallet_type', 'donation')
                    ->where('direction', 'credit')
                    ->where('reference_id', (string) $donation->id)
                    ->where('reference_table', 'donation_payment_records')
                    ->first();

                if ($existing) {
                    if (abs((float) $existing->gross_amount - (float) $donation->amount) >= 0.01) {
                        $report['conflicts']++;
                        Log::error('Donation wallet backfill found a conflicting existing credit', [
                            'donation_id' => (string) $donation->id,
                            'donation_amount' => (float) $donation->amount,
                            'credited_amount' => (float) $existing->gross_amount,
                        ]);
                    } else {
                        $report['already_credited']++;
                    }

                    continue;
                }

                $report['missing_credit']++;
                $receipt = (string) (
                    $donation->payment_ref
                    ?: $donation->mpesa_transaction_id
                    ?: 'DONATION-'.$donation->id
                );
                $receiptOwner = DB::table('wallet_transactions')
                    ->where('mpesa_receipt', $receipt)
                    ->where('direction', 'credit')
                    ->first();

                if ($receiptOwner) {
                    $report['conflicts']++;
                    Log::error('Donation wallet backfill found a receipt already used by another wallet credit', [
                        'donation_id' => (string) $donation->id,
                        'mpesa_receipt' => $receipt,
                        'wallet_transaction_id' => (string) $receiptOwner->id,
                    ]);
                    continue;
                }

                if (! $apply) {
                    continue;
                }

                try {
                    DB::transaction(function () use ($donation, $receipt, $walletService): void {
                        $lockedDonation = DB::table('donation_payment_records')
                            ->where('id', $donation->id)
                            ->lockForUpdate()
                            ->first();

                        if (! $lockedDonation || $lockedDonation->status !== 'verified') {
                            return;
                        }

                        $walletService->credit(
                            'donation',
                            (string) ($lockedDonation->member_id ?: 'system'),
                            (float) $lockedDonation->amount,
                            $receipt,
                            'donation_backfill',
                            (string) $lockedDonation->id,
                            'donation_payment_records',
                            'Backfill verified donation missing wallet credit'
                        );
                    });

                    $report['credited']++;
                } catch (\Throwable $exception) {
                    $report['failures']++;
                    Log::error('Donation wallet backfill failed', [
                        'donation_id' => (string) $donation->id,
                        'error' => $exception->getMessage(),
                    ]);
                    $this->warn("Could not credit donation {$donation->id}; see application logs.");
                }
            }
        }, 'id');

        $this->line(json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        return $report['conflicts'] > 0 || $report['failures'] > 0
            ? self::FAILURE
            : self::SUCCESS;
    }
}
