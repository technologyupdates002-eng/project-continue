<?php

namespace App\Listeners;

use App\Events\NewsPublished;
use App\Jobs\SendNewsSmsJob;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\DB;

class SendNewsSmsNotification implements ShouldQueue
{
    public function handle(NewsPublished $event): void
    {
        $news = $event->news;

        if (! in_array($news->status ?? null, ['published', 'active'], true)) {
            return;
        }

        $members = DB::table('members')
            ->where('is_active', 1)
            ->whereNotNull('phone')
            ->where('phone', '!=', '')
            ->get(['id', 'phone']);

        foreach ($members as $member) {
            SendNewsSmsJob::dispatch(
                $member->id,
                $news->id,
                $member->phone,
                $news->title,
                $news->content
            );
        }
    }
}
