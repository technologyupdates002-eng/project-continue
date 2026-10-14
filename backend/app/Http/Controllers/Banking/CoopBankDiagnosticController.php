<?php

namespace App\Http\Controllers\Banking;

use App\Http\Controllers\Controller;
use App\Models\BankDiagnosticRun;
use App\Services\CoopBank\CoopBankDiagnosticService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class CoopBankDiagnosticController extends Controller
{
    public function __construct(
        protected CoopBankDiagnosticService $diagnosticService
    ) {}

    public function connectivity(Request $request): JsonResponse
    {
        $identity = $request->attributes->get('identity');
        $this->assertAdmin($identity);

        $executedBy = $identity['user_id'] ?? 'system';
        $result = $this->diagnosticService->runFullDiagnostics($executedBy);

        return response()->json($result, 200);
    }

    public function runTest(Request $request, string $testType): JsonResponse
    {
        $identity = $request->attributes->get('identity');
        $this->assertAdmin($identity);

        $executedBy = $identity['user_id'] ?? 'system';
        $result = $this->diagnosticService->runSingleTest($testType, $executedBy);

        return response()->json($result, 200);
    }

    public function history(Request $request): JsonResponse
    {
        $identity = $request->attributes->get('identity');
        $this->assertAdmin($identity);

        $executedBy = $identity['user_id'] ?? 'system';
        $limit = (int) $request->query('limit', 50);

        $history = $this->diagnosticService->getDiagnosticHistory($executedBy, $limit);

        return response()->json([
            'history' => $history,
        ], 200);
    }

    public function report(Request $request, string $diagnosticId): JsonResponse
    {
        $identity = $request->attributes->get('identity');
        $this->assertAdmin($identity);

        $report = $this->diagnosticService->generateReport($diagnosticId);

        return response()->json($report, 200);
    }

    public function lastReport(Request $request): JsonResponse
    {
        $identity = $request->attributes->get('identity');
        $this->assertAdmin($identity);

        $executedBy = $identity['user_id'] ?? 'system';
        $lastRun = BankDiagnosticRun::where('executed_by', $executedBy)
            ->orderByDesc('created_at')
            ->first();

        if (!$lastRun) {
            return response()->json(['error' => 'No diagnostic runs found'], 404);
        }

        $report = $this->diagnosticService->generateReport($lastRun->diagnostic_id);

        return response()->json($report, 200);
    }

    protected function assertAdmin(array $identity): void
    {
        $roles = $identity['roles'] ?? [];

        if (!in_array('admin', $roles) && !in_array('super_admin', $roles)) {
            throw new \App\Exceptions\HttpException(403, 'insufficient_permissions', 'Admin access required');
        }
    }
}
