<?php

declare(strict_types=1);

namespace App\Services;

use App\Support\Uuid;
use DateTimeImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;

class PaymentEngine
{
    public function __construct(
        private readonly MemberFinancialProjectionService $memberFinancialProjection,
    ) {}

    public function finalizeProviderEvent(array $payload): array
    {
        return $this->processProviderEvent($payload);
    }

    public function processProviderEvent(array $payload): array
    {
        $provider = strtolower((string) ($payload['provider'] ?? $payload['provider_name'] ?? 'unknown'));
        $eventId = (string) ($payload['provider_event_id'] ?? $payload['provider_event'] ?? $payload['provider_reference'] ?? $payload['internal_reference'] ?? '');
        $memberId = (string) ($payload['member_id'] ?? '');

        if ($memberId === '' || ! DB::table('members')->where('id', $memberId)->exists()) {
            throw new InvalidArgumentException('Unknown member_id for canonical payment event.');
        }

        if ($eventId === '') {
            throw new InvalidArgumentException('A provider event id is required.');
        }

        return DB::transaction(function () use ($provider, $eventId, $memberId, $payload): array {
            $existingEvent = DB::table('payment_provider_events')->where('provider_name', $provider)->where('provider_event_id', $eventId)->first();
            if ($existingEvent && $existingEvent->payment_id) {
                $payment = DB::table('payments')->where('id', $existingEvent->payment_id)->first();
                if ($payment) {
                    $incomingAmount = $this->amountMinorFromPayload($payload);
                    $incomingReference = (string) ($payload['provider_reference'] ?? $payload['ReceiptNumber'] ?? $payload['transaction_reference'] ?? $eventId);
                    if ((string) $payment->member_id !== $memberId || (int) ($payment->amount_minor ?? 0) !== $incomingAmount || (string) ($payment->provider_reference ?? '') !== $incomingReference) {
                        $this->createFinancialException('conflicting_provider_event', $provider, [
                            'source_table' => 'payment_provider_events',
                            'source_id' => $existingEvent->id,
                            'provider_reference' => $incomingReference,
                            'member_id' => $memberId,
                            'payment_id' => $payment->id,
                            'payload' => $payload,
                        ]);
                    }

                    return $this->buildPaymentResponse($payment, true);
                }
            }

            $amountMinor = $this->amountMinorFromPayload($payload);
            $providerReference = (string) ($payload['provider_reference'] ?? $payload['ReceiptNumber'] ?? $payload['transaction_reference'] ?? $eventId);
            $internalReference = (string) ($payload['internal_reference'] ?? $providerReference);
            $fingerprint = (string) ($payload['fingerprint'] ?? md5($provider . ':' . $providerReference . ':' . $memberId . ':' . $amountMinor));
            $occurredAt = $this->normalizeDateTime($payload['occurred_at'] ?? $payload['received_at'] ?? now()->toDateTimeString());
            $status = $this->normalizeStatus($payload);

            $paymentId = $this->getOrCreatePayment($memberId, $providerReference, $internalReference, $fingerprint, $amountMinor, $provider, $status, $occurredAt, $payload);

            $this->recordProviderEvent($provider, $eventId, $paymentId, $status, $payload, $occurredAt);

            if ($status === 'pending') {
                return $this->buildPaymentResponse(DB::table('payments')->where('id', $paymentId)->first(), false);
            }

            if ($status === 'failed') {
                return $this->rejectFailedPayment($paymentId);
            }

            if ($status === 'reversed') {
                return $this->reversePayment($paymentId);
            }

            if ($status === 'confirmed') {
                return $this->finalizeSuccessfulPayment($paymentId, $memberId, $amountMinor, $occurredAt, $payload['allocations'] ?? []);
            }

            return $this->buildPaymentResponse(DB::table('payments')->where('id', $paymentId)->first(), false);
        });
    }

    public function createPendingPayment(string $memberId, string $provider, string $providerReference, string $internalReference, int $amountMinor, string $occurredAt = null, string $paymentType = 'monthly_contribution', ?string $relatedEntityId = null): array
    {
        $occurredAt = $this->normalizeDateTime($occurredAt ?? now()->toDateTimeString());
        $fingerprint = md5($provider . ':' . $providerReference . ':' . $memberId . ':' . $amountMinor);
        $paymentId = $this->getOrCreatePayment($memberId, $providerReference, $internalReference, $fingerprint, $amountMinor, $provider, 'pending', $occurredAt, [
            'provider' => $provider,
            'provider_reference' => $providerReference,
            'internal_reference' => $internalReference,
            'amount_minor' => $amountMinor,
            'occurred_at' => $occurredAt,
            'initiated_at' => $occurredAt,
            'source' => 'stk_' . $paymentType,
            'payment_type' => $paymentType,
            'related_entity_id' => $relatedEntityId,
        ]);

        DB::table('payments')->where('id', $paymentId)->update([
            'status' => 'pending',
            'updated_at' => now(),
        ]);

        return $this->buildPaymentResponse(DB::table('payments')->where('id', $paymentId)->first(), false);
    }

    public function updateProviderReference(string $paymentId, string $providerReference, ?string $providerRequestReference = null): array
    {
        $update = [
            'provider_reference' => $providerReference,
            'updated_at' => now(),
        ];
        if ($providerRequestReference !== null && Schema::hasColumn('payments', 'transaction_reference')) {
            $update['transaction_reference'] = $providerRequestReference;
        }

        DB::table('payments')->where('id', $paymentId)->update($update);

        return $this->buildPaymentResponse(DB::table('payments')->where('id', $paymentId)->first(), false);
    }

    public function updateProviderStatus(string $paymentId, string $status, ?string $reason = null, array $providerData = []): array
    {
        $payment = DB::table('payments')->where('id', $paymentId)->first();
        if (! $payment) {
            throw new InvalidArgumentException('Payment not found.');
        }

        $status = strtolower($status);
        if ($payment->status === 'reversed' && in_array($status, ['success', 'confirmed'], true)) {
            $this->createFinancialException('provider_status_conflict', $payment->provider_name ?? 'unknown', [
                'payment_id' => $payment->id,
                'provider_reference' => $payment->provider_reference,
                'member_id' => $payment->member_id,
                'payload' => $providerData,
            ]);
            return $this->buildPaymentResponse($payment, true) + ['status_message' => 'Reversed payment cannot be confirmed again.'];
        }
        if ($payment->status === 'confirmed' && in_array($status, ['failed', 'cancelled', 'reversed'], true)) {
            $this->createFinancialException('provider_status_conflict', $payment->provider_name ?? 'unknown', [
                'payment_id' => $payment->id,
                'provider_reference' => $payment->provider_reference,
                'member_id' => $payment->member_id,
                'payload' => $providerData,
            ]);
            return $this->buildPaymentResponse($payment, true) + ['status_message' => 'Confirmed payment status conflict.'];
        }
        if ($status === 'success' || $status === 'confirmed') {
            $result = $this->finalizeSuccessfulPayment(
                $paymentId,
                (string) $payment->member_id,
                (int) ($payment->amount_minor ?? round((float) $payment->amount * 100)),
                now()->toDateTimeString()
            );
            $this->recordStatusCheck($payment, 'confirmed', $reason, $providerData);
            return $result;
        }

        if (! in_array($status, ['pending', 'failed', 'cancelled', 'unknown', 'reversed'], true)) {
            $status = 'unknown';
        }

        $update = [
            'status' => $status,
            'updated_at' => now(),
        ];
        $this->addOptionalPaymentFields($update, [
            'status_message' => $reason,
            'provider_status' => $providerData['Status'] ?? $providerData['TransactionStatus'] ?? null,
            'last_status_checked_at' => now(),
            'failed_at' => in_array($status, ['failed', 'cancelled'], true) ? now() : null,
            'failure_reason' => in_array($status, ['failed', 'cancelled'], true) ? $reason : null,
        ]);
        DB::table('payments')->where('id', $paymentId)->update($update);
        $this->recordStatusCheck(DB::table('payments')->where('id', $paymentId)->first(), $status, $reason, $providerData);

        return $this->buildPaymentResponse(DB::table('payments')->where('id', $paymentId)->first(), false) + [
            'status_message' => $reason,
            'provider_data' => $providerData,
        ];
    }

    private function recordStatusCheck(object $payment, string $status, ?string $reason, array $providerData): void
    {
        if (! Schema::hasTable('payment_status_checks')) {
            return;
        }

        DB::table('payment_status_checks')->insert([
            'id' => Uuid::v4(),
            'payment_id' => $payment->id,
            'provider_name' => (string) ($payment->provider_name ?? 'mpesa'),
            'provider_reference' => $payment->provider_reference ?? null,
            'provider_status' => $providerData['Status'] ?? $providerData['TransactionStatus'] ?? null,
            'normalized_status' => $status,
            'status_message' => $reason,
            'response_json' => json_encode($providerData),
            'checked_at' => now(),
        ]);
    }

    public function getOrCreatePayment(string $memberId, string $providerReference, string $internalReference, string $fingerprint, int $amountMinor, string $provider, string $status, string $occurredAt, array $payload = []): string
    {
        $existing = $this->findCanonicalPayment($memberId, $providerReference, $internalReference, $fingerprint);
        if ($existing) {
            $payment = DB::table('payments')->where('id', $existing)->first();
            if ($payment && $status === 'confirmed' && $payment->status !== 'confirmed') {
                DB::table('payments')->where('id', $payment->id)->update([
                    'status' => 'confirmed',
                    'amount_minor' => $amountMinor,
                    'provider_name' => $provider,
                    'provider_reference' => $providerReference,
                    'internal_reference' => $internalReference,
                    'fingerprint' => $fingerprint,
                    'confirmed_at' => $occurredAt,
                    'updated_at' => now(),
                ]);
            }
            return $existing;
        }

        $paymentId = Uuid::v4();
        $insert = [
            'id' => $paymentId,
            'member_id' => $memberId,
            'amount' => $this->amountDecimalFromMinor($amountMinor),
            'source' => (string) ($payload['source'] ?? 'stk'),
            'matched' => 1,
            'status' => $status,
            'currency' => 'KES',
            'amount_minor' => $amountMinor,
            'provider_name' => $provider,
            'provider_reference' => $providerReference,
            'internal_reference' => $internalReference,
            'fingerprint' => $fingerprint,
            'received_at' => $occurredAt,
            'occurred_at' => $occurredAt,
                'initiated_at' => $occurredAt,
                'confirmed_at' => $status === 'confirmed' ? $occurredAt : null,
            'transaction_ref' => $providerReference,
            'transaction_reference' => Schema::hasColumn('payments', 'transaction_reference') ? $providerReference : null,
            'created_at' => now(),
            'updated_at' => now(),
        ];

        $this->addOptionalPaymentFields($insert, [
            'status' => $status,
            'currency' => 'KES',
            'amount_minor' => $amountMinor,
            'provider_name' => $provider,
            'provider_reference' => $providerReference,
            'internal_reference' => $internalReference,
            'fingerprint' => $fingerprint,
            'occurred_at' => $occurredAt,
            'confirmed_at' => $status === 'confirmed' ? $occurredAt : null,
            'payment_type' => $payload['payment_type'] ?? null,
            'related_entity_id' => $payload['related_entity_id'] ?? null,
            'transaction_ref' => $providerReference,
            'transaction_reference' => $providerReference,
        ]);

        DB::table('payments')->insert($insert);

        return $paymentId;
    }

    public function finalizeSuccessfulPayment(string $paymentId, string $memberId, int $amountMinor, ?string $occurredAt = null, array $allocations = []): array
    {
        $occurredAt = $this->normalizeDateTime($occurredAt ?? now()->toDateTimeString());
        $payment = DB::table('payments')->where('id', $paymentId)->first();

        if (! $payment) {
            throw new InvalidArgumentException('Payment not found.');
        }

        if ((string) $payment->status === 'confirmed') {
                if ($this->shouldAllocateContribution($payment)) {
                    $this->allocatePaymentSchedule($paymentId, $memberId, $allocations, $occurredAt, $amountMinor);
                }
            return $this->buildPaymentResponse($payment, true);
        }

        DB::table('payments')->where('id', $paymentId)->update([
            'status' => 'confirmed',
            'amount_minor' => $amountMinor,
            'confirmed_at' => $occurredAt,
            'updated_at' => now(),
        ]);

        $completionUpdate = [];
        $this->addOptionalPaymentFields($completionUpdate, [
            'completed_at' => $occurredAt,
            'last_status_checked_at' => now(),
        ]);
        if ($completionUpdate !== []) {
            DB::table('payments')->where('id', $paymentId)->update($completionUpdate);
        }

        if ($this->shouldAllocateContribution($payment)) {
            $this->allocatePaymentSchedule($paymentId, $memberId, $allocations, $occurredAt, $amountMinor);
        }

        $updated = DB::table('payments')->where('id', $paymentId)->first();
        $memberName = DB::table('members')->where('id', $memberId)->value('name') ?: 'A member';
        $reference = (string) ($updated->provider_reference ?? $updated->transaction_ref ?? $paymentId);
        app(AdminNotificationService::class)->notifyAdmins(
            'New confirmed transaction',
            "{$memberName} paid KES " . number_format($amountMinor / 100, 2) . " (reference {$reference}).",
            'new_transaction',
            '/admin/payments',
            'payment-confirmed:' . (string) $paymentId
        );

        return $this->buildPaymentResponse($updated, false);
    }

    private function shouldAllocateContribution(object $payment): bool
    {
        $paymentType = $payment->payment_type ?? null;
        if ($paymentType !== null && (string) $paymentType !== 'monthly_contribution') {
            return false;
        }

        return in_array((string) ($payment->source ?? ''), [
            'stk',
            'stk_monthly_contribution',
            'bank_sync',
            'bank_import',
            'bank_reconciliation',
            'bank_manual_reconciliation',
            'legacy_reconciliation',
            'manual',
        ], true)
            || (string) $paymentType === 'monthly_contribution';
    }

    public function rejectFailedPayment(string $paymentId): array
    {
        $payment = DB::table('payments')->where('id', $paymentId)->first();
        if (! $payment) {
            throw new InvalidArgumentException('Payment not found.');
        }

        DB::table('payments')->where('id', $paymentId)->update([
            'status' => 'failed',
            'updated_at' => now(),
        ]);

        return $this->buildPaymentResponse(DB::table('payments')->where('id', $paymentId)->first(), false);
    }

    public function allocatePayment(string $paymentId, string $memberId, int $amountMinor, ?string $occurredAt = null): void
    {
        $this->allocatePaymentSchedule($paymentId, $memberId, [], $occurredAt, $amountMinor);
    }

    public function allocatePaymentSchedule(string $paymentId, string $memberId, array $allocations, ?string $occurredAt = null, ?int $paymentAmountMinor = null): array
    {
        if ($paymentAmountMinor === null) {
            $paymentAmountMinor = (int) DB::table('payments')->where('id', $paymentId)->value('amount_minor');
        }

        if ($paymentAmountMinor <= 0 || ! Schema::hasTable('monthly_obligations') || ! Schema::hasTable('contribution_allocations')) {
            return [];
        }

        $occurredAt = $this->normalizeDateTime($occurredAt ?? now()->toDateTimeString());
        $allocations = $allocations !== []
            ? $allocations
            : $this->buildDefaultMonthlyAllocations($paymentAmountMinor, $occurredAt);

        return DB::transaction(function () use ($paymentId, $memberId, $allocations, $occurredAt, $paymentAmountMinor): array {
            $alreadyAllocated = (int) DB::table('contribution_allocations')->where('payment_id', $paymentId)->sum('amount_minor');
            $remaining = max(0, $paymentAmountMinor - $alreadyAllocated);
            $created = [];

            foreach ($allocations as $allocation) {
                if ($remaining <= 0) {
                    break;
                }

                $amount = max(0, (int) ($allocation['amount_minor'] ?? 0));
                $amount = min($amount, $remaining);
                if ($amount <= 0) {
                    continue;
                }

                $year = (int) ($allocation['year'] ?? 0);
                $month = (int) ($allocation['month'] ?? 0);
                if ($year < 2000 || $month < 1 || $month > 12) {
                    throw new InvalidArgumentException('A valid obligation year and month are required.');
                }

                $obligationId = $this->ensureMonthlyObligation($memberId, $year, $month, (int) ($allocation['required_amount_minor'] ?? $amount));
                $obligation = DB::table('monthly_obligations')->where('id', $obligationId)->first();
                $obligationRemaining = max(0, (int) $obligation->required_amount_minor - (int) $obligation->paid_amount_minor);
                $amount = min($amount, $obligationRemaining);
                if ($amount <= 0) {
                    continue;
                }
                $existing = DB::table('contribution_allocations')->where('payment_id', $paymentId)->where('obligation_id', $obligationId)->first();
                if ($existing) {
                    continue;
                }

                $allocationId = Uuid::v4();
                DB::table('contribution_allocations')->insert([
                    'id' => $allocationId,
                    'payment_id' => $paymentId,
                    'member_id' => $memberId,
                    'obligation_id' => $obligationId,
                    'amount_minor' => $amount,
                    'allocation_type' => (string) ($allocation['allocation_type'] ?? 'monthly'),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                $this->refreshObligationPaidAmount($obligationId);
                $remaining -= $amount;
                $created[] = $allocationId;
            }

            if ($remaining > 0) {
                $this->createCarryForward($memberId, $paymentId, $remaining);
            }

            $this->memberFinancialProjection->syncMember($memberId);

            return $created;
        });
    }

    private function buildDefaultMonthlyAllocations(int $amountMinor, string $occurredAt): array
    {
        $monthlyMinor = (int) round(((float) (DB::table('welfare_settings')->value('monthly_contribution_amount') ?? 300)) * 100);
        $monthlyMinor = max(1, $monthlyMinor);
        $remaining = $amountMinor;
        $cursor = new DateTimeImmutable($occurredAt);
        $allocations = [];

        while ($remaining > 0 && count($allocations) < 240) {
            $amount = min($monthlyMinor, $remaining);
            $allocations[] = [
                'year' => (int) $cursor->format('Y'),
                'month' => (int) $cursor->format('n'),
                'amount_minor' => $amount,
                'required_amount_minor' => $monthlyMinor,
            ];
            $remaining -= $amount;
            $cursor = $cursor->modify('+1 month');
        }

        return $allocations;
    }

    public function createFinancialException(string $type, string $source, array $details = []): string
    {
        $id = Uuid::v4();
        DB::table('financial_exceptions')->insert([
            'id' => $id,
            'type' => $type,
            'source' => $source,
            'source_table' => $details['source_table'] ?? null,
            'source_id' => $details['source_id'] ?? null,
            'external_reference' => $details['external_reference'] ?? $details['provider_reference'] ?? null,
            'member_id' => $details['member_id'] ?? null,
            'member_reference' => $details['member_reference'] ?? null,
            'payment_id' => $details['payment_id'] ?? null,
            'payload_json' => json_encode($details['payload'] ?? $details),
            'status' => 'open',
            'investigation_status' => 'open',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $id;
    }

    private function ensureMonthlyObligation(string $memberId, int $year, int $month, int $requiredAmountMinor): string
    {
        $existing = DB::table('monthly_obligations')
            ->where('member_id', $memberId)
            ->where('period_year', $year)
            ->where('period_month', $month)
            ->first();

        if ($existing) {
            if ($requiredAmountMinor > (int) $existing->required_amount_minor) {
                DB::table('monthly_obligations')->where('id', $existing->id)->update([
                    'required_amount_minor' => $requiredAmountMinor,
                    'updated_at' => now(),
                ]);
            }

            return $existing->id;
        }

        $id = Uuid::v4();
        DB::table('monthly_obligations')->insert([
            'id' => $id,
            'member_id' => $memberId,
            'period_year' => $year,
            'period_month' => $month,
            'required_amount_minor' => max(0, $requiredAmountMinor),
            'paid_amount_minor' => 0,
            'status' => 'unpaid',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $id;
    }

    private function refreshObligationPaidAmount(string $obligationId): void
    {
        $obligation = DB::table('monthly_obligations')->where('id', $obligationId)->first();
        if (! $obligation) {
            return;
        }

        $paid = (int) DB::table('contribution_allocations')->where('obligation_id', $obligationId)->sum('amount_minor');
        $required = (int) $obligation->required_amount_minor;
        $status = $paid <= 0 ? 'unpaid' : ($paid < $required ? 'partial' : 'paid');
        DB::table('monthly_obligations')->where('id', $obligationId)->update([
            'paid_amount_minor' => $paid,
            'status' => $status,
            'updated_at' => now(),
        ]);
    }

    private function createCarryForward(string $memberId, string $paymentId, int $amountMinor): ?string
    {
        if ($amountMinor <= 0 || ! Schema::hasTable('carry_forward_balances') || ! Schema::hasTable('carry_forward_movements')) {
            return null;
        }

        $existing = DB::table('carry_forward_balances')->where('source_payment_id', $paymentId)->first();
        if ($existing) {
            return $existing->id;
        }

        $id = Uuid::v4();
        DB::table('carry_forward_balances')->insert([
            'id' => $id,
            'member_id' => $memberId,
            'source_payment_id' => $paymentId,
            'amount_minor' => $amountMinor,
            'remaining_amount_minor' => $amountMinor,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('carry_forward_movements')->insert([
            'id' => Uuid::v4(),
            'member_id' => $memberId,
            'source_payment_id' => $paymentId,
            'movement_type' => 'created',
            'amount_minor' => $amountMinor,
            'idempotency_key' => 'create:' . $paymentId,
            'created_at' => now(),
        ]);

        return $id;
    }

    public function consumeCarryForward(string $carryForwardId, string $paymentId, string $memberId, string $obligationId, int $amountMinor): string
    {
        return DB::transaction(function () use ($carryForwardId, $paymentId, $memberId, $obligationId, $amountMinor): string {
            $carry = DB::table('carry_forward_balances')->where('id', $carryForwardId)->lockForUpdate()->first();
            $key = 'consume:' . $carryForwardId . ':' . $obligationId;
            $existing = DB::table('carry_forward_movements')->where('idempotency_key', $key)->first();
            if ($existing) {
                return $existing->allocation_id ?: $existing->id;
            }

            if (! $carry || $carry->member_id !== $memberId || $amountMinor <= 0 || $amountMinor > (int) $carry->remaining_amount_minor) {
                throw new InvalidArgumentException('Carry-forward amount is unavailable.');
            }

            $allocationId = Uuid::v4();
            DB::table('contribution_allocations')->insert([
                'id' => $allocationId,
                'payment_id' => $paymentId,
                'member_id' => $memberId,
                'obligation_id' => $obligationId,
                'amount_minor' => $amountMinor,
                'allocation_type' => 'carry_forward',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            DB::table('carry_forward_balances')->where('id', $carryForwardId)->update([
                'remaining_amount_minor' => (int) $carry->remaining_amount_minor - $amountMinor,
                'consumed_at' => (int) $carry->remaining_amount_minor === $amountMinor ? now() : null,
                'updated_at' => now(),
            ]);
            DB::table('carry_forward_movements')->insert([
                'id' => Uuid::v4(),
                'member_id' => $memberId,
                'source_carry_forward_id' => $carryForwardId,
                'allocation_id' => $allocationId,
                'movement_type' => 'consumed',
                'amount_minor' => $amountMinor,
                'idempotency_key' => $key,
                'created_at' => now(),
            ]);

            return $allocationId;
        });
    }

    public function reversePayment(string $paymentId): array
    {
        $payment = DB::table('payments')->where('id', $paymentId)->first();
        if (! $payment) {
            throw new InvalidArgumentException('Payment not found.');
        }

        DB::table('payments')->where('id', $paymentId)->update([
            'status' => 'reversed',
            'updated_at' => now(),
        ]);

        return [
            'payment_id' => $paymentId,
            'status' => 'reversed',
            'idempotent' => false,
        ];
    }

    private function recordProviderEvent(string $provider, string $eventId, string $paymentId, string $status, array $payload, string $occurredAt): void
    {
        $eventPayload = is_array($payload['payload'] ?? null) ? $payload['payload'] : $payload;
        $row = DB::table('payment_provider_events')->where('provider_name', $provider)->where('provider_event_id', $eventId)->first();

        if ($row) {
            DB::table('payment_provider_events')->where('id', $row->id)->update([
                'payment_id' => $paymentId,
                'normalized_status' => $status,
                'payload_json' => json_encode($eventPayload),
                'processed_at' => now()->toDateTimeString(),
                'updated_at' => now(),
            ]);

            return;
        }

        DB::table('payment_provider_events')->insert([
            'id' => Uuid::v4(),
            'payment_id' => $paymentId,
            'provider_name' => $provider,
            'provider_event_id' => $eventId,
            'event_type' => (string) ($payload['event_type'] ?? 'stk_success'),
            'payload_json' => json_encode($eventPayload),
            'normalized_status' => $status,
            'received_at' => $occurredAt,
            'processed_at' => now()->toDateTimeString(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function buildPaymentResponse(object $payment, bool $idempotent): array
    {
        $status = (string) ($payment->status ?? 'pending');

        return [
            'payment_id' => $payment->id,
            'member_id' => $payment->member_id,
            'status' => $status,
            'amount_minor' => (int) ($payment->amount_minor ?? round((float) ($payment->amount ?? 0) * 100)),
            'provider_name' => $payment->provider_name ?? '',
            'provider_reference' => $payment->provider_reference ?? '',
            'internal_reference' => $payment->internal_reference ?? '',
            'idempotent' => $idempotent,
        ];
    }

    private function normalizeStatus(array $payload): string
    {
        $eventType = strtolower((string) ($payload['event_type'] ?? ''));
        $payloadData = is_array($payload['payload'] ?? null) ? $payload['payload'] : [];

        $resultCode = $payloadData['ResultCode'] ?? $payloadData['result_code'] ?? $payload['result_code'] ?? null;
        $transactionStatus = $payloadData['TransactionStatus'] ?? $payloadData['transaction_status'] ?? $payload['transaction_status'] ?? null;

        if (str_contains($eventType, 'success') || str_contains($eventType, 'confirmed') || $resultCode === 0 || $transactionStatus === 'success' || $transactionStatus === 'confirmed') {
            return 'confirmed';
        }

        if (str_contains($eventType, 'pending') || $transactionStatus === 'pending') {
            return 'pending';
        }

        if (str_contains($eventType, 'failed') || ($resultCode !== null && (int) $resultCode !== 0) || $transactionStatus === 'failed') {
            return 'failed';
        }

        if (str_contains($eventType, 'revers')) {
            return 'reversed';
        }

        return 'pending';
    }

    private function normalizeDateTime(string $value): string
    {
        if ($value === '') {
            return now()->toDateTimeString();
        }

        try {
            $date = new DateTimeImmutable($value);
            return $date->format('Y-m-d H:i:s');
        } catch (\Exception) {
            return now()->toDateTimeString();
        }
    }

    private function amountMinorFromPayload(array $payload): int
    {
        $minorValue = $payload['amount_minor'] ?? null;
        if ($minorValue !== null && $minorValue !== '') {
            $minor = (float) str_replace([',', ' '], '', (string) $minorValue);
            return max(0, (int) round($minor));
        }

        $amount = $payload['amount'] ?? 0;
        if (is_string($amount)) {
            $amount = str_replace([',', ' '], '', $amount);
        }

        $numeric = (float) $amount;
        return max(0, (int) round($numeric * 100));
    }

    private function amountDecimalFromMinor(int $amountMinor): float
    {
        return round($amountMinor / 100, 2);
    }

    private function findCanonicalPayment(string $memberId, string $providerReference, string $internalReference, string $fingerprint): ?string
    {
        $query = DB::table('payments')->where('member_id', $memberId);
        if ($providerReference !== '') {
            $query->where(function ($builder) use ($providerReference, $internalReference, $fingerprint): void {
                $builder->where('provider_reference', $providerReference)
                    ->orWhere('internal_reference', $internalReference)
                    ->orWhere('fingerprint', $fingerprint)
                    ->orWhere('transaction_ref', $providerReference);

                if (Schema::hasColumn('payments', 'transaction_reference')) {
                    $builder->orWhere('transaction_reference', $providerReference);
                }
            });
        }

        $payment = $query->first();
        return $payment?->id;
    }

    private function upsertMonthlyObligation(string $memberId, int $amountMinor, string $occurredAt): ?string
    {
        $date = new DateTimeImmutable($occurredAt);
        $year = (int) $date->format('Y');
        $month = (int) $date->format('n');

        $existing = DB::table('monthly_obligations')->where('member_id', $memberId)->where('period_year', $year)->where('period_month', $month)->first();
        if ($existing) {
            $required = max((int) ($existing->required_amount_minor ?? 0), $amountMinor);
            $paid = (int) ($existing->paid_amount_minor ?? 0) + $amountMinor;
            DB::table('monthly_obligations')->where('id', $existing->id)->update([
                'required_amount_minor' => $required,
                'paid_amount_minor' => $paid,
                'status' => $paid >= $required ? 'paid' : 'partial',
                'updated_at' => now(),
            ]);

            return $existing->id;
        }

        $obligationId = Uuid::v4();
        $payload = [
            'id' => $obligationId,
            'member_id' => $memberId,
            'period_year' => $year,
            'period_month' => $month,
            'required_amount_minor' => $amountMinor,
            'paid_amount_minor' => $amountMinor,
            'status' => 'paid',
            'created_at' => now(),
            'updated_at' => now(),
        ];

        DB::table('monthly_obligations')->insert($payload);

        return $obligationId;
    }

    private function addOptionalPaymentFields(array &$destination, array $values): void
    {
        foreach ($values as $column => $value) {
            if ($value === null) {
                continue;
            }

            if (Schema::hasColumn('payments', $column)) {
                $destination[$column] = $value;
            }
        }
    }
}
