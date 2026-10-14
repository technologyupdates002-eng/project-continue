<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\AuthService;
use App\Services\FinancialReadModel;
use App\Services\PaymentEngine;
use App\Support\Uuid;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class FinancialReadModelTest extends TestCase
{
    use RefreshDatabase;

    private function createMember(): string
    {
        $userId = Uuid::v4();
        $memberId = Uuid::v4();
        DB::table('auth_users')->insert([
            'id' => $userId,
            'email' => $memberId . '@welfare.local',
            'phone' => '+254700000099',
            'encrypted_password' => AuthService::hashPassword('Secret123'),
            'must_change_password' => 0,
            'is_banned' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('members')->insert([
            'id' => $memberId,
            'user_id' => $userId,
            'name' => 'Read Model Member',
            'phone' => '+254700000099',
            'member_id' => $memberId,
            'status' => 'active',
            'is_active' => 1,
            'total_contributions' => 999999,
            'total_penalties' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $memberId;
    }

    public function test_member_overview_reads_canonical_financial_totals(): void
    {
        $memberId = $this->createMember();
        $engine = app(PaymentEngine::class);
        $engine->processProviderEvent([
            'provider' => 'mpesa',
            'provider_event_id' => 'read-model-success',
            'event_type' => 'stk_success',
            'member_id' => $memberId,
            'amount_minor' => 60000,
            'provider_reference' => 'READ-MODEL-600',
            'allocations' => [
                ['year' => 2026, 'month' => 7, 'amount_minor' => 30000],
                ['year' => 2026, 'month' => 8, 'amount_minor' => 30000],
            ],
            'occurred_at' => '2026-07-10 10:00:00',
        ]);
        $engine->createPendingPayment($memberId, 'mpesa', 'READ-MODEL-PENDING', 'pending', 50000);
        $engine->createFinancialException('unknown_bank_transaction', 'bank', [
            'member_id' => $memberId,
            'source_table' => 'bank_transactions',
            'source_id' => 'bank-1',
            'member_reference' => 'READ-MODEL-MEMBER',
        ]);

        $overview = app(FinancialReadModel::class)->memberOverview($memberId);

        $this->assertSame(1, $overview['confirmed_payment_count']);
        $this->assertSame(60000, $overview['confirmed_payment_minor']);
        $this->assertSame(60000, $overview['allocated_minor']);
        $this->assertSame(2, $overview['obligation_counts']['paid']);
        $this->assertSame(1, $overview['open_exception_count']);
    }

    public function test_statement_and_obligations_are_canonical_and_payment_allocations_are_visible(): void
    {
        $memberId = $this->createMember();
        $result = app(PaymentEngine::class)->processProviderEvent([
            'provider' => 'bank',
            'provider_event_id' => 'read-model-bank',
            'event_type' => 'payment_success',
            'member_id' => $memberId,
            'amount_minor' => 45000,
            'provider_reference' => 'BANK-READ-450',
            'allocations' => [
                ['year' => 2026, 'month' => 9, 'amount_minor' => 30000],
                ['year' => 2026, 'month' => 10, 'amount_minor' => 15000],
            ],
            'occurred_at' => '2026-09-10 10:00:00',
        ]);

        $model = app(FinancialReadModel::class);
        $statement = $model->statement($memberId);
        $obligations = $model->obligations($memberId);

        $this->assertCount(1, $statement);
        $this->assertSame($result['payment_id'], $statement[0]['payment_id']);
        $this->assertSame(45000, (int) $statement[0]['payment_amount_minor']);
        $this->assertSame('2026-09-10 10:00:00', $statement[0]['occurred_at']);
        $this->assertSame(2, count($obligations));
        $this->assertSame(45000, (int) $statement[0]['allocated_amount_minor']);
        $this->assertNull($statement[0]['period_year']);
        $this->assertNull($statement[0]['period_month']);
    }

    public function test_non_contribution_payment_is_excluded_from_contribution_read_totals(): void
    {
        $memberId = $this->createMember();

        app(PaymentEngine::class)->processProviderEvent([
            'provider' => 'mpesa',
            'provider_event_id' => 'read-model-operational',
            'event_type' => 'operational_success',
            'member_id' => $memberId,
            'amount_minor' => 100000,
            'provider_reference' => 'READ-MODEL-OPERATIONAL',
            'source' => 'stk',
            'payment_type' => 'operational',
            'occurred_at' => '2026-09-10 10:00:00',
        ]);

        $overview = app(FinancialReadModel::class)->memberOverview($memberId);
        $totals = app(FinancialReadModel::class)->confirmedContributionTotals();

        $this->assertSame(0, $overview['confirmed_payment_count']);
        $this->assertSame(0, $overview['cash_received_minor']);
        $this->assertSame(0, $totals['count']);
        $this->assertSame(0, $totals['amount_minor']);
    }

    public function test_contribution_allocation_source_is_included_in_contribution_read_totals(): void
    {
        $memberId = $this->createMember();

        app(PaymentEngine::class)->processProviderEvent([
            'provider' => 'legacy_reconciliation',
            'provider_event_id' => 'legacy-allocation-source',
            'event_type' => 'manual_confirmed',
            'member_id' => $memberId,
            'amount_minor' => 970000,
            'provider_reference' => 'CB-LEGACY-9700',
            'source' => 'contribution_allocation',
            'status' => 'confirmed',
            'occurred_at' => '2026-09-20 10:00:00',
        ]);

        $overview = app(FinancialReadModel::class)->memberOverview($memberId);
        $totals = app(FinancialReadModel::class)->confirmedContributionTotals();

        $this->assertSame(970000, $overview['cash_received_minor']);
        $this->assertSame(970000, $totals['amount_minor']);
    }

    public function test_carry_forward_covers_partial_obligations_without_creating_overdue_balance(): void
    {
        $memberId = $this->createMember();
        DB::table('monthly_obligations')->insert([
            [
                'id' => Uuid::v4(),
                'member_id' => $memberId,
                'period_year' => 2026,
                'period_month' => 9,
                'required_amount_minor' => 30000,
                'paid_amount_minor' => 10000,
                'status' => 'partial',
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);
        DB::table('carry_forward_balances')->insert([
            'id' => Uuid::v4(),
            'member_id' => $memberId,
            'source_payment_id' => Uuid::v4(),
            'amount_minor' => 20000,
            'remaining_amount_minor' => 20000,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $overview = app(FinancialReadModel::class)->memberOverview($memberId);

        $this->assertSame(0, $overview['outstanding_minor']);
        $this->assertSame(0, $overview['partial_months']);
        $this->assertSame(0, $overview['unpaid_months']);
        $this->assertSame(1, $overview['obligation_counts']['paid']);
        $this->assertSame('2026-10-01', $overview['cash_next_due_date']);
    }
}
