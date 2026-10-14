<?php

namespace App\Console\Commands;

use App\Services\CoopBank\CoopBankClient;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class CoopTestConnectivity extends Command
{
    protected $signature = 'coop:test-connectivity';

    protected $description = 'Test Co-op Bank API connectivity from the production server';

    public function handle(CoopBankClient $client): int
    {
        $this->info('=== Co-op Bank Connectivity Test ===');
        $this->line('Timestamp: ' . now()->toIso8601String());
        $this->line('Environment: ' . (config('coop_bank.enable_real_calls') ? 'PRODUCTION (real calls)' : 'SIMULATION (mock mode)'));
        $this->line('Token URL: ' . config('coop_bank.token_url'));
        $this->line('Base URL: ' . config('coop_bank.base_url'));
        $this->line('');

        $sourceIp = $this->getSourceIp();
        $this->line('Source IP: ' . ($sourceIp ?? 'Unable to determine'));
        $this->line('Destination: ' . parse_url(config('coop_bank.token_url'), PHP_URL_HOST));
        $this->line('');

        try {
            $token = $client->getAccessToken();

            if ($token === 'mock_token_for_testing') {
                $this->warn('Mock token returned — COOP_ENABLE_REAL_CALLS is false.');
                $this->info('Co-op Bank is in simulation mode. Set COOP_ENABLE_REAL_CALLS=true for real connectivity test.');
                return 0;
            }

            $this->info('Token acquisition: SUCCESS');
            $this->line('Token length: ' . strlen($token) . ' characters');

            if ($this->getOutput()->isVerbose()) {
                $this->line('Token (masked): ' . substr($token, 0, 8) . '...' . substr($token, -8));
            }

            Log::info('CoopBank connectivity test passed', [
                'source_ip' => $sourceIp,
                'environment' => config('coop_bank.environment'),
            ]);

            $this->info('');
            $this->info('=== Connectivity test PASSED ===');
            $this->line('The production server can reach the Co-op Bank API.');
            return 0;
        } catch (\Throwable $e) {
            $this->error('Connection FAILED');
            $this->error('Error: ' . $e->getMessage());

            Log::error('CoopBank connectivity test failed', [
                'error' => $e->getMessage(),
                'source_ip' => $sourceIp,
                'token_url' => config('coop_bank.token_url'),
            ]);

            $this->info('');
            $this->info('=== Connectivity test FAILED ===');
            $this->line('Possible causes:');
            $this->line('1. Akamai WAF blocking the production server IP');
            $this->line('2. Co-op Bank credentials not configured correctly');
            $this->line('3. Network/firewall restrictions');
            $this->line('4. Token URL unreachable');

            return 1;
        }
    }

    protected function getSourceIp(): ?string
    {
        try {
            $response = @file_get_contents('https://api.ipify.org?format=json');
            if ($response) {
                $data = json_decode($response, true);
                return $data['ip'] ?? null;
            }
        } catch (\Throwable $e) {
        }
        return null;
    }
}
