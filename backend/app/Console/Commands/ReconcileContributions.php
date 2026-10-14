<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Jobs\ProcessMemberReconciliation;
use App\Services\ContributionReconciliationService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class ReconcileContributions extends Command
{
    protected $signature = 'contributions:reconcile 
                            {--dry-run : Show what would be changed without modifying data}
                            {--member-id= : Reconcile a specific member only}
                            {--chunk=50 : Number of members per chunk}';

    protected $description = 'Full historical contribution reconciliation for all members';

    public function handle(ContributionReconciliationService $reconciliation): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $memberId = $this->option('member-id');
        $chunk = (int) $this->option('chunk');

        if ($dryRun) {
            $this->warn('=== DRY RUN MODE ===');
            $this->warn('No data will be modified.');
        }

        if ($memberId) {
            $members = DB::table('members')->where('id', $memberId)->get(['id', 'name']);
        } else {
            $members = DB::table('members')->where('is_active', 1)->get(['id', 'name']);
        }

        if ($members->isEmpty()) {
            $this->info('No members found.');
            return self::SUCCESS;
        }

        $this->info("Processing {$members->count()} member(s)...");

        $processed = 0;
        $fixed = 0;
        $errors = 0;

        foreach ($members as $member) {
            try {
                $result = $reconciliation->reconcileMember($member->id, $dryRun);

                if (isset($result['error'])) {
                    $this->error("Member {$member->name}: {$result['error']}");
                    $errors++;
                    continue;
                }

                $this->line("Member: {$result['member_name']}");
                $this->line("  Required months: {$result['required_months']}");
                $this->line("  Paid months: {$result['paid_months']}");
                $this->line("  Overdue months: {$result['overdue_months']}");
                $this->line("  Total paid: KES " . number_format($result['total_paid'], 2));
                $this->line("  Credit: KES " . number_format($result['credit'], 2));
                $this->line("  Next due: " . ($result['next_due_date'] ?? 'N/A'));

                if ($result['fixed'] ?? false) {
                    $fixed++;
                }

                $processed++;
            } catch (\Throwable $e) {
                $this->error("Member {$member->name}: " . $e->getMessage());
                $errors++;
            }
        }

        $this->info("=== Reconciliation Complete ===");
        $this->info("Processed: {$processed}");
        $this->info("Fixed: {$fixed}");
        $this->info("Errors: {$errors}");

        if ($dryRun) {
            $this->warn('This was a dry run. Run without --dry-run to apply changes.');
        }

        return self::SUCCESS;
    }
}
