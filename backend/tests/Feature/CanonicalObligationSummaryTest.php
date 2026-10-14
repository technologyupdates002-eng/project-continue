<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\ContributionObligationService;
use App\Services\FinancialReadModel;
use App\Support\Uuid;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class CanonicalObligationSummaryTest extends TestCase
{
    use RefreshDatabase;

    public function test_canonical_summary_includes_paid_penalties(): void
    {
        $memberId = Uuid::v4();
        DB::table('members')->insert([
            'id' => $memberId,
            'name' => 'Canonical Summary Member',
            'phone' => '+254711234567',
            'is_active' => 1,
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('monthly_obligations')->insert([
            'id' => Uuid::v4(),
            'member_id' => $memberId,
            'period_year' => 2026,
            'period_month' => 1,
            'required_amount_minor' => 30000,
            'paid_amount_minor' => 30000,
            'status' => 'paid',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('penalties')->insert([
            [
                'id' => Uuid::v4(),
                'member_id' => $memberId,
                'amount' => 25,
                'reason' => 'Paid penalty',
                'is_paid' => 1,
                'created_at' => now(),
            ],
            [
                'id' => Uuid::v4(),
                'member_id' => $memberId,
                'amount' => 50,
                'reason' => 'Outstanding penalty',
                'is_paid' => 0,
                'created_at' => now(),
            ],
        ]);

        $readModel = \Mockery::mock(FinancialReadModel::class);
        $readModel->shouldReceive('memberOverview')->once()->with($memberId)->andReturn([
            'cash_received_minor' => 30000,
            'confirmed_payment_count' => 1,
            'outstanding_minor' => 0,
            'penalties_outstanding_minor' => 5000,
            'paid_months' => 1,
            'partial_months' => 0,
            'unpaid_months' => 0,
        ]);
        $readModel->shouldReceive('overdueObligationsForMember')->once()->with($memberId)->andReturn(collect());
        $this->app->instance(FinancialReadModel::class, $readModel);

        $summary = app(ContributionObligationService::class)->memberSummary($memberId);

        $this->assertSame(25.0, $summary['penalty_paid']);
        $this->assertSame(50.0, $summary['penalty_outstanding']);
    }
}
