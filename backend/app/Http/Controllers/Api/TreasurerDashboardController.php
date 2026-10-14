<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\FinancialReadModel;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class TreasurerDashboardController extends Controller
{
    public function __construct(private FinancialReadModel $financialReadModel) {}

    public function chartData(Request $request): JsonResponse
    {
        $memberId = $request->query('member_id');
        $cacheKey = $memberId ? "treasurer:chart-data:{$memberId}" : 'treasurer:chart-data:all';
        $cacheTTL = 60;

        $data = $this->rememberCacheSafely($cacheKey, $cacheTTL, function () use ($memberId) {
            $currentDate = now();
            $startDate = $currentDate->copy()->subMonths(5)->startOfMonth();

            $expenseQuery = DB::table('expenses')
                ->selectRaw('YEAR(created_at) as year, MONTH(created_at) as month, SUM(amount) as total')
                ->whereIn('status', ['approved', 'completed'])
                ->where('created_at', '>=', $startDate);

            if ($memberId) {
                $expenseQuery->where('member_id', $memberId);
            }

            $incomeRows = collect($this->financialReadModel->bankIncomeSeries(
                $startDate,
                $currentDate,
                $memberId,
            ));

            $expenseRows = $expenseQuery->groupByRaw('YEAR(created_at), MONTH(created_at)')->get()
                ->mapWithKeys(fn ($row) => ["{$row->year}-{$row->month}" => (float) $row->total]);

            $months = [];
            for ($i = 5; $i >= 0; $i--) {
                $date = $currentDate->copy()->subMonths($i)->startOfMonth();
                $key = "{$date->year}-{$date->month}";
                $months[] = [
                    'month' => $date->format('M'),
                    'income' => $incomeRows->get($key, 0.0),
                    'expenses' => $expenseRows->get($key, 0.0),
                ];
            }

            return ['data' => $months];
        });

        return response()->json($data);
    }

    private function rememberCacheSafely(string $key, int $ttl, callable $callback): mixed
    {
        try {
            return Cache::store('redis')->remember($key, $ttl, $callback);
        } catch (\Throwable $e) {
            return $callback();
        }
    }

    public function recentActivity(): JsonResponse
    {
        $data = ['data' => $this->financialReadModel->recentConfirmedPayments()];

        return response()->json($data);
    }
}
