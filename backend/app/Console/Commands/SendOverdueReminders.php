<?php

namespace App\Console\Commands;

use App\Jobs\SendOverdueReminderSmsJob;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class SendOverdueReminders extends Command
{
    protected $signature = 'reminders:overdue {--dry-run : Show what would be sent without sending}';

    protected $description = 'Send SMS reminders for contributions due in 3 days (3-day rule)';

    protected int $reminderDays = 3;

    public function handle(): int
    {
        $dryRun = $this->option('dry-run');

        $this->info("Looking for contributions due in {$this->reminderDays} days...");

        $dueDate = date('Y-m-d', strtotime("+{$this->reminderDays} days"));

        $contributions = DB::table('contributions as c')
            ->join('members as m', 'c.member_id', '=', 'm.id')
            ->whereDate('c.due_date', $dueDate)
            ->where('c.status', '!=', 'paid')
            ->where('m.is_active', 1)
            ->whereNotNull('m.phone')
            ->where('m.phone', '!=', '')
            ->select(
                'c.id as contribution_id',
                'c.amount',
                'c.due_date',
                'c.year',
                'c.month',
                'm.id as member_id',
                'm.phone',
                'm.name'
            )
            ->get();

        if ($contributions->isEmpty()) {
            $this->info('No reminders to send.');
            return self::SUCCESS;
        }

        $count = 0;
        foreach ($contributions as $c) {
            $label = $c->phone . ' (' . $c->name . ')';

            if ($dryRun) {
                $this->line("[dry-run] Would send reminder to {$label} for contribution due on {$c->due_date}");
            } else {
                SendOverdueReminderSmsJob::dispatch(
                    $c->member_id,
                    $c->contribution_id,
                    $c->phone,
                    (float) $c->amount,
                    $c->due_date,
                    $c->year,
                    $c->month
                );
                $this->line("Dispatched reminder to {$label}");
            }
            $count++;
        }

        $this->info("Processed {$count} reminder(s).");

        return self::SUCCESS;
    }
}
