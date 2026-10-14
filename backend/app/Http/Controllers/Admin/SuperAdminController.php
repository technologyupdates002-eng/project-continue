<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\SystemHealthService;
use App\Services\QueueManagementService;
use App\Services\CacheManagementService;
use App\Services\FinancialIntegrityService;
use App\Services\FinancialReadModel;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use App\Models\CoopTransferRequest;
use App\Models\Backup;

class SuperAdminController extends Controller
{
    public function __construct(
        protected SystemHealthService $health,
        protected QueueManagementService $queue,
        protected CacheManagementService $cache,
        protected FinancialIntegrityService $financial,
        protected FinancialReadModel $financialReadModel,
    ) {
    }

    public function systemHealth(): JsonResponse
    {
        return response()->json($this->health->overview());
    }

    public function queueOverview(): JsonResponse
    {
        return response()->json($this->queue->overview());
    }

    public function queueFailed(): JsonResponse
    {
        return response()->json($this->queue->failedJobs());
    }

    public function retryFailedJob(Request $request, string $jobId): JsonResponse
    {
        $result = $this->queue->retryJob($jobId);

        return response()->json($result, $result['success'] ? 200 : 422);
    }

    public function deleteFailedJob(Request $request, string $jobId): JsonResponse
    {
        $result = $this->queue->deleteFailedJob($jobId);

        return response()->json($result, $result['success'] ? 200 : 422);
    }

    public function retryAllFailedJobs(): JsonResponse
    {
        $result = $this->queue->retryAllFailed();

        return response()->json($result, $result['success'] ? 200 : 422);
    }

    public function flushFailedJobs(): JsonResponse
    {
        $result = $this->queue->flushFailed();

        return response()->json($result, $result['success'] ? 200 : 422);
    }

    public function cacheOverview(): JsonResponse
    {
        return response()->json($this->cache->overview());
    }

    public function clearCache(): JsonResponse
    {
        $result = $this->cache->clear();

        return response()->json($result, $result['success'] ? 200 : 422);
    }

    public function clearRedis(): JsonResponse
    {
        $result = $this->cache->clearRedis();

        return response()->json($result, $result['success'] ? 200 : 422);
    }

    public function cacheStats(): JsonResponse
    {
        return response()->json($this->cache->stats());
    }

    public function financialOverview(): JsonResponse
    {
        return response()->json($this->financial->overview());
    }

    public function databaseHealth(): JsonResponse
    {
        $payload = [
            'status' => 'unavailable',
            'connection' => config('database.default'),
            'database' => config('database.connections.mysql.database'),
            'latency_ms' => null,
            'table_count' => 0,
            'table_sizes' => [],
        ];

        try {
            $start = microtime(true);
            DB::connection()->getPdo();
            $payload['latency_ms'] = round((microtime(true) - $start) * 1000, 2);

            $tables = DB::select('SHOW TABLES');
            $payload['table_count'] = count($tables);
            $payload['database'] = DB::connection()->getDatabaseName();

            $tableSizes = [];
            foreach ($tables as $table) {
                $tableName = array_values((array) $table)[0];
                try {
                    $size = DB::select("SHOW TABLE STATUS FROM `{$payload['database']}` WHERE Name = ?", [$tableName]);
                    $tableSizes[$tableName] = $size[0]->Data_length ?? 0;
                } catch (\Throwable $e) {
                    $tableSizes[$tableName] = null;
                }
            }

            $payload['table_sizes'] = $tableSizes;
            $payload['status'] = 'healthy';

            return response()->json($payload);
        } catch (\Throwable $e) {
            $payload['status'] = 'failed';
            $payload['error'] = $e->getMessage();

            return response()->json($payload, 200);
        }
    }

    public function bankMonitoring(): JsonResponse
    {
        $payload = [
            'status' => 'unavailable',
            'stk' => [
                'pending' => 0,
                'failed' => 0,
                'successful' => 0,
                'recent' => [],
            ],
            'last_backup' => null,
        ];

        try {
            $pendingStk = CoopTransferRequest::where('transfer_type', 'STK')
                ->whereIn('status', ['processing', 'submitted'])
                ->count();

            $failedStk = CoopTransferRequest::where('transfer_type', 'STK')
                ->where('status', 'failed')
                ->count();

            $successStk = CoopTransferRequest::where('transfer_type', 'STK')
                ->where('status', 'successful')
                ->count();

            $recentStk = CoopTransferRequest::where('transfer_type', 'STK')
                ->orderByDesc('created_at')
                ->limit(10)
                ->get(['id', 'message_reference', 'status', 'amount', 'created_at']);

            $lastSync = Backup::where('is_safety', false)
                ->where('status', Backup::STATUS_COMPLETED)
                ->orderByDesc('created_at')
                ->first();

            $payload['status'] = 'healthy';
            $payload['stk'] = [
                'pending' => $pendingStk,
                'failed' => $failedStk,
                'successful' => $successStk,
                'recent' => $recentStk,
            ];
            $payload['last_backup'] = $lastSync ? [
                'id' => $lastSync->id,
                'created_at' => $lastSync->created_at?->toIso8601String(),
            ] : null;

            return response()->json($payload);
        } catch (\Throwable $e) {
            $payload['status'] = 'failed';
            $payload['error'] = $e->getMessage();

            return response()->json($payload, 200);
        }
    }

    public function transactionTrace(Request $request): JsonResponse
    {
        $query = trim((string) $request->query('query', ''));

        if ($query === '') {
            return response()->json(['error' => 'Missing query parameter'], 422);
        }

        $results = [
            'query' => $query,
            'member' => null,
            'contributions' => [],
            'wallet_transactions' => [],
            'stk_transfers' => [],
            'stk_by_reference' => [],
        ];

        try {
            $member = DB::table('members')
                ->where('phone', 'like', "%{$query}%")
                ->orWhere('name', 'like', "%{$query}%")
                ->first();

            if ($member) {
                $results['member'] = $member;

                $results['payments'] = DB::table('payments')
                    ->where('member_id', $member->id)
                    ->where('status', 'confirmed')
                    ->orderByDesc('created_at')
                    ->limit(10)
                    ->get();
                $results['contributions'] = DB::table('contributions')
                    ->where('member_id', $member->id)
                    ->orderByDesc('created_at')
                    ->limit(10)
                    ->get();
                $results['financial_read_model'] = $this->financialReadModel->memberOverview((string) $member->id);

                $results['wallet_transactions'] = DB::table('wallet_transactions')
                    ->where('member_id', $member->id)
                    ->orderByDesc('created_at')
                    ->limit(10)
                    ->get();

                $results['stk_transfers'] = CoopTransferRequest::where('member_id', $member->id)
                    ->orderByDesc('created_at')
                    ->limit(10)
                    ->get();
            }

            $stkByRef = CoopTransferRequest::where('message_reference', 'like', "%{$query}%")
                ->orWhere('internal_reference', 'like', "%{$query}%")
                ->orderByDesc('created_at')
                ->limit(10)
                ->get();

            $results['stk_by_reference'] = $stkByRef;

            return response()->json($results);
        } catch (\Throwable $e) {
            $results['status'] = 'failed';
            $results['error'] = $e->getMessage();

            return response()->json($results, 200);
        }
    }
}
