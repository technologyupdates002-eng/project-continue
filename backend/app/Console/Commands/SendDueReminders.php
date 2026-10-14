<?php

namespace App\Console\Commands;

use App\Jobs\SendDueReminderSms;
use App\Models\WelfareSetting;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class SendDueReminders extends Command
{
    protected $signature = 'reminders:due {--dry-run : Show what would be sent without sending}';

    protected $description = 'Send SMS reminders for contributions due in 3 days (3-day rule)';

    protected int $reminderDays = 3;

    public function handle(): int
    {
        $setting = WelfareSetting::current();
        $dueDay = (int) ($setting->contribution_due_day ?? 5);

        $today = Carbon::today();
        $currentMonth = $today->month;
        $currentYear = $today->year;

        $dueDateThisMonth = Carbon::create($currentYear, $currentMonth, $dueDay);
        $dueDateNextMonth = Carbon::create($currentYear, $currentMonth, $dueDay)->addMonth();

        $targetDueDate = $today->greaterThanOrEqualTo($dueDateThisMonth->copy()->subDays($this->reminderDays))
            ? $dueDateNextMonth
            : $dueDateThisMonth;

        $sendDate = $targetDueDate->copy()->subDays($this->reminderDays);
        $sendDateString = $sendDate->toDateString();
        $dueDateString = $targetDueDate->toDateString();

        if ($today->toDateString() !== $sendDateString) {
            $this->info("Today is not the reminder send date. Reminders are scheduled for {$sendDateString} (due date: {$dueDateString}).");

            return self::SUCCESS;
        }

        $dryRun = $this->option('dry-run');

        $this->info("Looking for contributions due on {$dueDateString} (day {$dueDay} of month)...");
        $this->info("Sending reminders {$this->reminderDays} days before due date.");

        $contributions = DB::table('contributions as c')
            ->join('members as m', 'c.member_id', '=', 'm.id')
            ->whereDate('c.due_date', $dueDateString)
            ->where('c.status', '!=', 'paid')
            ->where('m.is_active', 1)
            ->whereNotNull('m.phone')
            ->where('m.phone', '!=', '')
            ->select(
                'c.id as contribution_id',
                'c.amount',
                'c.due_date',
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
                $this->line("[dry-run] Would send reminder to {$label}");
            } else {
                SendDueReminderSms::dispatch(
                    $c->member_id,
                    $c->contribution_id,
                    $c->phone,
                    (float) $c->amount,
                    $c->due_date
                );
                $this->line("Dispatched reminder to {$label}");
            }
            $count++;
        }

        $this->info("Processed {$count} reminder(s).");

        return self::SUCCESS;
    }
}
