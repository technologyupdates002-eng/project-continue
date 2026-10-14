<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(\App\Services\RedisLockService::class);
        $this->app->singleton(\App\Services\Cache\CacheService::class);
    }

    public function boot(): void
    {
        RateLimiter::for('login', function (Request $request) {
            return Limit::perMinute(10)->by($request->ip());
        });

        RateLimiter::for('api', function (Request $request) {
            $identity = $request->attributes->get('identity');
            $subject = is_array($identity)
                ? ($identity['user_id'] ?? $identity['member_id'] ?? null)
                : null;

            return Limit::perMinute(240)->by((string) ($subject ?: $request->ip()));
        });

        Event::listen(
            \App\Events\NewsPublished::class,
            \App\Listeners\SendNewsSmsNotification::class
        );
    }
}
