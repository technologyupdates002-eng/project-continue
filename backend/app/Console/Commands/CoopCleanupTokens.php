<?php

namespace App\Console\Commands;

use App\Models\CoopToken;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Redis;

class CoopCleanupTokens extends Command
{
    protected $signature = 'coop:cleanup-tokens';
    protected $description = 'Remove expired Co-operative Bank tokens from cache';

    public function handle(): int
    {
        $expired = CoopToken::where('expires_at', '<', now())->delete();

        $redisKey = 'coop_bank_token:' . config('coop_bank.environment', 'sandbox');
        try {
            $cached = Redis::get($redisKey);
            if ($cached) {
                $data = json_decode($cached, true);
                if (isset($data['expires_at']) && $data['expires_at'] < time()) {
                    Redis::del($redisKey);
                }
            }
        } catch (\Throwable $e) {
            // Redis not available
        }

        $this->info("Cleaned up {$expired} expired token(s)");
        return 0;
    }
}
