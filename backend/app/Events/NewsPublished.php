<?php

namespace App\Events;

use App\Models\News;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class NewsPublished
{
    use Dispatchable, SerializesModels;

    public function __construct(public News $news) {}
}
