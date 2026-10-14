<?php

namespace App\Console\Commands;

use App\Jobs\SendNewsSmsJob;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class SendNewsSms extends Command
{
    protected $signature = 'news:send-sms {--dry-run : Show what would be sent without sending} {--news-id= : Send SMS for specific news ID}';

    protected $description = 'Send SMS notification for news to all active members';

    public function handle(): int
    {
        $dryRun = $this->option('dry-run');
        $newsId = $this->option('news-id');

        $query = DB::table('news')
            ->where('status', 'active')
            ->orderByDesc('created_at');

        if ($newsId) {
            $query->where('id', $newsId);
        }

        $newsItems = $query->get(['id', 'title', 'content', 'created_at']);

        if ($newsItems->isEmpty()) {
            $this->info('No active news found.');
            return self::SUCCESS;
        }

        $members = DB::table('members')
            ->where('is_active', 1)
            ->whereNotNull('phone')
            ->where('phone', '!=', '')
            ->get(['id', 'phone', 'name']);

        if ($members->isEmpty()) {
            $this->info('No active members with phone numbers found.');
            return self::SUCCESS;
        }

        $count = 0;
        foreach ($newsItems as $news) {
            $this->info("Processing news: {$news->title}");

            foreach ($members as $member) {
                $label = $member->phone . ' (' . $member->name . ')';

                if ($dryRun) {
                    $this->line("[dry-run] Would send news SMS to {$label}");
                } else {
                    SendNewsSmsJob::dispatch(
                        $member->id,
                        $news->id,
                        $member->phone,
                        $news->title,
                        $news->content
                    );
                    $this->line("Dispatched news SMS to {$label}");
                }
                $count++;
            }
        }

        $this->info("Processed {$count} SMS notification(s).");

        return self::SUCCESS;
    }
}
