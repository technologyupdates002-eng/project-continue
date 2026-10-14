<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\BankTransaction;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class BankBalanceController extends Controller
{
    /**
     * Get bank balance overview with statistics
     */
    public function overview()
    {
        // Get the most recent transaction for current balance
        $latestTransaction = BankTransaction::orderBy('transaction_date', 'desc')
            ->orderBy('id', 'desc')
            ->first();

        // Calculate totals from credit_amount and debit_amount columns
        $totalCredits = BankTransaction::whereNotNull('credit_amount')->sum('credit_amount');
        $totalDebits = BankTransaction::whereNotNull('debit_amount')->sum('debit_amount');
        $creditCount = BankTransaction::whereNotNull('credit_amount')->where('credit_amount', '>', 0)->count();
        $debitCount = BankTransaction::whereNotNull('debit_amount')->where('debit_amount', '>', 0)->count();

        // Get monthly summary for last 6 months
        $monthlySummary = BankTransaction::select(
            DB::raw('DATE_FORMAT(transaction_date, "%Y-%m") as month'),
            DB::raw('SUM(IFNULL(credit_amount, 0)) as credits'),
            DB::raw('SUM(IFNULL(debit_amount, 0)) as debits'),
            DB::raw('SUM(IFNULL(credit_amount, 0)) - SUM(IFNULL(debit_amount, 0)) as net'),
            DB::raw('COUNT(*) as transaction_count')
        )
        ->where('transaction_date', '>=', now()->subMonths(6))
        ->groupBy(DB::raw('DATE_FORMAT(transaction_date, "%Y-%m")'))
        ->orderBy('month', 'desc')
        ->get()
        ->map(function($item) {
            $item->month = date('M Y', strtotime($item->month . '-01'));
            return $item;
        });

        return response()->json([
            'data' => [
                'current_balance' => $latestTransaction->bank_balance ?? 0,
                'last_updated' => $latestTransaction->transaction_date ?? now(),
                'total_credits' => $totalCredits,
                'total_debits' => $totalDebits,
                'credit_transaction_count' => $creditCount,
                'debit_transaction_count' => $debitCount,
                'net_flow' => $totalCredits - $totalDebits,
                'monthly_summary' => $monthlySummary,
            ]
        ]);
    }

    /**
     * Get withdrawal transactions (debits)
     */
    public function withdrawals(Request $request)
    {
        $query = BankTransaction::whereNotNull('debit_amount')->where('debit_amount', '>', 0);

        // Apply search filter
        if ($request->has('search') && $request->search) {
            $search = $request->search;
            $query->where(function($q) use ($search) {
                $q->where('transaction_reference', 'like', "%{$search}%")
                  ->orWhere('narration', 'like', "%{$search}%");
            });
        }

        // Apply date filters
        if ($request->has('start_date')) {
            $query->where('transaction_date', '>=', $request->start_date);
        }
        if ($request->has('end_date')) {
            $query->where('transaction_date', '<=', $request->end_date);
        }

        $perPage = $request->get('per_page', 50);
        $transactions = $query->orderBy('transaction_date', 'desc')
            ->orderBy('id', 'desc')
            ->paginate($perPage);

        // Transform the data
        $transformedData = $transactions->map(function($txn) {
            return [
                'id' => $txn->id,
                'date' => $txn->transaction_date,
                'reference' => $txn->transaction_reference,
                'bank_reference' => $txn->bank_transaction_reference ?? '',
                'amount' => $txn->debit_amount,
                'balance_after' => $txn->bank_balance,
                'description' => $txn->narration,
                'account' => $txn->account_number ?? '',
                'type' => 'DEBIT',
            ];
        });

        return response()->json([
            'data' => $transformedData,
            'pagination' => [
                'total' => $transactions->total(),
                'per_page' => $transactions->perPage(),
                'current_page' => $transactions->currentPage(),
                'last_page' => $transactions->lastPage(),
            ]
        ]);
    }

    /**
     * Get deposit transactions (credits)
     */
    public function deposits(Request $request)
    {
        $query = BankTransaction::whereNotNull('credit_amount')->where('credit_amount', '>', 0);

        // Apply search filter
        if ($request->has('search') && $request->search) {
            $search = $request->search;
            $query->where(function($q) use ($search) {
                $q->where('transaction_reference', 'like', "%{$search}%")
                  ->orWhere('narration', 'like', "%{$search}%")
                  ->orWhere('name', 'like', "%{$search}%")
                  ->orWhere('phone', 'like', "%{$search}%");
            });
        }

        // Apply date filters
        if ($request->has('start_date')) {
            $query->where('transaction_date', '>=', $request->start_date);
        }
        if ($request->has('end_date')) {
            $query->where('transaction_date', '<=', $request->end_date);
        }

        $perPage = $request->get('per_page', 50);
        $transactions = $query->orderBy('transaction_date', 'desc')
            ->orderBy('id', 'desc')
            ->paginate($perPage);

        // Transform the data
        $transformedData = $transactions->map(function($txn) {
            return [
                'id' => $txn->id,
                'date' => $txn->transaction_date,
                'reference' => $txn->transaction_reference,
                'bank_reference' => $txn->bank_transaction_reference ?? '',
                'amount' => $txn->credit_amount,
                'balance_after' => $txn->bank_balance,
                'description' => $txn->narration,
                'account' => $txn->account_number ?? '',
                'type' => 'CREDIT',
                'name' => $txn->name,
                'phone' => $txn->phone,
                'reconciled' => $txn->reconciliation_status === 'matched',
            ];
        });

        return response()->json([
            'data' => $transformedData,
            'pagination' => [
                'total' => $transactions->total(),
                'per_page' => $transactions->perPage(),
                'current_page' => $transactions->currentPage(),
                'last_page' => $transactions->lastPage(),
            ]
        ]);
    }

    /**
     * Get all transactions
     */
    public function transactions(Request $request)
    {
        $query = BankTransaction::query();

        // Apply search filter
        if ($request->has('search') && $request->search) {
            $search = $request->search;
            $query->where(function($q) use ($search) {
                $q->where('transaction_reference', 'like', "%{$search}%")
                  ->orWhere('narration', 'like', "%{$search}%")
                  ->orWhere('name', 'like', "%{$search}%")
                  ->orWhere('phone', 'like', "%{$search}%");
            });
        }

        // Apply date filters
        if ($request->has('start_date')) {
            $query->where('transaction_date', '>=', $request->start_date);
        }
        if ($request->has('end_date')) {
            $query->where('transaction_date', '<=', $request->end_date);
        }

        $perPage = $request->get('per_page', 50);
        $transactions = $query->orderBy('transaction_date', 'desc')
            ->orderBy('id', 'desc')
            ->paginate($perPage);

        // Transform the data
        $transformedData = $transactions->map(function($txn) {
            $isCredit = $txn->credit_amount && $txn->credit_amount > 0;
            return [
                'id' => $txn->id,
                'date' => $txn->transaction_date,
                'reference' => $txn->transaction_reference,
                'bank_reference' => $txn->bank_transaction_reference ?? '',
                'amount' => $isCredit ? $txn->credit_amount : $txn->debit_amount,
                'balance' => $txn->bank_balance,
                'description' => $txn->narration,
                'account' => $txn->account_number ?? '',
                'type' => $isCredit ? 'CREDIT' : 'DEBIT',
                'name' => $txn->name ?? null,
                'phone' => $txn->phone ?? null,
                'credit' => $txn->credit_amount,
                'debit' => $txn->debit_amount,
            ];
        });

        return response()->json([
            'data' => $transformedData,
            'pagination' => [
                'total' => $transactions->total(),
                'per_page' => $transactions->perPage(),
                'current_page' => $transactions->currentPage(),
                'last_page' => $transactions->lastPage(),
            ]
        ]);
    }
}
