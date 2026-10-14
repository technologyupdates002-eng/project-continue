<?php

namespace App\Services\CoopBank;

use App\Exceptions\CoopAuthenticationException;
use App\Exceptions\CoopBankException;
use App\Models\CoopToken;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;

class CoopBankClient
{
    protected string $environment;
    protected string $baseUrl;
    protected string $tokenUrl;
    protected ?string $consumerKey;
    protected ?string $consumerSecret;
    protected int $refreshBuffer;
    protected int $timeout;
    protected int $connectTimeout;
    protected ?array $cachedToken = null;

    public function __construct()
    {
        $this->environment = config('coop_bank.environment', 'sandbox');
        $this->baseUrl = rtrim((string) config('coop_bank.base_url'), '/');
        $this->tokenUrl = (string) config('coop_bank.token_url');
        $this->consumerKey = config('coop_bank.consumer_key');
        $this->consumerSecret = config('coop_bank.consumer_secret');
        $this->refreshBuffer = (int) config('coop_bank.token_refresh_buffer', 60);
        $this->timeout = (int) config('coop_bank.timeout', 30);
        $this->connectTimeout = (int) config('coop_bank.connect_timeout', 10);
    }

    public function getAccessToken(): string
    {
        $redisKey = "coop_bank_token:{$this->environment}";

        if (!config('coop_bank.enable_real_calls', false)) {
            Log::warning('CoopBank: real calls disabled, returning mock token');
            return 'mock_token_for_testing';
        }

        try {
            $cached = Redis::get($redisKey);
            if ($cached) {
                $data = json_decode($cached, true);
                if (is_array($data) && ($data['expires_at'] ?? 0) > time() + $this->refreshBuffer) {
                    return $data['access_token'];
                }
            }
        } catch (\Throwable $e) {
            Log::warning('CoopBank: Redis token cache read failed', ['error' => $e->getMessage()]);
        }

        $cached = CoopToken::where('environment', $this->environment)
            ->where('expires_at', '>', now()->addSeconds($this->refreshBuffer))
            ->latest('created_at')
            ->first();

        if ($cached) {
            $data = json_encode([
                'access_token' => $cached->token_value,
                'expires_at' => strtotime($cached->expires_at),
            ]);
            try {
                Redis::setex($redisKey, max(0, strtotime($cached->expires_at) - time()), $data);
            } catch (\Throwable $e) {
                // Redis not available, skip
            }
            return $cached->token_value;
        }

        $token = $this->requestToken();

        CoopToken::create([
            'id' => \App\Support\Uuid::v4(),
            'environment' => $this->environment,
            'token_value' => $token['access_token'],
            'refresh_token' => $token['refresh_token'] ?? null,
            'expires_at' => now()->addSeconds($token['expires_in']),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        try {
            Redis::setex($redisKey, max(0, $token['expires_in'] - $this->refreshBuffer), json_encode([
                'access_token' => $token['access_token'],
                'expires_at' => time() + $token['expires_in'],
            ]));
        } catch (\Throwable $e) {
            // Redis not available, skip
        }

        return $token['access_token'];
    }

    protected function requestToken(): array
    {
        if (!$this->consumerKey || !$this->consumerSecret) {
            throw new CoopAuthenticationException(
                'COOP_CONSUMER_KEY and COOP_CONSUMER_SECRET must be configured',
                'credentials_missing'
            );
        }

        $authHeader = 'Basic ' . base64_encode($this->consumerKey . ':' . $this->consumerSecret);

        Log::info('CoopBank: requesting token', [
            'environment' => $this->environment,
            'token_url' => $this->tokenUrl,
            'auth_header_prefix' => substr($authHeader, 0, 20) . '...',
        ]);

        $response = Http::timeout($this->timeout)
            ->connectTimeout($this->connectTimeout)
            ->withHeaders([
                'Authorization' => $authHeader,
                'Content-Type' => 'application/x-www-form-urlencoded',
            ])
            ->asForm()
            ->post($this->tokenUrl, ['grant_type' => 'client_credentials']);

        if ($response->failed()) {
            $body = $response->json();
            Log::warning('CoopBank: token request failed', [
                'status' => $response->status(),
                'body' => $body,
            ]);
            throw new CoopAuthenticationException(
                'Failed to obtain Co-op access token: ' . ($body['error_description'] ?? $response->body()),
                'token_error',
                null,
                $response->status() === 401
            );
        }

        $body = $response->json();

        return [
            'access_token' => $body['access_token'],
            'expires_in' => (int) ($body['expires_in'] ?? 3600),
            'refresh_token' => $body['refresh_token'] ?? null,
        ];
    }

    public function request(string $method, string $path, ?array $body = null, ?array $headers = null): array
    {
        $token = $this->getAccessToken();
        $url = $this->baseUrl . $path;

        $defaultHeaders = [
            'Authorization' => "Bearer {$token}",
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
        ];

        if (is_array($headers)) {
            $defaultHeaders = array_merge($defaultHeaders, $headers);
        }

        $attempts = 0;
        $maxAttempts = strtoupper($method) === 'POST'
            && str_contains($path, '/FundsTransfer/External/A2M/Mpesa_v2/2.0.0')
                ? 1
                : 3;
        $lastException = null;

        while ($attempts < $maxAttempts) {
            $attempts++;

            try {
                $startTime = microtime(true);

                Log::info('CoopBank: API request', [
                    'environment' => $this->environment,
                    'method' => strtoupper($method),
                    'url' => $url,
                    'attempt' => $attempts,
                ]);

                $httpResponse = Http::timeout($this->timeout)
                    ->connectTimeout($this->connectTimeout)
                    ->withHeaders($defaultHeaders)
                    ->{strtolower($method)}($url, $this->prepareBody($body, $method));

                $durationMs = (int) round((microtime(true) - $startTime) * 1000);
                $data = $httpResponse->json() ?? [];
                $statusCode = $httpResponse->status();

                Log::info('CoopBank: API response', [
                    'environment' => $this->environment,
                    'method' => strtoupper($method),
                    'url' => $url,
                    'status' => $statusCode,
                    'duration_ms' => $durationMs,
                    'success' => $httpResponse->successful(),
                ]);

                if ($statusCode === 401 && $attempts < $maxAttempts) {
                    $this->clearCachedToken();
                    $token = $this->getAccessToken();
                    $defaultHeaders['Authorization'] = "Bearer {$token}";
                    usleep(500000 * $attempts);
                    continue;
                }

                $this->logRequest($path, $method, $statusCode, $data, $durationMs);

                return [
                    'status' => $statusCode,
                    'body' => $data,
                    'success' => $httpResponse->successful(),
                    'duration_ms' => $durationMs,
                ];
            } catch (\Illuminate\Http\Client\ConnectionException $e) {
                $lastException = $e;
                Log::warning('CoopBank: connection error', [
                    'environment' => $this->environment,
                    'method' => strtoupper($method),
                    'url' => $url,
                    'attempt' => $attempts,
                    'error' => $e->getMessage(),
                ]);

                if ($attempts < $maxAttempts) {
                    usleep(500000 * $attempts);
                    continue;
                }

                throw new CoopBankException(
                    'Connection failed: ' . $e->getMessage(),
                    'connection_error',
                    null,
                    false,
                    $e
                );
            } catch (\Throwable $e) {
                $lastException = $e;
                Log::warning('CoopBank: unexpected error', [
                    'environment' => $this->environment,
                    'method' => strtoupper($method),
                    'url' => $url,
                    'attempt' => $attempts,
                    'error' => $e->getMessage(),
                    'class' => get_class($e),
                ]);

                if ($attempts < $maxAttempts) {
                    usleep(500000 * $attempts);
                    continue;
                }

                throw new CoopBankException(
                    'Request failed: ' . $e->getMessage(),
                    'request_error',
                    null,
                    false,
                    $e
                );
            }
        }

        throw new CoopBankException(
            'Max retries exceeded: ' . ($lastException?->getMessage() ?? 'unknown error'),
            'max_retries',
            null,
            false,
            $lastException
        );
    }

    protected function prepareBody(?array $body, string $method): ?array
    {
        if ($body === null || $method === 'GET') {
            return null;
        }
        return $body;
    }

    protected function clearCachedToken(): void
    {
        $redisKey = "coop_bank_token:{$this->environment}";
        try {
            Redis::del($redisKey);
        } catch (\Throwable $e) {
            // Redis not available
        }
        CoopToken::where('environment', $this->environment)->delete();
    }

    protected function logRequest(string $path, string $method, int $statusCode, array $response, ?int $durationMs = null, ?string $errorStage = null, ?string $exceptionClass = null): void
    {
        $operation = strtoupper($method) . ' ' . $path;
        $messageReference = $response['MessageReference'] ?? null;
        $bankStatus = $response['Status'] ?? $response['status'] ?? null;
        $bankErrorCode = $response['MessageCode'] ?? null;
        $bankErrorMessage = $response['MessageDescription'] ?? $response['error_description'] ?? null;

        $requestPayload = [];
        if (isset($response['_request_body'])) {
            $requestPayload = $response['_request_body'];
        }

        try {
            \App\Models\BankApiLog::create([
                'id' => \App\Support\Uuid::v4(),
                'provider' => 'coop_bank',
                'environment' => $this->environment,
                'operation' => substr($operation, 0, 128),
                'endpoint' => substr($path, 0, 255),
                'http_method' => strtoupper($method),
                'message_reference' => $messageReference,
                'correlation_id' => null,
                'request_payload_redacted' => json_encode($this->redactPayload($requestPayload)),
                'response_payload_redacted' => json_encode($this->redactPayload($response)),
                'http_status' => $statusCode,
                'bank_status' => $bankStatus ? substr($bankStatus, 0, 64) : null,
                'bank_error_code' => $bankErrorCode ? substr($bankErrorCode, 0, 64) : null,
                'bank_error_message' => $bankErrorMessage ? substr($bankErrorMessage, 0, 255) : null,
                'transaction_status' => $bankStatus ? substr($bankStatus, 0, 64) : null,
                'duration_ms' => $durationMs,
                'success' => $statusCode >= 200 && $statusCode < 300,
                'error_stage' => $errorStage,
                'exception_class' => $exceptionClass,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        } catch (\Throwable $e) {
            Log::warning('BankApiLog write failed', ['error' => $e->getMessage()]);
        }

        Log::info('CoopBank API request', [
            'path' => $path,
            'method' => $method,
            'status' => $statusCode,
            'duration_ms' => $durationMs,
        ]);
    }

    protected function redactPayload(array $payload): array
    {
        $redacted = $payload;
        unset($redacted['Authorization']);
        unset($redacted['access_token']);
        unset($redacted['refresh_token']);
        unset($redacted['client_secret']);
        unset($redacted['consumer_secret']);
        return $redacted;
    }

    public function baseUrl(): string
    {
        return $this->baseUrl;
    }

    public function environment(): string
    {
        return $this->environment;
    }

    public function getConnectTimeout(): int
    {
        return $this->connectTimeout;
    }

    public function getTimeout(): int
    {
        return $this->timeout;
    }
}
