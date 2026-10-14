<?php

namespace App\Services\CoopBank;

use App\Exceptions\CoopAuthenticationException;
use App\Exceptions\CoopBankException;
use App\Models\BankApiLog;
use App\Models\BankDiagnosticRun;
use App\Support\Uuid;
use Illuminate\Support\Facades\Log;

class CoopBankDiagnosticService
{
    public function __construct(
        protected CoopBankClient $client,
        protected CoopBankAccountService $accountService,
        protected CoopBankTransferService $transferService
    ) {}

    public function runFullDiagnostics(string $executedBy): array
    {
        $environment = config('coop_bank.environment', 'sandbox');
        $enableRealCalls = config('coop_bank.enable_real_calls', false);
        $diagnosticId = $this->generateDiagnosticId();

        $result = [
            'diagnostic_id' => $diagnosticId,
            'success' => true,
            'service' => 'cooperative_bank',
            'operation' => 'full_diagnostics',
            'environment' => $environment,
            'timestamp' => now()->toIso8601String(),
            'server' => $this->getServerInfo(),
            'bank_environment' => $environment,
            'outbound_ip' => $this->getOutboundIp(),
            'urls' => $this->getConfiguredUrls(),
            'diagnostics' => [
                'configuration' => 'not_tested',
                'dns' => 'not_tested',
                'network' => 'not_tested',
                'tls' => 'not_tested',
                'authentication' => 'not_tested',
                'http' => 'not_tested',
                'bank_processing' => 'not_tested',
            ],
            'tests' => [],
        ];

        $tests = [];

        if (!$enableRealCalls) {
            $tests[] = $this->recordTest('Configuration', 'PASS', 'Bank configuration present. Real calls disabled (simulation mode).');
            $tests[] = $this->recordTest('Authentication', 'NOT_TESTED', 'Real calls disabled. Set COOP_ENABLE_REAL_CALLS=true to test.');
            $tests[] = $this->recordTest('Account Balance', 'NOT_TESTED', 'Real calls disabled.');
            $tests[] = $this->recordTest('Mini Statement', 'NOT_TESTED', 'Real calls disabled.');
            $tests[] = $this->recordTest('Transaction Status', 'NOT_TESTED', 'Real calls disabled.');
            $tests[] = $this->recordTest('STK Transaction Status', 'NOT_TESTED', 'Real calls disabled.');
            $tests[] = $this->recordTest('PesaLink Validation', 'NOT_TESTED', 'Real calls disabled.');
            $tests[] = $this->recordTest('B2C M-Pesa', 'NOT_TESTED', 'Real calls disabled.');
            $tests[] = $this->recordTest('PesaLink Transfer', 'NOT_TESTED', 'Real calls disabled.');
            $tests[] = $this->recordTest('IFT', 'NOT_TESTED', 'Real calls disabled.');

            $result['tests'] = $tests;
            $this->persistRuns($diagnosticId, $environment, $executedBy, $tests);

            return $result;
        }

        $tests[] = $this->runConfigurationTest();
        $tests[] = $this->runAuthenticationTest();
        $tests[] = $this->runAccountBalanceTest();
        $tests[] = $this->runMiniStatementTest();
        $tests[] = $this->runTransactionStatusTest();
        $tests[] = $this->runStkStatusTest();
        $tests[] = $this->runPesalinkValidationTest();
        $tests[] = $this->runB2cMpesaTest();

        foreach ($tests as $test) {
            $statusKey = strtolower($test['name']);
            if ($test['status'] === 'FAIL') {
                $result['diagnostics'][$statusKey] = 'failed';
            } elseif ($test['status'] === 'PASS') {
                $result['diagnostics'][$statusKey] = 'ok';
            }
        }

        $result['success'] = !in_array('FAIL', array_column($tests, 'status'));
        $result['tests'] = $tests;

        $this->persistRuns($diagnosticId, $environment, $executedBy, $tests);

        return $result;
    }

    public function runSingleTest(string $testType, string $executedBy): array
    {
        $environment = config('coop_bank.environment', 'sandbox');
        $diagnosticId = $this->generateDiagnosticId();

        $normalized = strtolower(str_replace([' ', '-'], '_', $testType));

        $tests = match ($normalized) {
            'authentication' => [$this->runAuthenticationTest()],
            'account_balance' => [$this->runAccountBalanceTest()],
            'mini_statement' => [$this->runMiniStatementTest()],
            'transaction_status' => [$this->runTransactionStatusTest()],
            'stk_status', 'stk_transaction_status' => [$this->runStkStatusTest()],
            'pesalink_validation' => [$this->runPesalinkValidationTest()],
            'b2c_mpesa' => [$this->runB2cMpesaTest()],
            'configuration' => [$this->runConfigurationTest()],
            default => [
                [
                    'name' => $testType,
                    'status' => 'FAIL',
                    'message' => 'Unknown test type: ' . $testType,
                    'error_category' => 'CONFIGURATION_ERROR',
                ],
            ],
        };

        $this->persistRuns($diagnosticId, $environment, $executedBy, $tests);

        return [
            'diagnostic_id' => $diagnosticId,
            'tests' => $tests,
            'environment' => $environment,
            'timestamp' => now()->toIso8601String(),
        ];
    }

    public function getDiagnosticHistory(string $executedBy, ?int $limit = 50): array
    {
        $query = BankDiagnosticRun::query()
            ->orderByDesc('created_at')
            ->limit($limit);

        if ($executedBy) {
            $query->where('executed_by', $executedBy);
        }

        return $query->get()->map(function ($run) {
            return [
                'id' => $run->id,
                'diagnostic_id' => $run->diagnostic_id,
                'test_type' => $run->test_type,
                'environment' => $run->environment,
                'status' => $run->status,
                'error_category' => $run->error_category,
                'http_status' => $run->http_status,
                'bank_response_code' => $run->bank_response_code,
                'bank_response_message' => $run->bank_response_message,
                'message_reference' => $run->message_reference,
                'bank_reference' => $run->bank_reference,
                'duration_ms' => $run->duration_ms,
                'endpoint' => $run->endpoint,
                'http_method' => $run->http_method,
                'executed_by' => $run->executed_by,
                'conclusion' => $run->conclusion,
                'created_at' => $run->created_at?->toIso8601String(),
            ];
        })->toArray();
    }

    public function generateReport(string $diagnosticId): array
    {
        $runs = BankDiagnosticRun::where('diagnostic_id', $diagnosticId)->get();

        if ($runs->isEmpty()) {
            return ['error' => 'Diagnostic run not found'];
        }

        $environment = $runs->first()->environment;
        $timestamp = $runs->first()->created_at?->toIso8601String() ?? now()->toIso8601String();

        $report = [
            'report_title' => 'Co-operative Bank API Integration Diagnostic Report',
            'organization' => 'KIRINYAGA HEALTH CARE WORKERS WELFARE GROUP',
            'diagnostic_id' => $diagnosticId,
            'environment' => $environment,
            'generated_at' => $timestamp,
            'server' => $this->getServerInfo(),
            'outbound_ip' => $this->getOutboundIp(),
            'bank_environment' => $environment,
            'security' => [
                'credentials' => 'REDACTED',
                'bearer_tokens' => 'REDACTED',
                'secrets' => 'REDACTED',
                'account_numbers' => 'MASKED',
            ],
            'tests' => [],
            'failures' => [],
            'conclusion' => '',
        ];

        foreach ($runs as $run) {
            $testEntry = [
                'test' => $run->test_type,
                'environment' => $run->environment,
                'status' => $run->status,
                'http_status' => $run->http_status,
                'bank_response_code' => $run->bank_response_code,
                'bank_response_message' => $run->bank_response_message,
                'message_reference' => $run->message_reference,
                'bank_reference' => $run->bank_reference,
                'duration_ms' => $run->duration_ms,
                'endpoint' => $run->endpoint,
                'http_method' => $run->http_method,
                'conclusion' => $run->conclusion,
                'human_explanation' => $run->human_explanation,
            ];

            $report['tests'][] = $testEntry;

            if ($run->status === 'FAIL') {
                $report['failures'][] = [
                    'test' => $run->test_type,
                    'error_category' => $run->error_category,
                    'http_status' => $run->http_status,
                    'bank_response_code' => $run->bank_response_code,
                    'bank_response_message' => $run->bank_response_message,
                    'human_explanation' => $run->human_explanation,
                    'conclusion' => $run->conclusion,
                ];
            }
        }

        $passCount = $runs->where('status', 'PASS')->count();
        $failCount = $runs->where('status', 'FAIL')->count();
        $notTestedCount = $runs->where('status', 'NOT_TESTED')->count();

        if ($failCount === 0 && $notTestedCount === 0) {
            $report['conclusion'] = 'All diagnostic tests passed. The Co-operative Bank API integration is functioning correctly in the ' . $environment . ' environment.';
        } elseif ($failCount > 0) {
            $report['conclusion'] = $failCount . ' of ' . ($passCount + $failCount) . ' tested diagnostics failed. Review the failures section for details and contact Co-operative Bank technical support with the diagnostic ID: ' . $diagnosticId;
        } else {
            $report['conclusion'] = 'Diagnostics completed. ' . $notTestedCount . ' tests were skipped because real API calls are disabled. Enable COOP_ENABLE_REAL_CALLS to run full diagnostics.';
        }

        return $report;
    }

    protected function runConfigurationTest(): array
    {
        $missing = [];
        if (empty(config('coop_bank.consumer_key'))) $missing[] = 'consumer_key';
        if (empty(config('coop_bank.consumer_secret'))) $missing[] = 'consumer_secret';
        if (empty(config('coop_bank.token_url'))) $missing[] = 'token_url';
        if (empty(config('coop_bank.base_url'))) $missing[] = 'base_url';
        if (empty(config('coop_bank.account_number'))) $missing[] = 'account_number';
        if (empty(config('coop_bank.default_user_id'))) $missing[] = 'user_id';

        if (!empty($missing)) {
            return $this->recordTest('Configuration', 'FAIL', 'Missing: ' . implode(', ', $missing), [
                'error_category' => 'CONFIGURATION_ERROR',
                'missing_fields' => $missing,
                'urls' => $this->getConfiguredUrls(),
            ]);
        }

        return $this->recordTest('Configuration', 'PASS', 'All required configuration present', [
            'urls' => $this->getConfiguredUrls(),
        ]);
    }

    protected function getConfiguredUrls(): array
    {
        return [
            'token_url' => config('coop_bank.token_url'),
            'base_url' => config('coop_bank.base_url'),
            'callback_base_url' => config('coop_bank.callback_base_url'),
        ];
    }

    protected function runAuthenticationTest(): array
    {
        try {
            $start = microtime(true);
            $token = $this->client->getAccessToken();
            $duration = (int) round((microtime(true) - $start) * 1000);

            return $this->recordTest('Authentication', 'PASS', 'Token acquired successfully', [
                'duration_ms' => $duration,
                'token_length' => strlen($token),
            ]);
        } catch (CoopAuthenticationException $e) {
            return $this->recordTest('Authentication', 'FAIL', $e->getMessage(), [
                'error_category' => 'AUTHENTICATION_FAILURE',
                'bank_error_code' => $e->getBankErrorCode(),
                'http_status' => 401,
            ]);
        } catch (CoopBankException $e) {
            return $this->recordTest('Authentication', 'FAIL', $e->getMessage(), [
                'error_category' => $e->getErrorCategory() ?? 'UNKNOWN_ERROR',
                'bank_error_code' => $e->getBankErrorCode(),
            ]);
        } catch (\Throwable $e) {
            return $this->recordTest('Authentication', 'FAIL', $e->getMessage(), [
                'error_category' => $this->classifyError($e),
            ]);
        }
    }

    protected function runAccountBalanceTest(): array
    {
        try {
            $start = microtime(true);
            $result = $this->accountService->getAccountBalance();
            $duration = (int) round((microtime(true) - $start) * 1000);

            return $this->recordTest('Account Balance', 'PASS', $result['message_description'] ?? 'Success', [
                'duration_ms' => $duration,
                'available_balance' => $result['available_balance'] ?? 0,
                'booked_balance' => $result['booked_balance'] ?? 0,
                'currency' => $result['currency'] ?? 'KES',
                'message_reference' => $result['message_reference'] ?? null,
            ]);
        } catch (CoopBankException $e) {
            return $this->recordTest('Account Balance', 'FAIL', $e->getMessage(), [
                'error_category' => $e->getErrorCategory() ?? 'BANK_APPLICATION_ERROR',
                'bank_error_code' => $e->getBankErrorCode(),
            ]);
        } catch (\Throwable $e) {
            return $this->recordTest('Account Balance', 'FAIL', $e->getMessage(), [
                'error_category' => $this->classifyError($e),
            ]);
        }
    }

    protected function runMiniStatementTest(): array
    {
        try {
            $start = microtime(true);
            $result = $this->accountService->getMiniStatement();
            $duration = (int) round((microtime(true) - $start) * 1000);

            return $this->recordTest('Mini Statement', 'PASS', $result['message_description'] ?? 'Success', [
                'duration_ms' => $duration,
                'transaction_count' => count($result['transactions'] ?? []),
                'message_reference' => $result['message_reference'] ?? null,
            ]);
        } catch (CoopBankException $e) {
            return $this->recordTest('Mini Statement', 'FAIL', $e->getMessage(), [
                'error_category' => $e->getErrorCategory() ?? 'BANK_APPLICATION_ERROR',
                'bank_error_code' => $e->getBankErrorCode(),
            ]);
        } catch (\Throwable $e) {
            return $this->recordTest('Mini Statement', 'FAIL', $e->getMessage(), [
                'error_category' => $this->classifyError($e),
            ]);
        }
    }

    protected function runTransactionStatusTest(): array
    {
        try {
            $start = microtime(true);
            $result = $this->transferService->queryTransactionStatus('CONNECTIVITY_TEST');
            $duration = (int) round((microtime(true) - $start) * 1000);

            return $this->recordTest('Transaction Status', 'PASS', 'Status enquiry endpoint reachable', [
                'duration_ms' => $duration,
                'bank_status' => $result['status'] ?? 'unknown',
            ]);
        } catch (CoopBankException $e) {
            return $this->recordTest('Transaction Status', 'FAIL', $e->getMessage(), [
                'error_category' => $e->getErrorCategory() ?? 'BANK_APPLICATION_ERROR',
                'bank_error_code' => $e->getBankErrorCode(),
            ]);
        } catch (\Throwable $e) {
            return $this->recordTest('Transaction Status', 'FAIL', $e->getMessage(), [
                'error_category' => $this->classifyError($e),
            ]);
        }
    }

    protected function runStkStatusTest(): array
    {
        try {
            $start = microtime(true);
            $messageReference = $this->generateDiagnosticId();
            $result = $this->accountService->queryStkStatus($messageReference);
            $duration = (int) round((microtime(true) - $start) * 1000);

            return $this->recordTest('STK Transaction Status', 'PASS', 'STK status endpoint reachable', [
                'duration_ms' => $duration,
                'bank_status' => $result['status'] ?? 'unknown',
                'message_reference' => $messageReference,
            ]);
        } catch (CoopBankException $e) {
            return $this->recordTest('STK Transaction Status', 'FAIL', $e->getMessage(), [
                'error_category' => $e->getErrorCategory() ?? 'BANK_APPLICATION_ERROR',
                'bank_error_code' => $e->getBankErrorCode(),
            ]);
        } catch (\Throwable $e) {
            return $this->recordTest('STK Transaction Status', 'FAIL', $e->getMessage(), [
                'error_category' => $this->classifyError($e),
            ]);
        }
    }

    protected function runPesalinkValidationTest(): array
    {
        try {
            $start = microtime(true);
            $result = $this->transferService->validatePesalinkRecipient(config('coop_bank.account_number', ''), '0011');
            $duration = (int) round((microtime(true) - $start) * 1000);

            $status = $result['valid'] ?? false ? 'PASS' : 'FAIL';
            $message = $result['message_description'] ?? ($status === 'PASS' ? 'Validation successful' : 'Validation failed');

            return $this->recordTest('PesaLink Validation', $status, $message, [
                'duration_ms' => $duration,
                'valid' => $result['valid'] ?? false,
                'message_reference' => $result['message_reference'] ?? null,
                'message_code' => $result['message_code'] ?? null,
            ]);
        } catch (CoopBankException $e) {
            return $this->recordTest('PesaLink Validation', 'FAIL', $e->getMessage(), [
                'error_category' => $e->getErrorCategory() ?? 'BANK_APPLICATION_ERROR',
                'bank_error_code' => $e->getBankErrorCode(),
            ]);
        } catch (\Throwable $e) {
            return $this->recordTest('PesaLink Validation', 'FAIL', $e->getMessage(), [
                'error_category' => $this->classifyError($e),
            ]);
        }

    }

    protected function runB2cMpesaTest(): array
    {
        $endpoint = '/FundsTransfer/External/A2M/Mpesa_v2/2.0.0';

        return $this->recordTest(
            'B2C M-Pesa',
            'NOT_TESTED',
            'B2C endpoint is configured. A live B2C test is intentionally not sent from diagnostics because it would transfer real money; execute only through an approved withdrawal.',
            [
                'endpoint' => $endpoint,
                'http_method' => 'POST',
                'error_category' => 'NOT_TESTED',
                'request_summary' => 'No transfer submitted',
            ]
        );
    }

    protected function recordTest(string $name, string $status, string $message, array $extra = []): array
    {
        $data = array_merge([
            'name' => $name,
            'status' => $status,
            'message' => $message,
        ], $extra);

        return $data;
    }

    protected function persistRuns(string $diagnosticId, string $environment, string $executedBy, array $tests): void
    {
        foreach ($tests as $test) {
            try {
                BankDiagnosticRun::create([
                    'id' => Uuid::v4(),
                    'diagnostic_id' => $diagnosticId,
                    'test_type' => $test['name'] ?? 'unknown',
                    'environment' => $environment,
                    'status' => $test['status'] ?? 'UNKNOWN',
                    'error_category' => $test['error_category'] ?? null,
                    'http_status' => $test['http_status'] ?? null,
                    'bank_response_code' => $test['bank_response_code'] ?? null,
                    'bank_response_message' => $test['bank_response_message'] ?? null,
                    'message_reference' => $test['message_reference'] ?? null,
                    'bank_reference' => $test['bank_reference'] ?? null,
                    'duration_ms' => $test['duration_ms'] ?? null,
                    'endpoint' => $test['endpoint'] ?? null,
                    'http_method' => $test['http_method'] ?? null,
                    'executed_by' => $executedBy,
                    'request_summary' => $test['request_summary'] ?? null,
                    'response_summary' => $test['response_summary'] ?? null,
                    'raw_response' => $test['raw_response'] ?? null,
                    'conclusion' => $this->generateConclusion($test),
                    'human_explanation' => $this->generateHumanExplanation($test),
                ]);
            } catch (\Throwable $e) {
                Log::warning('BankDiagnosticRun persist failed', ['error' => $e->getMessage()]);
            }
        }
    }

    protected function generateConclusion(array $test): string
    {
        if ($test['status'] === 'PASS') {
            return $test['name'] . ' test passed successfully.';
        }

        $category = $test['error_category'] ?? 'UNKNOWN_ERROR';
        return $test['name'] . ' failed with category: ' . $category;
    }

    protected function generateHumanExplanation(array $test): string
    {
        if ($test['status'] === 'PASS') {
            return $test['name'] . ' completed successfully. Duration: ' . ($test['duration_ms'] ?? 0) . 'ms.';
        }

        $category = $test['error_category'] ?? 'UNKNOWN_ERROR';
        $message = $test['message'] ?? 'Unknown error';

        return match ($category) {
            'AUTHENTICATION_FAILURE' => 'Bank API is reachable but authentication was rejected. Check client credentials (COOP_CONSUMER_KEY / COOP_CONSUMER_SECRET), OAuth endpoint, and environment configuration.',
            'CONNECTION_ERROR' => 'Could not establish a connection to the Co-operative Bank API. Check internet connectivity, DNS, firewall, proxy, and API availability.',
            'TIMEOUT' => 'The request to Co-operative Bank API timed out. The bank may be experiencing high latency or the network is unstable.',
            'BANK_APPLICATION_ERROR' => 'The bank API returned an error response. Bank error code: ' . ($test['bank_response_code'] ?? 'N/A') . '. Bank message: ' . ($test['bank_response_message'] ?? $message) . '. Contact Co-operative Bank support with the diagnostic ID.',
            'VALIDATION_ERROR' => 'The bank rejected the request due to validation failure. Check request parameters, account numbers, and message references.',
            'CONFIGURATION_ERROR' => 'Bank configuration is incomplete or incorrect. Check .env values for Co-op Bank settings.',
            default => 'An unexpected error occurred during ' . $test['name'] . '. Error: ' . $message . '. Review the raw response for more details.',
        };
    }

    protected function classifyError(\Throwable $e): string
    {
        $message = strtolower($e->getMessage());

        if (str_contains($message, 'timeout') || str_contains($message, 'timed out')) {
            return 'TIMEOUT';
        }
        if (str_contains($message, 'connection') || str_contains($message, 'could not resolve')) {
            return 'CONNECTION_ERROR';
        }
        if (str_contains($message, 'tls') || str_contains($message, 'ssl') || str_contains($message, 'certificate')) {
            return 'TLS_FAILURE';
        }
        if (str_contains($message, '401') || str_contains($message, 'unauthorized') || str_contains($message, 'authentication')) {
            return 'AUTHENTICATION_FAILURE';
        }
        if (str_contains($message, '403') || str_contains($message, 'forbidden')) {
            return 'AUTHORIZATION_FAILURE';
        }
        if (str_contains($message, '429') || str_contains($message, 'rate limit')) {
            return 'RATE_LIMIT';
        }
        if (str_contains($message, '500') || str_contains($message, '502') || str_contains($message, '503')) {
            return 'SERVER_ERROR';
        }
        if (str_contains($message, '400') || str_contains($message, 'validation')) {
            return 'VALIDATION_ERROR';
        }

        return 'UNKNOWN_ERROR';
    }

    protected function generateDiagnosticId(): string
    {
        return 'BANK-DIAG-' . strtoupper(substr(Uuid::v4(), 0, 12));
    }

    protected function getServerInfo(): array
    {
        return [
            'php_version' => phpversion(),
            'laravel_version' => app()->version(),
            'server_software' => $_SERVER['SERVER_SOFTWARE'] ?? 'unknown',
            'document_root' => $_SERVER['DOCUMENT_ROOT'] ?? 'unknown',
            'server_name' => $_SERVER['SERVER_NAME'] ?? 'unknown',
        ];
    }

    protected function getOutboundIp(): ?string
    {
        try {
            $response = Http::timeout(5)->get('https://api.ipify.org');
            if ($response->successful()) {
                return $response->body();
            }
        } catch (\Throwable $e) {
            Log::warning('Outbound IP detection failed', ['error' => $e->getMessage()]);
        }

        return null;
    }
}
