<?php

namespace App\Services;

use App\Exceptions\HttpException;
use App\Models\AuditLog;
use App\Models\CoopTransferRequest;
use App\Services\CoopBank\CoopBankTransferService;
use App\Support\Uuid;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

class WithdrawalApprovalService
{
    public const WALLET_TABLES = [
        'penalty' => 'penalty_withdrawals',
        'donation' => 'donation_withdrawals',
        'operational' => 'operational_withdrawals',
    ];

    public const SIGNATORY_TABLES = [
        'penalty' => 'withdrawal_signatories',
        'donation' => 'donation_withdrawal_signatories',
        'operational' => 'operational_withdrawal_signatories',
    ];

    public const APPROVAL_CHAIN = ['secretary', 'chairperson'];

    protected array $identity;
    protected WithdrawalSignatureService $signatureService;

    public function __construct(WithdrawalSignatureService $signatureService)
    {
        $this->signatureService = $signatureService;
        $this->identity = [];
    }

    public function setIdentity(array $identity): void
    {
        $this->identity = $identity;
    }

    public function resolveWalletType(string $signatoryTable): string
    {
        return match ($signatoryTable) {
            'withdrawal_signatories' => 'penalty',
            'donation_withdrawal_signatories' => 'donation',
            'operational_withdrawal_signatories' => 'operational',
            default => throw new HttpException(400, 'invalid_table', "Unknown signatory table: {$signatoryTable}"),
        };
    }

    public function getUserSignatoryRole(array $roles): ?string
    {
        $priority = ['secretary', 'chairperson', 'treasurer'];
        foreach ($priority as $role) {
            if (in_array($role, $roles, true)) {
                return $role;
            }
        }
        if (in_array('admin', $roles, true) || in_array('super_admin', $roles, true)) {
            return 'secretary';
        }
        return null;
    }

    public function validateSignatoryApproval(string $walletType, string $signatoryRole, array $existing): void
    {
        if (!array_key_exists($walletType, self::WALLET_TABLES)) {
            throw new HttpException(400, 'invalid_wallet_type', "Unknown wallet type: {$walletType}");
        }

        if (!in_array($signatoryRole, self::APPROVAL_CHAIN, true)) {
            throw new HttpException(403, 'invalid_signatory_role', "Your signatory role ($signatoryRole) is not part of the approval chain.");
        }

        $roles = $this->identity['roles'] ?? [];
        $hasRequiredRole = in_array($signatoryRole, $roles, true)
            || in_array('admin', $roles, true)
            || in_array('super_admin', $roles, true);
        if (!$hasRequiredRole) {
            throw new HttpException(403, 'invalid_signatory_role', 'Your account is not authorized for this approval stage.');
        }
    }

    public function validateApprovalStage(string $walletType, string $withdrawalId, string $approvingRole): void
    {
        $withdrawalTable = self::WALLET_TABLES[$walletType];
        $withdrawal = DB::table($withdrawalTable)->where('id', $withdrawalId)->first();

        if (!$withdrawal) {
            throw new HttpException(404, 'withdrawal_not_found', "Withdrawal not found: {$withdrawalId}");
        }

        $stages = self::APPROVAL_CHAIN;
        $currentStageIndex = array_search($withdrawal->approval_stage ?? 'secretary', $stages, true);

        if ($currentStageIndex === false) {
            $currentStageIndex = 0;
        }

        $nextStageIndex = array_search($approvingRole, $stages, true);

        if ($nextStageIndex !== $currentStageIndex) {
            $expectedRole = $stages[$currentStageIndex];
            $stageName = $withdrawal->approval_stage ?? 'treasurer';
            throw new HttpException(
                409,
                'invalid_approval_stage',
                "Cannot approve at this stage. The withdrawal is at stage '{$stageName}'. Expected approver: {$expectedRole}, got: {$approvingRole}."
            );
        }
    }

    public function validateNotSelfApproved(string $walletType, string $withdrawalId, string $userId): void
    {
        $withdrawalTable = self::WALLET_TABLES[$walletType];
        $withdrawal = DB::table($withdrawalTable)->where('id', $withdrawalId)->first();

        if ($withdrawal && ($withdrawal->requested_by ?? null) === $userId) {
            $roles = $this->identity['roles'] ?? [];
            if (!in_array('admin', $roles, true) && !in_array('super_admin', $roles, true)) {
                throw new HttpException(403, 'self_approval_not_allowed', 'You cannot approve a withdrawal you initiated.');
            }
        }
    }

    public function recordApproval(string $signatoryTable, string $withdrawalId, string $signatoryRole, array $changes, array $identity): ?array
    {
        $walletType = $this->resolveWalletType($signatoryTable);
        $this->setIdentity($identity);

        $userId = $identity['user_id'] ?? $this->identity['user_id'] ?? null;
        $this->validateNotSelfApproved($walletType, $withdrawalId, $userId);

        $this->validateSignatoryApproval($walletType, $signatoryRole, $changes);
        $this->validateApprovalStage($walletType, $withdrawalId, $signatoryRole);
        $signature = DB::table('signatory_signatures')
            ->where('user_id', $userId)
            ->where('signatory_role', $signatoryRole)
            ->first(['signature_url']);
        if (!$signature || !$signature->signature_url) {
            DB::table(self::WALLET_TABLES[$walletType])->where('id', $withdrawalId)->update([
                'status' => 'awaiting_signature',
                'updated_at' => now(),
            ]);
            throw new HttpException(409, 'signature_required', "Upload your authorized {$signatoryRole} signature before approving this withdrawal.");
        }
        try {
            $this->signatureService->resolveImage($signature->signature_url);
        } catch (HttpException $e) {
            if (in_array($e->errorCode, ['signature_required', 'signature_unavailable'], true)) {
                DB::table(self::WALLET_TABLES[$walletType])->where('id', $withdrawalId)->update([
                    'status' => 'awaiting_signature',
                    'updated_at' => now(),
                ]);
            }
            throw $e;
        }

        DB::transaction(function () use ($signatoryTable, $withdrawalId, $signatoryRole, $changes, $walletType, $userId, $identity, $signature) {
            $signatory = DB::table($signatoryTable)
                ->where('withdrawal_id', $withdrawalId)
                ->where('signatory_role', $signatoryRole)
                ->first();

            if (!$signatory) {
                throw new HttpException(404, 'signatory_not_found', "Signatory record not found for withdrawal {$withdrawalId}, role {$signatoryRole}.");
            }

            if ($signatory->status === 'rejected') {
                throw new HttpException(409, 'approval_already_rejected', 'A rejected approval cannot be changed to approved.');
            }
            if ($signatory->status === 'approved' && $signatory->signatory_user_id !== $userId) {
                throw new HttpException(409, 'approval_already_recorded', 'This approval has already been recorded by another authorized user.');
            }

            $updateCols = [
                'signatory_user_id' => $userId,
                'status' => 'approved',
                'updated_at' => now(),
            ];
            if (Schema::hasColumn($signatoryTable, 'signature_url')) {
                $updateCols['signature_url'] = $signature->signature_url;
            }
            if ($signatory->status !== 'approved') {
                $updateCols['approved_at'] = now();
            }

            DB::table($signatoryTable)
                ->where('id', $signatory->id)
                ->update($updateCols);

            $this->logAudit(
                $identity,
                'withdrawal_signatory_updated',
                $signatoryTable,
                $signatory->id,
                [
                    'withdrawal_id' => $withdrawalId,
                    'signatory_role' => $signatoryRole,
                    'status' => $updateCols['status'] ?? 'approved',
                    'wallet_type' => $walletType,
                ]
            );

            $this->advanceApprovalStage($walletType, $withdrawalId, $identity);
        });

        $signatoryTable = self::SIGNATORY_TABLES[$walletType];
        $withdrawalStatus = DB::table(self::WALLET_TABLES[$walletType])->where('id', $withdrawalId)->value('status');
        if (in_array($withdrawalStatus, ['approved', 'awaiting_signature'], true)) {
            try {
                $this->triggerB2CWithdrawal($walletType, $withdrawalId, $identity);
            } catch (HttpException $e) {
                if (in_array($e->errorCode, ['signature_required', 'signature_unavailable'], true)) {
                    DB::table(self::WALLET_TABLES[$walletType])->where('id', $withdrawalId)->update([
                        'status' => 'awaiting_signature',
                        'updated_at' => now(),
                    ]);
                }
                throw $e;
            }
        }

        return (array) DB::table($signatoryTable)
            ->where('withdrawal_id', $withdrawalId)
            ->where('signatory_role', $signatoryRole)
            ->first();
    }

    public function recordRejection(string $signatoryTable, string $withdrawalId, string $signatoryRole, string $rejectionReason, array $identity): array
    {
        $walletType = $this->resolveWalletType($signatoryTable);
        $this->setIdentity($identity);
        if (trim($rejectionReason) === '') {
            throw new HttpException(422, 'rejection_reason_required', 'A rejection reason is required.');
        }

        $this->validateSignatoryApproval($walletType, $signatoryRole, []);
        $this->validateApprovalStage($walletType, $withdrawalId, $signatoryRole);

        $userId = $identity['user_id'] ?? $this->identity['user_id'] ?? null;
        $this->validateNotSelfApproved($walletType, $withdrawalId, $userId);

        return DB::transaction(function () use ($signatoryTable, $withdrawalId, $signatoryRole, $rejectionReason, $walletType, $userId, $identity) {
            $signatory = DB::table($signatoryTable)
                ->where('withdrawal_id', $withdrawalId)
                ->where('signatory_role', $signatoryRole)
                ->first();

            if (!$signatory) {
                throw new HttpException(404, 'signatory_not_found', "Signatory record not found for withdrawal {$withdrawalId}, role {$signatoryRole}.");
            }
            if ($signatory->status === 'approved') {
                throw new HttpException(409, 'approval_already_recorded', 'An approved withdrawal cannot be rejected at the same stage.');
            }
            if ($signatory->status === 'rejected' && $signatory->signatory_user_id !== $userId) {
                throw new HttpException(409, 'approval_already_recorded', 'This rejection has already been recorded by another authorized user.');
            }

            $updateCols = [
                'status' => 'rejected',
                'rejected_at' => now(),
                'rejection_reason' => $rejectionReason,
                'signatory_user_id' => $userId,
                'updated_at' => now(),
            ];

            DB::table($signatoryTable)
                ->where('id', $signatory->id)
                ->update($updateCols);

            $withdrawalTable = self::WALLET_TABLES[$walletType];
            DB::table($withdrawalTable)
                ->where('id', $withdrawalId)
                ->update([
                    'status' => 'rejected',
                    'updated_at' => now(),
                ]);

            $this->logAudit(
                $identity,
                'withdrawal_signatory_rejected',
                $signatoryTable,
                $signatory->id,
                [
                    'withdrawal_id' => $withdrawalId,
                    'signatory_role' => $signatoryRole,
                    'rejection_reason' => $rejectionReason,
                    'wallet_type' => $walletType,
                ]
            );

            return (array) DB::table($signatoryTable)
                ->where('id', $signatory->id)
                ->first();
        });
    }

    public function advanceApprovalStage(string $walletType, string $withdrawalId, array $identity): void
    {
        $withdrawalTable = self::WALLET_TABLES[$walletType];
        $signatoryTable = self::SIGNATORY_TABLES[$walletType];

        $withdrawal = DB::table($withdrawalTable)->where('id', $withdrawalId)->lockForUpdate()->first();

        if (!$withdrawal) {
            throw new HttpException(404, 'withdrawal_not_found', "Withdrawal not found: {$withdrawalId}");
        }

        $signatories = DB::table($signatoryTable)
            ->where('withdrawal_id', $withdrawalId)
            ->orderByRaw("FIELD(signatory_role, 'secretary', 'chairperson')")
            ->get();

        $allApproved = true;
        $anyRejected = false;

        foreach ($signatories as $sig) {
            if ($sig->status !== 'approved') {
                $allApproved = false;
            }
            if ($sig->status === 'rejected') {
                $anyRejected = true;
            }
        }

        if ($anyRejected) {
            DB::table($withdrawalTable)
                ->where('id', $withdrawalId)
                ->update([
                    'status' => 'rejected',
                    'updated_at' => now(),
                ]);
            return;
        }

        if ($allApproved) {
            DB::table($withdrawalTable)
                ->where('id', $withdrawalId)
                ->update([
                    'status' => 'approved',
                    'updated_at' => now(),
                ]);
            return;
        }

        $stages = self::APPROVAL_CHAIN;
        $nextStage = null;
        foreach ($stages as $i => $stage) {
            $sig = $signatories->firstWhere('signatory_role', $stage);
            if ($sig && $sig->status !== 'approved') {
                $nextStage = $stage;
                break;
            }
        }

        if ($nextStage === null) {
            foreach ($stages as $stage) {
                $sig = $signatories->firstWhere('signatory_role', $stage);
                if (!$sig) {
                    $nextStage = $stage;
                    break;
                }
            }
        }

        if ($nextStage !== null && $nextStage !== $withdrawal->approval_stage) {
            DB::table($withdrawalTable)
                ->where('id', $withdrawalId)
                ->update([
                    'approval_stage' => $nextStage,
                    'updated_at' => now(),
                ]);
        }
    }

    public function triggerB2CWithdrawal(string $walletType, string $withdrawalId, array $identity): void
    {
        $withdrawalTable = self::WALLET_TABLES[$walletType];
        $walletTable = $walletType . '_wallet';

        try {
            $withdrawal = DB::transaction(function () use ($walletType, $withdrawalId, $withdrawalTable) {
            $record = DB::table($withdrawalTable)->where('id', $withdrawalId)->lockForUpdate()->first();
            if (!$record) {
                throw new HttpException(404, 'withdrawal_not_found', "Withdrawal not found: {$withdrawalId}");
            }
            $status = strtolower((string) $record->status);
            if (!in_array($status, ['approved', 'awaiting_signature'], true)) {
                if (in_array($status, ['submitted', 'processing', 'completed', 'failed', 'reversed', 'reconciliation_required', 'reservation_failed', 'approved_no_phone'], true) || !empty($record->bank_transfer_id)) {
                    return null;
                }
                $code = $status === 'rejected' ? 'invalid_withdrawal_status' : 'withdrawal_not_approved';
                throw new HttpException(409, $code, 'Withdrawal is not approved for B2C submission.');
            }
            if (!empty($record->bank_transfer_id)) {
                return null;
            }

            $existingTransfer = CoopTransferRequest::query()
                ->where('internal_table', $withdrawalTable)
                ->where('internal_reference', $withdrawalId)
                ->where('transfer_type', 'B2C_MPESA')
                ->lockForUpdate()
                ->first();
            if ($existingTransfer) {
                return null;
            }

            $linkedExpense = DB::table('expenses')
                ->where('withdrawal_id', $withdrawalId)
                ->where('withdrawal_table', $withdrawalTable)
                ->first();
            if ($linkedExpense && in_array(strtolower($linkedExpense->payment_method ?? ''), ['cash', 'bank', 'cheque', 'check'], true)) {
                return ['record' => $record, 'linked_expense' => $linkedExpense];
            }

            if (empty($record->phone_number)) {
                return ['record' => $record, 'linked_expense' => null, 'missing_phone' => true];
            }

            if (config('app.env') === 'production') {
                return ['record' => $record, 'linked_expense' => null, 'provider_disabled' => true];
            }

            $this->assertRequiredSignatures($walletType, $withdrawalId);
            $amount = (float) $record->amount;
            $phoneNumber = (string) ($record->phone_number ?? '');

            app(WalletService::class)->reserveFunds(
                $walletType,
                $withdrawalId,
                $amount,
                $withdrawalTable,
                "B2C withdrawal reservation for withdrawal #{$withdrawalId}"
            );

            DB::table($withdrawalTable)->where('id', $withdrawalId)->update([
                'status' => 'submitted',
                'submitted_at' => now(),
                'updated_at' => now(),
            ]);

                return ['record' => $record, 'linked_expense' => $linkedExpense];
            });
        } catch (HttpException $e) {
            if ($e->httpStatus !== 422) {
                throw $e;
            }

            DB::table($withdrawalTable)->where('id', $withdrawalId)->update([
                'status' => 'reservation_failed',
                'updated_at' => now(),
            ]);
            $this->logAudit($identity, 'withdrawal_b2c_reservation_failed', $withdrawalTable, $withdrawalId, [
                'reason' => $e->getMessage(),
                'wallet_type' => $walletType,
            ]);
            return;
        }

        if (!$withdrawal) {
            return;
        }

        if (!empty($withdrawal['missing_phone'])) {
            $this->logAudit(
                $identity,
                'withdrawal_b2c_skipped',
                $withdrawalTable,
                $withdrawalId,
                ['reason' => 'No phone number', 'wallet_type' => $walletType]
            );
            DB::table($withdrawalTable)->where('id', $withdrawalId)->update([
                'status' => 'approved_no_phone',
                'updated_at' => now(),
            ]);
            return;
        }

        if (!empty($withdrawal['provider_disabled'])) {
            Log::warning('B2C withdrawal submission blocked by production safety mode.', [
                'withdrawal_id' => $withdrawalId,
                'wallet_type' => $walletType,
            ]);
            $this->logAudit(
                $identity,
                'withdrawal_b2c_blocked_by_safety_gate',
                $withdrawalTable,
                $withdrawalId,
                ['wallet_type' => $walletType, 'reason' => 'production_provider_calls_disabled']
            );
            return;
        }

        $linkedExpense = $withdrawal['linked_expense'];
        $withdrawal = $withdrawal['record'];
        $amount = (float) $withdrawal->amount;
        $phoneNumber = (string) $withdrawal->phone_number;

        if ($linkedExpense && in_array(strtolower($linkedExpense->payment_method ?? ''), ['cash', 'bank', 'cheque', 'check'])) {
            $this->finalizeCashExpense($walletType, $withdrawalId, $withdrawal, $linkedExpense, $identity);
            return;
        }

        $walletService = app(WalletService::class);

        try {
            $transferService = app(CoopBankTransferService::class);

            $narration = sprintf(
                '%s Wallet withdrawal #%s',
                ucfirst($walletType),
                substr($withdrawalId, 0, 8)
            );

            $result = $transferService->sendToMpesa(
                $withdrawalId,
                $withdrawalTable,
                ['wallet' => $walletTable],
                $amount,
                $phoneNumber,
                $narration
            );

            if ($result['success']) {
                $messageReference = $result['message_reference'] ?? null;
                $transferId = $result['transfer_id'] ?? null;

                $b2cTxId = Uuid::v4();
                DB::table('b2c_transactions')->insert([
                    'id' => $b2cTxId,
                    'withdrawal_id' => $withdrawalId,
                    'wallet_type' => $walletType,
                    'mpesa_transaction_id' => $messageReference ?? '',
                    'phone_number' => $phoneNumber,
                    'amount' => $amount,
                    'status' => 'initiated',
                    'created_at' => now(),
                    'updated_at' => now(),
                    'transfer_id' => $transferId,
                ]);

                $updateCols = [
                    'b2c_reference' => $messageReference,
                    'bank_transfer_id' => $transferId,
                    'status' => 'processing',
                    'updated_at' => now(),
                ];

                DB::table($withdrawalTable)
                    ->where('id', $withdrawalId)
                    ->update($updateCols);

                if ($linkedExpense) {
                    DB::table('expenses')
                        ->where('id', $linkedExpense->id)
                        ->update([
                            'status' => 'approved',
                            'approved_at' => now(),
                            'updated_at' => now(),
                        ]);
                }

                $this->logAudit(
                    $identity,
                    'withdrawal_b2c_initiated',
                    $withdrawalTable,
                    $withdrawalId,
                    [
                        'wallet_type' => $walletType,
                        'amount' => $amount,
                        'b2c_reference' => $messageReference,
                        'bank_transfer_id' => $transferId,
                    ]
                );
            } else {
                $walletService->releaseReservedFunds(
                    $walletType,
                    $withdrawalId,
                    $amount,
                    $withdrawalTable,
                    "B2C withdrawal failed, releasing reservation for withdrawal #{$withdrawalId}"
                );

                $this->logAudit(
                    $identity,
                    'withdrawal_b2c_failed',
                    $withdrawalTable,
                    $withdrawalId,
                    ['reason' => $result['error'] ?? 'Unknown error', 'wallet_type' => $walletType]
                );

                DB::table($withdrawalTable)
                    ->where('id', $withdrawalId)
                    ->update([
                        'status' => 'approved',
                        'updated_at' => now(),
                    ]);
            }
        } catch (\Throwable $e) {
            $transfer = CoopTransferRequest::query()
                ->where('internal_table', $withdrawalTable)
                ->where('internal_reference', $withdrawalId)
                ->where('transfer_type', 'B2C_MPESA')
                ->first();

            $isOutcomeUnknown = $transfer && in_array($transfer->status, ['processing', 'submitted', 'timeout'], true);
            if (!$isOutcomeUnknown) {
                $walletService->releaseReservedFunds(
                    $walletType,
                    $withdrawalId,
                    $amount,
                    $withdrawalTable,
                    "B2C withdrawal definitively failed, releasing reservation for withdrawal #{$withdrawalId}"
                );
            }

            $this->logAudit(
                $identity,
                'withdrawal_b2c_exception',
                $withdrawalTable,
                $withdrawalId,
                [
                    'error' => $e->getMessage(),
                    'wallet_type' => $walletType,
                    'outcome_unknown' => $isOutcomeUnknown,
                ]
            );

            DB::table($withdrawalTable)
                ->where('id', $withdrawalId)
                ->update([
                    'status' => $isOutcomeUnknown ? 'processing' : 'failed',
                    'updated_at' => now(),
                ]);
        }
    }

    protected function finalizeCashExpense(string $walletType, string $withdrawalId, object $withdrawal, object $linkedExpense, array $identity): void
    {
        $withdrawalTable = self::WALLET_TABLES[$walletType];
        $walletService = app(WalletService::class);
        $amount = (float) $withdrawal->amount;

        try {
            $walletService->settleReservedFunds(
                $walletType,
                $withdrawalId,
                $amount,
                $withdrawalTable,
                "Cash/bank expense settlement for withdrawal #{$withdrawalId}"
            );
        } catch (HttpException $e) {
            $this->logAudit(
                $identity,
                'expense_settlement_failed',
                $withdrawalTable,
                $withdrawalId,
                ['reason' => $e->getMessage(), 'wallet_type' => $walletType]
            );

            DB::table($withdrawalTable)
                ->where('id', $withdrawalId)
                ->update([
                    'status' => 'reservation_failed',
                    'updated_at' => now(),
                ]);
            return;
        }

        DB::table($withdrawalTable)
            ->where('id', $withdrawalId)
            ->update([
                'status' => 'completed',
                'completed_at' => now(),
                'updated_at' => now(),
            ]);

        DB::table('expenses')
            ->where('id', $linkedExpense->id)
            ->update([
                'status' => 'approved',
                'approved_at' => now(),
                'paid_by' => $identity['user_id'] ?? null,
                'paid_at' => now(),
                'updated_at' => now(),
            ]);

        app(MemberPayoutService::class)->markWithdrawalCompleted($withdrawalId);

        $messageReference = 'CASH-' . substr($withdrawalId, 0, 8);

        if (Schema::hasColumn($withdrawalTable, 'receipt_url')) {
            DB::table($withdrawalTable)
                ->where('id', $withdrawalId)
                ->update([
                    'receipt_url' => $this->generateReceiptUrl($walletType, $withdrawalId, $messageReference),
                ]);
        }

        $this->logAudit(
            $identity,
            'expense_cash_approved',
            $withdrawalTable,
            $withdrawalId,
            [
                'wallet_type' => $walletType,
                'amount' => $amount,
                'expense_id' => $linkedExpense->id,
                'payment_method' => $linkedExpense->payment_method,
            ]
        );
    }

    public function applyProviderOutcome(CoopTransferRequest $transfer): void
    {
        $walletType = match ($transfer->internal_table) {
            'penalty_withdrawals' => 'penalty',
            'donation_withdrawals' => 'donation',
            'operational_withdrawals' => 'operational',
            default => null,
        };
        if (!$walletType || $transfer->transfer_type !== 'B2C_MPESA') {
            return;
        }

        $withdrawalTable = self::WALLET_TABLES[$walletType];
        $status = strtolower((string) $transfer->status);
        if (!in_array($status, ['successful', 'failed', 'reversed'], true)) {
            return;
        }

        $receiptNeeded = false;
        DB::transaction(function () use ($transfer, $walletType, $withdrawalTable, $status, &$receiptNeeded) {
            $lockedTransfer = CoopTransferRequest::query()->whereKey($transfer->id)->lockForUpdate()->first();
            if (!$lockedTransfer) {
                return;
            }

            $withdrawal = DB::table($withdrawalTable)
                ->where('id', $lockedTransfer->internal_reference)
                ->lockForUpdate()
                ->first();
            if (!$withdrawal) {
                Log::error('B2C provider outcome has no canonical withdrawal', [
                    'transfer_id' => $lockedTransfer->id,
                    'withdrawal_id' => $lockedTransfer->internal_reference,
                ]);
                return;
            }

            if ($withdrawal->status === 'reversed') {
                return;
            }

            if ($status === 'reversed') {
                $walletService = app(WalletService::class);
                $reservation = DB::table('wallet_transactions')
                    ->where('wallet_type', $walletType)
                    ->where('reference_id', $withdrawal->id)
                    ->where('reference_table', $withdrawalTable)
                    ->where('direction', 'reserve')
                    ->where('status', 'pending')
                    ->lockForUpdate()
                    ->first();

                if ($reservation) {
                    $result = $walletService->releaseReservedFunds(
                        $walletType,
                        (string) $withdrawal->id,
                        (float) $withdrawal->amount,
                        $withdrawalTable,
                        "B2C provider reversal released withdrawal #{$withdrawal->id}"
                    );
                    $reversed = $result['released'];
                } else {
                    $result = $walletService->reverseWithdrawalSettlement(
                        $walletType,
                        (string) $withdrawal->id,
                        (float) $withdrawal->amount,
                        $withdrawalTable,
                        "B2C provider reversal credited withdrawal #{$withdrawal->id}"
                    );
                    $reversed = $result['reversed'];
                }

                DB::table($withdrawalTable)->where('id', $withdrawal->id)->update([
                    'status' => $reversed ? 'reversed' : 'reconciliation_required',
                    'updated_at' => now(),
                ]);
                if (!$reversed) {
                    Log::critical('B2C provider reversal could not be safely applied to the wallet ledger', [
                        'transfer_id' => $lockedTransfer->id,
                        'withdrawal_id' => $withdrawal->id,
                        'previous_status' => $withdrawal->status,
                    ]);
                }
                return;
            }

            if ($withdrawal->status === 'completed') {
                $receiptNeeded = Schema::hasColumn($withdrawalTable, 'receipt_url')
                    && empty($withdrawal->receipt_url);
                return;
            }
            if ($withdrawal->status === 'failed') {
                return;
            }

            $walletService = app(WalletService::class);
            if ($status === 'successful') {
                $settlement = $walletService->settleReservedFunds(
                    $walletType,
                    (string) $withdrawal->id,
                    (float) $withdrawal->amount,
                    $withdrawalTable,
                    "B2C provider confirmed withdrawal #{$withdrawal->id}"
                );
                if (!$settlement['settled']) {
                    DB::table($withdrawalTable)->where('id', $withdrawal->id)->update([
                        'status' => 'reconciliation_required',
                        'updated_at' => now(),
                    ]);
                    Log::critical('Confirmed B2C transfer has no pending wallet reservation', [
                        'transfer_id' => $lockedTransfer->id,
                        'withdrawal_id' => $withdrawal->id,
                    ]);
                    return;
                }

                $updates = [
                    'status' => 'completed',
                    'completed_at' => now(),
                    'b2c_reference' => $lockedTransfer->message_reference,
                    'updated_at' => now(),
                ];
                if (Schema::hasColumn($withdrawalTable, 'receipt_url')) {
                    $receiptNeeded = true;
                }
                DB::table($withdrawalTable)->where('id', $withdrawal->id)->update($updates);
                DB::table('b2c_transactions')->where('transfer_id', $lockedTransfer->id)->update([
                    'status' => 'completed',
                    'updated_at' => now(),
                ]);
                return;
            }

            $walletService->releaseReservedFunds(
                $walletType,
                (string) $withdrawal->id,
                (float) $withdrawal->amount,
                $withdrawalTable,
                "B2C provider confirmed failure for withdrawal #{$withdrawal->id}"
            );
            $updates = ['status' => 'failed', 'updated_at' => now()];
            if (Schema::hasColumn($withdrawalTable, 'failed_at')) {
                $updates['failed_at'] = now();
            }
            DB::table($withdrawalTable)->where('id', $withdrawal->id)->update($updates);
            DB::table('b2c_transactions')->where('transfer_id', $lockedTransfer->id)->update([
                'status' => 'failed',
                'updated_at' => now(),
            ]);
        });

        if ($receiptNeeded) {
            $withdrawalId = (string) $transfer->internal_reference;
            try {
                $receiptUrl = $this->generateReceiptUrl($walletType, $withdrawalId, $transfer->message_reference);
                if ($receiptUrl && Schema::hasColumn($withdrawalTable, 'receipt_url')) {
                    DB::table($withdrawalTable)->where('id', $withdrawalId)->update([
                        'receipt_url' => $receiptUrl,
                        'updated_at' => now(),
                    ]);
                }
            } catch (\Throwable $e) {
                Log::error('Could not generate completed B2C withdrawal receipt', [
                    'transfer_id' => $transfer->id,
                    'withdrawal_id' => $withdrawalId,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        if ($status === 'successful') {
            app(MemberPayoutService::class)->markWithdrawalCompleted((string) $transfer->internal_reference);
        }
    }

    protected function assertRequiredSignatures(string $walletType, string $withdrawalId): void
    {
        $signatories = DB::table(self::SIGNATORY_TABLES[$walletType])
            ->where('withdrawal_id', $withdrawalId)
            ->get(['signatory_role', 'signatory_user_id', 'status']);

        foreach (self::APPROVAL_CHAIN as $role) {
            $approval = $signatories->firstWhere('signatory_role', $role);
            if (!$approval || $approval->status !== 'approved' || !$approval->signatory_user_id) {
                throw new HttpException(409, 'approval_incomplete', "Required approval is missing: {$role}.");
            }

            $signature = DB::table('signatory_signatures')
                ->where('user_id', $approval->signatory_user_id)
                ->where('signatory_role', $role)
                ->first(['signature_url']);
            if (!$signature || !$signature->signature_url) {
                throw new HttpException(409, 'signature_required', "The {$role} must upload an authorized signature before B2C submission.");
            }

            $this->signatureService->resolveImage($signature->signature_url);
        }
    }

    protected function generateReceiptUrl(string $walletType, string $withdrawalId, ?string $messageReference): ?string
    {
        $withdrawalTable = self::WALLET_TABLES[$walletType];
        $signatoryTable = self::SIGNATORY_TABLES[$walletType];

        $withdrawal = DB::table($withdrawalTable)->where('id', $withdrawalId)->first();
        if (! $withdrawal) {
            return null;
        }

        $signatories = DB::table($signatoryTable)
            ->where('withdrawal_id', $withdrawalId)
            ->orderByRaw("FIELD(signatory_role, 'secretary', 'chairperson')")
            ->get();

        $userIdList = $signatories->pluck('signatory_user_id')->filter()->unique()->values()->all();
        $users = [];
        $members = [];
        $signatureNames = [];
        $signatureUrls = [];

        if (!empty($userIdList)) {
            $users = DB::table('auth_users')
                ->whereIn('id', $userIdList)
                ->get(['id', 'email', 'raw_user_meta_data'])
                ->keyBy('id')
                ->map(fn ($u) => (array) $u)
                ->all();

            $members = DB::table('members')
                ->whereIn('user_id', $userIdList)
                ->get(['user_id', 'name'])
                ->keyBy('user_id')
                ->map(fn ($m) => (array) $m)
                ->all();

            $signatureRows = DB::table('signatory_signatures')
                ->whereIn('user_id', $userIdList)
                ->get(['user_id', 'signatory_role', 'full_name', 'signature_url']);

            foreach ($signatureRows as $row) {
                $signatureNames[$row->user_id][$row->signatory_role] = $row->full_name;
                $signatureUrls[$row->user_id][$row->signatory_role] = $row->signature_url;
            }
        }

        $resolvedSignatories = $signatories->map(function ($sig) use ($users, $members, $signatureNames, $signatureUrls) {
            $userId = $sig->signatory_user_id;
            $fullName = null;
            $signatureUrl = $sig->signature_url ?? null;

            if ($userId) {
                if (isset($signatureNames[$userId][$sig->signatory_role])) {
                    $fullName = $signatureNames[$userId][$sig->signatory_role];
                } elseif (isset($members[$userId]['name'])) {
                    $fullName = $members[$userId]['name'];
                } elseif (isset($users[$userId])) {
                    $meta = json_decode($users[$userId]['raw_user_meta_data'] ?? '{}', true);
                    $fullName = $meta['full_name'] ?? $meta['name'] ?? $users[$userId]['email'] ?? null;
                }

                if (!$signatureUrl && isset($signatureUrls[$userId][$sig->signatory_role])) {
                    $signatureUrl = $signatureUrls[$userId][$sig->signatory_role];
                }
            }

            if ($signatureUrl && $sig->status === 'approved') {
                $signatureUrl = $this->signatureService->resolveImage($signatureUrl)['data_uri'];
            }

            return (object) array_merge((array) $sig, ['full_name' => $fullName, 'signature_url' => $signatureUrl]);
        });

        $orgSettings = DB::table('organization_settings')->first();
        $orgName = $orgSettings->organization_name ?? 'KHCWW';

        $html = view('receipts.withdrawal', [
            'orgName' => $orgName,
            'withdrawal' => $withdrawal,
            'walletType' => $walletType,
            'signatories' => $resolvedSignatories,
            'messageReference' => $messageReference,
            'generatedAt' => now()->format('Y-m-d H:i:s'),
        ])->render();

        $path = 'receipts/withdrawal-' . $withdrawalId . '.html';
        if (!\Storage::disk('public')->put($path, $html)) {
            Log::error('Failed to persist canonical withdrawal receipt', ['withdrawal_id' => $withdrawalId]);
            throw new \RuntimeException('Could not persist the completed withdrawal receipt.');
        }

        return url('storage/' . $path);
    }

    public function getWithdrawalRecord(string $signatoryTable, string $withdrawalId): ?array
    {
        $walletType = $this->resolveWalletType($signatoryTable);
        $withdrawalTable = self::WALLET_TABLES[$walletType];

        $withdrawal = DB::table($withdrawalTable)->where('id', $withdrawalId)->first();

        if (!$withdrawal) {
            return null;
        }

        $signatories = DB::table($signatoryTable)
            ->where('withdrawal_id', $withdrawalId)
            ->get()
            ->map(fn ($s) => (array) $s)
            ->all();

        $result = (array) $withdrawal;
        $result['type'] = $walletType;
        $result['signatories'] = $signatories;

        return $this->normalizeNumericStrings([$result])[0] ?? $result;
    }

    protected function normalizeNumericStrings(array $rows): array
    {
        return array_map(function ($row) {
            if (! is_array($row)) {
                return $row;
            }

            foreach ($row as $key => $value) {
                if (is_string($value) && is_numeric($value)) {
                    if (str_contains($value, '.')) {
                        $row[$key] = (float) $value;
                    } else {
                        $row[$key] = (int) $value;
                    }
                } elseif (is_array($value)) {
                    $row[$key] = $this->normalizeNumericStrings([$value])[0] ?? $value;
                }
            }

            return $row;
        }, $rows);
    }

    protected function logAudit(array $identity, string $action, string $table, string $recordId, array $details): void
    {
        try {
            $userId = $identity['user_id'] ?? null;
            $superAdminId = in_array('super_admin', $identity['roles'] ?? [], true) ? $userId : null;

            AuditLog::create([
                'id' => Uuid::v4(),
                'super_admin_id' => $superAdminId ?? $userId ?? '00000000-0000-0000-0000-000000000000',
                'action' => $action,
                'target_user_id' => $userId,
                'details' => json_encode(array_merge($details, [
                    'table' => $table,
                    'record_id' => $recordId,
                ])),
                'ip_address' => request()->ip() ?? null,
                'created_at' => now(),
            ]);
        } catch (\Throwable $e) {
            // Audit logging should never break the main flow
        }
    }
}
