<?php

namespace App\Console\Commands;

use App\Services\BankSyncService;
use Illuminate\Console\Command;

class CoopSyncStatement extends Command
{
    protected $signature = 'coop:sync-statement {--from= : Start date (Y-m-d)} {--to= : End date (Y-m-d)} {--account= : Account number} {--user= : User ID performing sync}';
    protected $description = 'Sync bank statements from Co-operative Bank (paginated + reconciliation)';

    public function handle(BankSyncService $syncService): int
    {
        $fromDate = $this->option('from') ?? now()->subDay()->toDateString();
        $toDate = $this->option('to') ?? now()->toDateString();
        $account = $this->option('account') ?? config('coop_bank.account_number');
        $userId = $this->option('user');

        $this->info("Syncing Co-op bank statements for account {$account}");
        $this->info("Date range: {$fromDate} to {$toDate}");

        try {
            $result = $syncService->syncFullStatement($account, $fromDate, $toDate, $userId);

            if ($result['status'] === 'success') {
                $summary = $result['summary'];
                $this->info("Synced {$summary['total_transactions']} transactions successfully");
                $this->info("  New: {$summary['new_transactions']}");
                $this->info("  Duplicates: {$summary['duplicate_skipped']}");
                $this->info("  Matched: {$summary['matched']}");
                $this->info("  Unmatched: {$summary['unmatched']}");
                $this->info("  New members: {$summary['new_members']}");
                $this->info("  Contributions created: {$summary['contributions_created']}");
                if ($result['bank_balance'] !== null) {
                    $this->info("  Bank balance: " . $result['bank_balance']);
                }
                $this->info("  Batch ID: {$result['batch_id']}");
            } else {
                $this->error('Sync failed: ' . ($result['error'] ?? 'Unknown error'));
            }

            return $result['status'] === 'success' ? 0 : 1;
        } catch (\Throwable $e) {
            $this->error('Sync failed: ' . $e->getMessage());
            return 1;
        }
    }
}
