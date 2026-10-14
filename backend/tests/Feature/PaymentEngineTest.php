<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\PaymentEngine;
use App\Support\Uuid;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class PaymentEngineTest extends TestCase
{
    use RefreshDatabase;

    private function createMember(string $phone = '+254700000001', string $name = 'Canonical Member'): string
    {
        $userId = Uuid::v4();
        $memberId = Uuid::v4();

        DB::table('auth_users')->insert([
            'id' => $userId,
            'email' => str_replace('+', '', $phone) . '@welfare.local',
            'phone' => $phone,
            'encrypted_password' => \App\Services\AuthService::hashPassword('Secret123'),
            'must_change_password' => 0,
            'is_banned' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('members')->insert([
            'id' => $memberId,
            'user_id' => $userId,
            'name' => $name,
            'phone' => $phone,
            'member_id' => $memberId,
            'status' => 'active',
            'is_active' => 1,
            'total_contributions' => 0,
            'total_penalties' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $memberId;
    }

    public function test_pending_provider_event_remains_pending_without_allocation(): void
    {
        $memberId = $this->createMember();
        $engine = app(PaymentEngine::class);

        $result = $engine->processProviderEvent([
            'provider' => 'mpesa',
            'provider_event_id' => 'evt-pending-1',
            'event_type' => 'stk_pending',
            'member_id' => $memberId,
            'amount_minor' => 30000,
            'provider_reference' => 'MPESA-REF-PENDING',
            'internal_reference' => 'stk-pending-1',
            'occurred_at' => '2026-01-15 10:00:00',
            'payload' => ['ResultCode' => 1032],
        ]);

        $this->assertSame('pending', $result['status']);
        $this->assertSame('pending', DB::table('payments')->where('provider_reference', 'MPESA-REF-PENDING')->value('status'));
        $this->assertSame(0, DB::table('monthly_obligations')->where('member_id', $memberId)->count());
    }

    public function test_failed_provider_event_does_not_allocate(): void
    {
        $memberId = $this->createMember();
        $engine = app(PaymentEngine::class);

        $result = $engine->processProviderEvent([
            'provider' => 'mpesa',
            'provider_event_id' => 'evt-failed-1',
            'event_type' => 'stk_failed',
            'member_id' => $memberId,
            'amount_minor' => 30000,
            'provider_reference' => 'MPESA-REF-FAILED',
            'internal_reference' => 'stk-failed-1',
            'occurred_at' => '2026-01-15 10:00:00',
            'payload' => ['ResultCode' => 1],
        ]);

        $this->assertSame('failed', $result['status']);
        $this->assertSame('failed', DB::table('payments')->where('provider_reference', 'MPESA-REF-FAILED')->value('status'));
        $this->assertSame(0, DB::table('monthly_obligations')->where('member_id', $memberId)->count());
    }

    public function test_non_contribution_payment_types_never_allocate_for_generic_sources(): void
    {
        foreach (['stk', 'manual'] as $source) {
            $memberId = $this->createMember('+' . (700000100 + random_int(1, 899)), 'Non-contribution ' . $source);
            $result = app(PaymentEngine::class)->processProviderEvent([
                'provider' => 'test',
                'provider_event_id' => 'evt-' . $source,
                'event_type' => 'confirmed',
                'member_id' => $memberId,
                'amount_minor' => 30000,
                'provider_reference' => 'REF-' . $source,
                'internal_reference' => 'INT-' . $source,
                'source' => $source,
                'payment_type' => 'operational',
                'status' => 'confirmed',
            ]);

            $this->assertSame('confirmed', $result['status']);
            $this->assertSame(0, DB::table('monthly_obligations')->where('member_id', $memberId)->count());
            $this->assertSame(0, DB::table('contribution_allocations')->where('member_id', $memberId)->count());
        }
    }

    public function test_monthly_contribution_payment_type_allocates_with_generic_source(): void
    {
        $memberId = $this->createMember();
        $result = app(PaymentEngine::class)->processProviderEvent([
            'provider' => 'test',
            'provider_event_id' => 'evt-monthly-generic',
            'event_type' => 'confirmed',
            'member_id' => $memberId,
            'amount_minor' => 30000,
            'provider_reference' => 'REF-MONTHLY-GENERIC',
            'internal_reference' => 'INT-MONTHLY-GENERIC',
            'source' => 'manual',
            'payment_type' => 'monthly_contribution',
            'status' => 'confirmed',
            'occurred_at' => '2026-01-15 10:00:00',
        ]);

        $this->assertSame('confirmed', $result['status']);
        $this->assertSame(1, DB::table('contribution_allocations')->where('member_id', $memberId)->count());
    }

    public function test_provider_event_is_idempotent_and_writes_canonical_payment(): void
    {
        $memberId = $this->createMember();
        $engine = app(PaymentEngine::class);

        $payload = [
            'provider' => 'mpesa',
            'provider_event_id' => 'evt-1001',
            'event_type' => 'stk_success',
            'member_id' => $memberId,
            'amount_minor' => 30000,
            'provider_reference' => 'MPESA-REF-1001',
            'internal_reference' => 'stk-1001',
            'occurred_at' => '2026-01-15 10:00:00',
            'payload' => [
                'ReceiptNumber' => 'MPESA-REF-1001',
                'ResultCode' => 0,
            ],
        ];

        $first = $engine->processProviderEvent($payload);
        $second = $engine->processProviderEvent($payload);

        $this->assertSame($first['payment_id'], $second['payment_id']);
        $this->assertSame(1, DB::table('payment_provider_events')->where('provider_name', 'mpesa')->where('provider_event_id', 'evt-1001')->count());
        $this->assertSame(1, DB::table('payments')->where('member_id', $memberId)->count());
        $this->assertSame('confirmed', DB::table('payments')->where('id', $first['payment_id'])->value('status'));
        $this->assertSame(30000, DB::table('monthly_obligations')->where('member_id', $memberId)->where('period_year', 2026)->where('period_month', 1)->value('required_amount_minor'));
        $this->assertSame(1, DB::table('contribution_allocations')->where('payment_id', $first['payment_id'])->count());
    }

    public function test_same_provider_reference_cannot_create_duplicate_payment(): void
    {
        $memberId = $this->createMember();
        $engine = app(PaymentEngine::class);

        $first = $engine->processProviderEvent([
            'provider' => 'mpesa',
            'provider_event_id' => 'evt-dup-1',
            'event_type' => 'stk_success',
            'member_id' => $memberId,
            'amount_minor' => 30000,
            'provider_reference' => 'MPESA-REF-DUP',
            'internal_reference' => 'stk-dup-1',
            'occurred_at' => '2026-01-15 10:00:00',
            'payload' => ['ReceiptNumber' => 'MPESA-REF-DUP', 'ResultCode' => 0],
        ]);

        $second = $engine->processProviderEvent([
            'provider' => 'mpesa',
            'provider_event_id' => 'evt-dup-2',
            'event_type' => 'stk_success',
            'member_id' => $memberId,
            'amount_minor' => 30000,
            'provider_reference' => 'MPESA-REF-DUP',
            'internal_reference' => 'stk-dup-2',
            'occurred_at' => '2026-01-16 10:00:00',
            'payload' => ['ReceiptNumber' => 'MPESA-REF-DUP', 'ResultCode' => 0],
        ]);

        $this->assertSame($first['payment_id'], $second['payment_id']);
        $this->assertSame(1, DB::table('payments')->where('member_id', $memberId)->where('provider_reference', 'MPESA-REF-DUP')->count());
    }

    public function test_unknown_member_is_rejected_without_creating_unmatched_payment(): void
    {
        $engine = app(PaymentEngine::class);

        $this->expectException(\InvalidArgumentException::class);

        $engine->processProviderEvent([
            'provider' => 'mpesa',
            'provider_event_id' => 'evt-unknown',
            'event_type' => 'stk_success',
            'member_id' => Uuid::v4(),
            'amount_minor' => 15000,
            'provider_reference' => 'MPESA-REF-UNKNOWN',
            'internal_reference' => 'stk-unknown',
            'occurred_at' => '2026-01-16 09:00:00',
            'payload' => ['ReceiptNumber' => 'MPESA-REF-UNKNOWN'],
        ]);
    }

    public function test_unknown_member_does_not_create_member_or_user(): void
    {
        $engine = app(PaymentEngine::class);
        $memberId = Uuid::v4();
        $memberCount = DB::table('members')->count();
        $userCount = DB::table('auth_users')->count();

        try {
            $engine->processProviderEvent([
                'provider' => 'mpesa',
                'provider_event_id' => 'evt-unknown-no-create',
                'event_type' => 'stk_success',
                'member_id' => $memberId,
                'amount_minor' => 15000,
                'provider_reference' => 'MPESA-REF-UNKNOWN-NO-CREATE',
            ]);
        } catch (\InvalidArgumentException) {
        }

        $this->assertSame($memberCount, DB::table('members')->count());
        $this->assertSame($userCount, DB::table('auth_users')->count());
    }

    public function test_one_payment_can_cover_multiple_obligations(): void
    {
        $memberId = $this->createMember();
        $result = app(PaymentEngine::class)->processProviderEvent([
            'provider' => 'mpesa',
            'provider_event_id' => 'evt-multi-month',
            'event_type' => 'stk_success',
            'member_id' => $memberId,
            'amount_minor' => 60000,
            'provider_reference' => 'MPESA-REF-MULTI',
            'allocations' => [
                ['year' => 2026, 'month' => 1, 'amount_minor' => 30000],
                ['year' => 2026, 'month' => 2, 'amount_minor' => 30000],
            ],
            'occurred_at' => '2026-01-15 10:00:00',
        ]);

        $this->assertCount(2, DB::table('contribution_allocations')->where('payment_id', $result['payment_id'])->get());
        $this->assertSame(2, DB::table('monthly_obligations')->where('member_id', $memberId)->count());
        $this->assertSame(2, DB::table('monthly_obligations')->where('member_id', $memberId)->where('status', 'paid')->count());
    }

    public function test_partial_payments_accumulate_without_duplicate_finalization(): void
    {
        $memberId = $this->createMember();
        $engine = app(PaymentEngine::class);

        $engine->processProviderEvent([
            'provider' => 'mpesa',
            'provider_event_id' => 'evt-partial-1',
            'event_type' => 'stk_success',
            'member_id' => $memberId,
            'amount_minor' => 10000,
            'provider_reference' => 'MPESA-REF-PARTIAL-1',
            'allocations' => [['year' => 2026, 'month' => 3, 'amount_minor' => 10000, 'required_amount_minor' => 30000]],
            'occurred_at' => '2026-03-10 10:00:00',
        ]);
        $second = $engine->processProviderEvent([
            'provider' => 'mpesa',
            'provider_event_id' => 'evt-partial-2',
            'event_type' => 'stk_success',
            'member_id' => $memberId,
            'amount_minor' => 20000,
            'provider_reference' => 'MPESA-REF-PARTIAL-2',
            'allocations' => [['year' => 2026, 'month' => 3, 'amount_minor' => 20000, 'required_amount_minor' => 30000]],
            'occurred_at' => '2026-03-11 10:00:00',
        ]);
        $engine->processProviderEvent([
            'provider' => 'mpesa',
            'provider_event_id' => 'evt-partial-2',
            'event_type' => 'stk_success',
            'member_id' => $memberId,
            'amount_minor' => 20000,
            'provider_reference' => 'MPESA-REF-PARTIAL-2',
            'allocations' => [['year' => 2026, 'month' => 3, 'amount_minor' => 20000, 'required_amount_minor' => 30000]],
            'occurred_at' => '2026-03-11 10:00:00',
        ]);

        $obligation = DB::table('monthly_obligations')->where('member_id', $memberId)->where('period_year', 2026)->where('period_month', 3)->first();
        $this->assertSame('paid', $obligation->status);
        $this->assertSame(30000, (int) $obligation->paid_amount_minor);
        $this->assertSame(1, DB::table('contribution_allocations')->where('payment_id', $second['payment_id'])->count());
    }

    public function test_allocation_cannot_exceed_payment_amount_and_creates_carry_forward(): void
    {
        $memberId = $this->createMember();
        $result = app(PaymentEngine::class)->processProviderEvent([
            'provider' => 'mpesa',
            'provider_event_id' => 'evt-overallocation',
            'event_type' => 'stk_success',
            'member_id' => $memberId,
            'amount_minor' => 30000,
            'provider_reference' => 'MPESA-REF-OVERALLOC',
            'allocations' => [['year' => 2026, 'month' => 4, 'amount_minor' => 50000]],
            'occurred_at' => '2026-04-10 10:00:00',
        ]);

        $this->assertSame(30000, (int) DB::table('contribution_allocations')->where('payment_id', $result['payment_id'])->sum('amount_minor'));
        $this->assertSame(0, DB::table('carry_forward_balances')->where('source_payment_id', $result['payment_id'])->count());
    }

    public function test_carry_forward_is_created_and_cannot_be_consumed_twice(): void
    {
        $memberId = $this->createMember();
        $engine = app(PaymentEngine::class);
        $result = $engine->processProviderEvent([
            'provider' => 'mpesa',
            'provider_event_id' => 'evt-carry',
            'event_type' => 'stk_success',
            'member_id' => $memberId,
            'amount_minor' => 40000,
            'provider_reference' => 'MPESA-REF-CARRY',
            'allocations' => [['year' => 2026, 'month' => 5, 'amount_minor' => 30000]],
            'occurred_at' => '2026-05-10 10:00:00',
        ]);
        $carry = DB::table('carry_forward_balances')->where('source_payment_id', $result['payment_id'])->first();
        $obligationId = Uuid::v4();
        DB::table('monthly_obligations')->insert([
            'id' => $obligationId,
            'member_id' => $memberId,
            'period_year' => 2026,
            'period_month' => 6,
            'required_amount_minor' => 30000,
            'paid_amount_minor' => 0,
            'status' => 'unpaid',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $first = $engine->consumeCarryForward($carry->id, $result['payment_id'], $memberId, $obligationId, 10000);
        $second = $engine->consumeCarryForward($carry->id, $result['payment_id'], $memberId, $obligationId, 10000);

        $this->assertSame($first, $second);
        $this->assertSame(0, (int) DB::table('carry_forward_balances')->where('id', $carry->id)->value('remaining_amount_minor'));
        $this->assertSame(1, DB::table('carry_forward_movements')->where('source_carry_forward_id', $carry->id)->where('movement_type', 'consumed')->count());
    }

    public function test_conflicting_provider_event_creates_financial_exception(): void
    {
        $memberId = $this->createMember();
        $engine = app(PaymentEngine::class);
        $payload = [
            'provider' => 'mpesa',
            'provider_event_id' => 'evt-conflict',
            'event_type' => 'stk_success',
            'member_id' => $memberId,
            'amount_minor' => 30000,
            'provider_reference' => 'MPESA-REF-CONFLICT',
        ];
        $engine->processProviderEvent($payload);
        $payload['amount_minor'] = 40000;
        $engine->processProviderEvent($payload);

        $this->assertSame(1, DB::table('financial_exceptions')->where('type', 'conflicting_provider_event')->count());
        $this->assertSame('open', DB::table('financial_exceptions')->where('type', 'conflicting_provider_event')->value('investigation_status'));
    }

    public function test_reversed_provider_event_is_recorded_without_new_allocation(): void
    {
        $memberId = $this->createMember();
        $result = app(PaymentEngine::class)->processProviderEvent([
            'provider' => 'mpesa',
            'provider_event_id' => 'evt-reversed',
            'event_type' => 'payment_reversed',
            'member_id' => $memberId,
            'amount_minor' => 30000,
            'provider_reference' => 'MPESA-REF-REVERSED',
            'occurred_at' => '2026-06-10 10:00:00',
        ]);

        $this->assertSame('reversed', $result['status']);
        $this->assertSame(0, DB::table('contribution_allocations')->where('payment_id', $result['payment_id'])->count());
        $this->assertSame('reversed', DB::table('payments')->where('id', $result['payment_id'])->value('status'));
    }
}
