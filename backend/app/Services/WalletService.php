<?php

namespace App\Services;

use App\Exceptions\HttpException;
use App\Models\DonationWallet;
use App\Models\OperationalWallet;
use App\Models\PenaltyWallet;
use App\Models\WalletTransaction;
use App\Support\Uuid;
use Illuminate\Support\Facades\DB;

class WalletService
{
    public function credit(string $walletType, string $memberId, float $amount, ?string $mpesaReceipt, string $source, ?string $referenceId = null, ?string $referenceTable = null, ?string $notes = null): array
    {
        if ($amount <= 0) {
            throw new HttpException(422, 'invalid_amount', 'Amount must be positive');
        }

        $walletType = strtolower($walletType);
        if (! in_array($walletType, ['penalty', 'donation', 'operational'])) {
            throw new HttpException(422, 'invalid_wallet', 'Unknown wallet type');
        }

        $result = DB::transaction(function () use ($walletType, $memberId, $amount, $mpesaReceipt, $source, $referenceId, $referenceTable, $notes) {
            $wallet = $this->getWalletModel($walletType);
            $record = $wallet::lockForUpdate()->firstOrCreate([], [
                'id' => Uuid::v4(),
                'total_balance' => 0,
                'total_received' => 0,
                'total_withdrawn' => 0,
                'reserved_balance' => 0,
                'available_balance' => 0,
            ]);

            if ($referenceId && $referenceTable) {
                $existingTransaction = WalletTransaction::where('wallet_type', $walletType)
                    ->where('direction', 'credit')
                    ->where('reference_id', $referenceId)
                    ->where('reference_table', $referenceTable)
                    ->lockForUpdate()
                    ->first();

                if ($existingTransaction) {
                    return ['wallet' => $record->refresh(), 'transaction' => $existingTransaction];
                }
            }

            $newBalance = (float) $record->total_balance + $amount;
            $newReceived = (float) $record->total_received + $amount;

            $record->total_balance = $newBalance;
            $record->total_received = $newReceived;
            $record->available_balance = max(0, $newBalance - (float) ($record->reserved_balance ?? 0));
            $record->save();

            $transaction = WalletTransaction::create([
                'id' => Uuid::v4(),
                'wallet_type' => $walletType,
                'direction' => 'credit',
                'source' => $source,
                'reference_id' => $referenceId,
                'reference_table' => $referenceTable,
                'gross_amount' => $amount,
                'mpesa_charge' => 0,
                'system_fee' => 0,
                'net_amount' => $amount,
                'running_balance' => $newBalance,
                'mpesa_receipt' => $mpesaReceipt,
                'status' => 'completed',
                'notes' => $notes,
                'created_by' => $memberId,
                'occurred_at' => now(),
                'created_at' => now(),
            ]);

            return ['wallet' => $record, 'transaction' => $transaction];
        });

        return [
            'success' => true,
            'wallet_type' => $walletType,
            'amount' => $amount,
            'new_balance' => (float) $result['wallet']->total_balance,
            'transaction_id' => $result['transaction']->id,
        ];
    }

    public function debit(string $walletType, string $memberId, float $amount, string $reason, ?string $referenceId = null, ?string $referenceTable = null): array
    {
        if ($amount <= 0) {
            throw new HttpException(422, 'invalid_amount', 'Amount must be positive');
        }

        $walletType = strtolower($walletType);
        if (! in_array($walletType, ['penalty', 'donation', 'operational'])) {
            throw new HttpException(422, 'invalid_wallet', 'Unknown wallet type');
        }

        $result = DB::transaction(function () use ($walletType, $memberId, $amount, $reason, $referenceId, $referenceTable) {
            $wallet = $this->getWalletModel($walletType);
            $record = $wallet::lockForUpdate()->firstOrCreate([], [
                'id' => Uuid::v4(),
                'total_balance' => 0,
                'total_received' => 0,
                'total_withdrawn' => 0,
                'reserved_balance' => 0,
                'available_balance' => 0,
            ]);

            $currentBalance = (float) $record->total_balance;
            if ($currentBalance < $amount) {
                throw new HttpException(422, 'insufficient_balance', "Insufficient balance. Current: {$currentBalance}, Required: {$amount}");
            }

            $newBalance = $currentBalance - $amount;
            $newWithdrawn = (float) $record->total_withdrawn + $amount;

            $record->total_balance = $newBalance;
            $record->total_withdrawn = $newWithdrawn;
            $record->available_balance = (float) ($record->total_balance - $record->reserved_balance);
            $record->save();

            $transaction = WalletTransaction::create([
                'id' => Uuid::v4(),
                'wallet_type' => $walletType,
                'direction' => 'debit',
                'source' => 'withdrawal',
                'reference_id' => $referenceId,
                'reference_table' => $referenceTable,
                'gross_amount' => $amount,
                'mpesa_charge' => 0,
                'system_fee' => 0,
                'net_amount' => $amount,
                'running_balance' => $newBalance,
                'mpesa_receipt' => null,
                'status' => 'completed',
                'notes' => $reason,
                'created_by' => $memberId,
                'occurred_at' => now(),
                'created_at' => now(),
            ]);

            return ['wallet' => $record, 'transaction' => $transaction];
        });

        return [
            'success' => true,
            'wallet_type' => $walletType,
            'amount' => $amount,
            'new_balance' => (float) $result['wallet']->total_balance,
            'transaction_id' => $result['transaction']->id,
        ];
    }

    public function getBalance(string $walletType): ?array
    {
        $walletType = strtolower($walletType);
        $wallet = $this->getWalletModel($walletType);
        $record = $wallet::first();

        if (! $record) {
            return null;
        }

        $totalBalance = (float) $record->total_balance;
        $reservedBalance = (float) ($record->reserved_balance ?? 0);

        return [
            'wallet_type' => $walletType,
            'total_balance' => $totalBalance,
            'available_balance' => $totalBalance - $reservedBalance,
            'reserved_balance' => $reservedBalance,
            'total_received' => (float) $record->total_received,
            'total_withdrawn' => (float) $record->total_withdrawn,
        ];
    }

    public function getTransactions(string $walletType, ?string $memberId = null, int $limit = 100): array
    {
        $query = WalletTransaction::where('wallet_type', strtolower($walletType))
            ->orderByDesc('occurred_at')
            ->limit($limit);

        if ($memberId) {
            $query->where('created_by', $memberId);
        }

        return $query->get()->map(fn ($t) => [
            'id' => $t->id,
            'direction' => $t->direction,
            'source' => $t->source,
            'gross_amount' => (float) $t->gross_amount,
            'net_amount' => (float) $t->net_amount,
            'running_balance' => (float) $t->running_balance,
            'mpesa_receipt' => $t->mpesa_receipt,
            'status' => $t->status,
            'notes' => $t->notes,
            'occurred_at' => (string) $t->occurred_at,
        ])->all();
    }

    private function getWalletModel(string $walletType): string
    {
        return match ($walletType) {
            'penalty' => PenaltyWallet::class,
            'donation' => DonationWallet::class,
            'operational' => OperationalWallet::class,
            default => throw new HttpException(422, 'invalid_wallet', 'Unknown wallet type'),
        };
    }

    public function reserveFunds(string $walletType, string $referenceId, float $amount, string $referenceTable, string $notes): array
    {
        $walletType = strtolower($walletType);
        if (! in_array($walletType, ['penalty', 'donation', 'operational'])) {
            throw new HttpException(422, 'invalid_wallet', 'Unknown wallet type');
        }

        if ($amount <= 0) {
            throw new HttpException(422, 'invalid_amount', 'Amount must be positive');
        }

        $result = DB::transaction(function () use ($walletType, $referenceId, $amount, $referenceTable, $notes) {
            $wallet = $this->getWalletModel($walletType);
            $record = $wallet::lockForUpdate()->firstOrCreate([], [
                'id' => Uuid::v4(),
                'total_balance' => 0,
                'total_received' => 0,
                'total_withdrawn' => 0,
                'reserved_balance' => 0,
                'available_balance' => 0,
            ]);

            $existingReservation = WalletTransaction::where('wallet_type', $walletType)
                ->where('reference_id', $referenceId)
                ->where('reference_table', $referenceTable)
                ->where('direction', 'reserve')
                ->lockForUpdate()
                ->exists();
            if ($existingReservation) {
                throw new HttpException(422, 'duplicate_reservation', 'Funds have already been reserved for this reference');
            }

            $totalBalance = (float) $record->total_balance;
            $reservedBalance = (float) ($record->reserved_balance ?? 0);
            $availableBalance = $totalBalance - $reservedBalance;

            if ($availableBalance < $amount) {
                throw new HttpException(422, 'insufficient_available_balance', "Insufficient available balance. Total: {$totalBalance}, Reserved: {$reservedBalance}, Available: {$availableBalance}, Required: {$amount}");
            }

            $newReserved = $reservedBalance + $amount;
            $newAvailable = $totalBalance - $newReserved;

            $record->reserved_balance = $newReserved;
            $record->available_balance = $newAvailable;
            $record->save();

            $transaction = WalletTransaction::create([
                'id' => Uuid::v4(),
                'wallet_type' => $walletType,
                'direction' => 'reserve',
                'source' => 'bank_withdrawal_reservation',
                'reference_id' => $referenceId,
                'reference_table' => $referenceTable,
                'gross_amount' => $amount,
                'mpesa_charge' => 0,
                'system_fee' => 0,
                'net_amount' => $amount,
                'running_balance' => $totalBalance,
                'mpesa_receipt' => null,
                'status' => 'pending',
                'notes' => $notes,
                'created_by' => null,
                'occurred_at' => now(),
                'created_at' => now(),
            ]);

            return ['wallet' => $record, 'transaction' => $transaction];
        });

        return [
            'success' => true,
            'wallet_type' => $walletType,
            'amount' => $amount,
            'reserved_balance' => (float) $result['wallet']->reserved_balance,
            'available_balance' => (float) $result['wallet']->available_balance,
            'reservation_id' => $result['transaction']->id,
        ];
    }

    public function releaseReservedFunds(string $walletType, string $referenceId, float $amount, string $referenceTable, string $notes): array
    {
        $walletType = strtolower($walletType);
        if (! in_array($walletType, ['penalty', 'donation', 'operational'])) {
            throw new HttpException(422, 'invalid_wallet', 'Unknown wallet type');
        }

        $result = DB::transaction(function () use ($walletType, $referenceId, $amount, $referenceTable, $notes) {
            $reservation = WalletTransaction::where('wallet_type', $walletType)
                ->where('reference_id', $referenceId)
                ->where('reference_table', $referenceTable)
                ->where('direction', 'reserve')
                ->where('status', 'pending')
                ->lockForUpdate()
                ->first();

            if (! $reservation) {
                return ['wallet' => null, 'transaction' => null, 'released' => false];
            }

            $wallet = $this->getWalletModel($walletType);
            $record = $wallet::lockForUpdate()->first();

            if (! $record) {
                $record = $wallet::create([
                    'id' => Uuid::v4(),
                    'total_balance' => 0,
                    'total_received' => 0,
                    'total_withdrawn' => 0,
                    'reserved_balance' => 0,
                    'available_balance' => 0,
                ]);
            }

            $currentReserved = (float) $record->reserved_balance;
            $newReserved = max(0, $currentReserved - $amount);
            $newAvailable = (float) $record->total_balance - $newReserved;

            $record->reserved_balance = $newReserved;
            $record->available_balance = $newAvailable;
            $record->save();

            DB::table('wallet_transactions')
                ->where('id', $reservation->id)
                ->update(['status' => 'released']);

            $releaseTx = WalletTransaction::create([
                'id' => Uuid::v4(),
                'wallet_type' => $walletType,
                'direction' => 'release',
                'source' => 'bank_withdrawal_release',
                'reference_id' => $referenceId,
                'reference_table' => $referenceTable,
                'gross_amount' => $amount,
                'mpesa_charge' => 0,
                'system_fee' => 0,
                'net_amount' => $amount,
                'running_balance' => (float) $record->total_balance,
                'mpesa_receipt' => null,
                'status' => 'completed',
                'notes' => $notes,
                'created_by' => null,
                'occurred_at' => now(),
                'created_at' => now(),
            ]);

            return ['wallet' => $record->refresh(), 'transaction' => $releaseTx, 'released' => true];
        });

        return [
            'success' => true,
            'wallet_type' => $walletType,
            'amount' => $amount,
            'reserved_balance' => $result['wallet'] ? (float) $result['wallet']->reserved_balance : 0,
            'available_balance' => $result['wallet'] ? (float) $result['wallet']->available_balance : 0,
            'released' => $result['released'],
        ];
    }

    public function settleReservedFunds(string $walletType, string $referenceId, float $amount, string $referenceTable, string $notes): array
    {
        $walletType = strtolower($walletType);
        if (! in_array($walletType, ['penalty', 'donation', 'operational'])) {
            throw new HttpException(422, 'invalid_wallet', 'Unknown wallet type');
        }

        $result = DB::transaction(function () use ($walletType, $referenceId, $amount, $referenceTable, $notes) {
            $reservation = WalletTransaction::where('wallet_type', $walletType)
                ->where('reference_id', $referenceId)
                ->where('reference_table', $referenceTable)
                ->where('direction', 'reserve')
                ->where('status', 'pending')
                ->lockForUpdate()
                ->first();

            if (! $reservation) {
                return ['wallet' => null, 'transaction' => null, 'settled' => false];
            }

            $wallet = $this->getWalletModel($walletType);
            $record = $wallet::lockForUpdate()->first();

            if (! $record) {
                $record = $wallet::create([
                    'id' => Uuid::v4(),
                    'total_balance' => 0,
                    'total_received' => 0,
                    'total_withdrawn' => 0,
                    'reserved_balance' => 0,
                    'available_balance' => 0,
                ]);
            }

            $currentReserved = (float) $record->reserved_balance;
            $newReserved = max(0, $currentReserved - $amount);
            $newBalance = (float) $record->total_balance - $amount;
            $newWithdrawn = (float) $record->total_withdrawn + $amount;
            $newAvailable = $newBalance - $newReserved;

            $record->total_balance = $newBalance;
            $record->total_withdrawn = $newWithdrawn;
            $record->reserved_balance = $newReserved;
            $record->available_balance = $newAvailable;
            $record->save();

            DB::table('wallet_transactions')
                ->where('id', $reservation->id)
                ->update(['status' => 'settled']);

            $debitTx = WalletTransaction::create([
                'id' => Uuid::v4(),
                'wallet_type' => $walletType,
                'direction' => 'debit',
                'source' => 'bank_withdrawal_settlement',
                'reference_id' => $referenceId,
                'reference_table' => $referenceTable,
                'gross_amount' => $amount,
                'mpesa_charge' => 0,
                'system_fee' => 0,
                'net_amount' => $amount,
                'running_balance' => $newBalance,
                'mpesa_receipt' => null,
                'status' => 'completed',
                'notes' => $notes,
                'created_by' => null,
                'occurred_at' => now(),
                'created_at' => now(),
            ]);

            return ['wallet' => $record->refresh(), 'transaction' => $debitTx, 'settled' => true];
        });

        return [
            'success' => true,
            'wallet_type' => $walletType,
            'amount' => $amount,
            'reserved_balance' => $result['wallet'] ? (float) $result['wallet']->reserved_balance : 0,
            'available_balance' => $result['wallet'] ? (float) $result['wallet']->available_balance : 0,
            'total_balance' => $result['wallet'] ? (float) $result['wallet']->total_balance : 0,
            'transaction_id' => $result['transaction'] ? $result['transaction']->id : null,
            'settled' => $result['settled'],
        ];
    }

    public function reverseWithdrawalSettlement(string $walletType, string $referenceId, float $amount, string $referenceTable, string $notes): array
    {
        $walletType = strtolower($walletType);
        if (! in_array($walletType, ['penalty', 'donation', 'operational'])) {
            throw new HttpException(422, 'invalid_wallet', 'Unknown wallet type');
        }
        if ($amount <= 0) {
            throw new HttpException(422, 'invalid_amount', 'Amount must be positive');
        }

        return DB::transaction(function () use ($walletType, $referenceId, $amount, $referenceTable, $notes) {
            $wallet = $this->getWalletModel($walletType);
            $record = $wallet::lockForUpdate()->first();
            if (! $record) {
                return ['reversed' => false, 'already_reversed' => false];
            }

            $existingReversal = WalletTransaction::where('wallet_type', $walletType)
                ->where('direction', 'credit')
                ->where('source', 'bank_withdrawal_reversal')
                ->where('reference_id', $referenceId)
                ->where('reference_table', $referenceTable)
                ->lockForUpdate()
                ->first();
            if ($existingReversal) {
                return ['reversed' => true, 'already_reversed' => true];
            }

            $settlement = WalletTransaction::where('wallet_type', $walletType)
                ->where('direction', 'debit')
                ->where('source', 'bank_withdrawal_settlement')
                ->where('reference_id', $referenceId)
                ->where('reference_table', $referenceTable)
                ->where('status', 'completed')
                ->lockForUpdate()
                ->first();
            if (! $settlement) {
                return ['reversed' => false, 'already_reversed' => false];
            }

            $settledAmount = (float) $settlement->net_amount;
            if (abs($settledAmount - $amount) > 0.00001) {
                return ['reversed' => false, 'already_reversed' => false];
            }

            $newBalance = (float) $record->total_balance + $amount;
            $record->total_balance = $newBalance;
            $record->total_withdrawn = max(0, (float) $record->total_withdrawn - $amount);
            $record->available_balance = $newBalance - (float) ($record->reserved_balance ?? 0);
            $record->save();

            WalletTransaction::create([
                'id' => Uuid::v4(),
                'wallet_type' => $walletType,
                'direction' => 'credit',
                'source' => 'bank_withdrawal_reversal',
                'reference_id' => $referenceId,
                'reference_table' => $referenceTable,
                'gross_amount' => $amount,
                'mpesa_charge' => 0,
                'system_fee' => 0,
                'net_amount' => $amount,
                'running_balance' => $newBalance,
                'mpesa_receipt' => null,
                'status' => 'completed',
                'notes' => $notes,
                'created_by' => null,
                'occurred_at' => now(),
                'created_at' => now(),
            ]);

            return ['reversed' => true, 'already_reversed' => false];
        });
    }
}
