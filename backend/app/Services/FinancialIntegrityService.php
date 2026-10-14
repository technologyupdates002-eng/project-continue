<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use App\Models\Contribution;
use App\Models\CoopTransferRequest;
use App\Models\WalletTransaction;

class FinancialIntegrityService
{
    public function overview(): array
    {
        return [
            'contributions' => $this->checkContributions(),
            'wallets' => $this->checkWallets(),
            'stk_transfers' => $this->checkStkTransfers(),
            'duplicate_contributions' => $this->detectDuplicateContributions(),
            'negative_wallets' => $this->detectNegativeWallets(),
        ];
    }

    protected function checkContributions(): array
    {
        try {
            $total = Contribution::count();
            $pending = Contribution::where('status', 'pending')->count();
            $failed = Contribution::where('status', 'failed')->count();

            return [
                'status' => 'healthy',
                'total' => $total,
                'pending' => $pending,
                'failed' => $failed,
            ];
        } catch (\Throwable $e) {
            return [
                'status' => 'error',
                'error' => $e->getMessage(),
            ];
        }
    }

    protected function checkWallets(): array
    {
        try {
            $wallets = DB::table('wallet_transactions')->get();
            $balance = $wallets->sum('amount');

            return [
                'status' => 'healthy',
                'balance' => (float) $balance,
                'transaction_count' => $wallets->count(),
            ];
        } catch (\Throwable $e) {
            return [
                'status' => 'error',
                'error' => $e->getMessage(),
            ];
        }
    }

    protected function checkStkTransfers(): array
    {
        try {
            $pending = CoopTransferRequest::where('transfer_type', 'STK')
                ->whereIn('status', ['processing', 'submitted'])
                ->count();

            $failed = CoopTransferRequest::where('transfer_type', 'STK')
                ->where('status', 'failed')
                ->count();

            return [
                'status' => 'healthy',
                'pending' => $pending,
                'failed' => $failed,
            ];
        } catch (\Throwable $e) {
            return [
                'status' => 'error',
                'error' => $e->getMessage(),
            ];
        }
    }

    protected function detectDuplicateContributions(): array
    {
        try {
            $duplicates = DB::table('contributions')
                ->select('member_id', 'month', 'year', DB::raw('COUNT(*) as count'))
                ->groupBy('member_id', 'month', 'year')
                ->having('count', '>', 1)
                ->get();

            return [
                'count' => $duplicates->count(),
                'duplicates' => $duplicates->take(20),
            ];
        } catch (\Throwable $e) {
            return [
                'status' => 'error',
                'error' => $e->getMessage(),
            ];
        }
    }

    protected function detectNegativeWallets(): array
    {
        try {
            $negative = DB::table('wallet_transactions')
                ->select('wallet_type', DB::raw('SUM(amount) as balance'))
                ->groupBy('wallet_type')
                ->having('balance', '<', 0)
                ->get();

            return [
                'count' => $negative->count(),
                'wallets' => $negative,
            ];
        } catch (\Throwable $e) {
            return [
                'status' => 'error',
                'error' => $e->getMessage(),
            ];
        }
    }
}
