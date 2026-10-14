<?php

namespace App\Services;

use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\DB;

class QueueManagementService
{
    public function overview(): array
    {
        $connection = config('queue.default');

        return [
            'connection' => $connection,
            'driver' => config("queue.connections.{$connection}.driver", 'unknown'),
            'status' => 'configured',
        ];
    }

    public function failedJobs(): array
    {
        try {
            if (! DB::getSchemaBuilder()->hasTable('failed_jobs')) {
                return [
                    'count' => 0,
                    'jobs' => [],
                    'status' => 'not_configured',
                    'message' => 'No failed job table is configured for this queue driver.',
                ];
            }

            $jobs = DB::table('failed_jobs')->orderByDesc('failed_at')->get();

            $count = $jobs->count();

            return [
                'count' => $count,
                'jobs' => $jobs->map(function ($job) {
                    return [
                        'id' => $job->uuid,
                        'connection' => $job->connection,
                        'queue' => $job->queue,
                        'payload' => $this->safePayload($job->payload),
                        'exception' => $this->safeException($job->exception),
                        'failed_at' => $job->failed_at,
                    ];
                })->toArray(),
            ];
        } catch (\Throwable $e) {
            return [
                'count' => 0,
                'jobs' => [],
                'status' => 'error',
                'error' => $e->getMessage(),
            ];
        }
    }

    public function retryJob(string $jobId): array
    {
        try {
            $result = Queue::retry($jobId);

            return [
                'success' => true,
                'job_id' => $jobId,
                'result' => $result,
            ];
        } catch (\Throwable $e) {
            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    public function deleteFailedJob(string $jobId): array
    {
        try {
            Queue::forget($jobId);

            return [
                'success' => true,
                'job_id' => $jobId,
            ];
        } catch (\Throwable $e) {
            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    public function retryAllFailed(): array
    {
        try {
            Queue::retryAll();

            return [
                'success' => true,
                'message' => 'All failed jobs queued for retry.',
            ];
        } catch (\Throwable $e) {
            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    public function flushFailed(): array
    {
        try {
            Queue::flushFailedJobs();

            return [
                'success' => true,
                'message' => 'All failed jobs deleted.',
            ];
        } catch (\Throwable $e) {
            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    protected function safePayload(string $payload): string
    {
        $decoded = json_decode($payload, true);

        if (!is_array($decoded)) {
            return substr($payload, 0, 200);
        }

        unset($decoded['data']['command']);

        return substr(json_encode($decoded), 0, 500);
    }

    protected function safeException(string $exception): string
    {
        return substr($exception, 0, 500);
    }
}
