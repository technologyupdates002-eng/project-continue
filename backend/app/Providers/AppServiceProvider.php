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

        // Any money change refreshes the saved dashboard figures immediately.
        foreach ([
            \App\Models\Payment::class, \App\Models\Contribution::class, \App\Models\WalletTransaction::class,
            \App\Models\UnmatchedPayment::class, \App\Models\Penalty::class, \App\Models\PenaltyPayment::class,
            \App\Models\PenaltyPaymentRecord::class, \App\Models\DonationPaymentRecord::class,
            \App\Models\OperationalPaymentRecord::class, \App\Models\Expense::class, \App\Models\Payout::class,
            \App\Models\OperationalWithdrawal::class, \App\Models\PenaltyWithdrawal::class,
            \App\Models\DonationWithdrawal::class, \App\Models\BankTransaction::class, \App\Models\Member::class,
        ] as $model) {
            if (class_exists($model)) {
                $model::observe(\App\Observers\FinancialChangeObserver::class);
            }
        }

        Event::listen(
            \App\Events\NewsPublished::class,
            \App\Listeners\SendNewsSmsNotification::class
        );
    }
}
