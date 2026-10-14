<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Services\ContributionReconciliationService;
use App\Services\ContributionObligationService;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ContributionReconciliationServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        DB::beginTransaction();
    }

    protected function tearDown(): void
    {
        DB::rollBack();
        parent::tearDown();
    }

    public function test_reconcile_member_with_no_contributions(): void
    {
        $memberId = DB::table('members')->insertGetId([
            'id' => 'test-member-1',
            'name' => 'Test Member',
            'phone' => '254700000001',
            'member_id' => 'MEM-001',
            'total_contributions' => 0,
            'total_penalties' => 0,
            'is_active' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $service = app(ContributionReconciliationService::class);
        $result = $service->reconcileMember('test-member-1', true);

        $this->assertEquals(0, $result['required_months']);
        $this->assertEquals(0, $result['paid_months']);
        $this->assertEquals(0, $result['overdue_months']);
        $this->assertEquals(0, $result['total_paid']);
    }

    public function test_reconcile_member_with_exact_monthly_payment(): void
    {
        $memberId = 'test-member-2';
        DB::table('members')->insert([
            'id' => $memberId,
            'name' => 'Test Member 2',
            'phone' => '254700000002',
            'member_id' => 'MEM-002',
            'total_contributions' => 0,
            'total_penalties' => 0,
            'is_active' => 1,
            'first_contribution_date' => '2026-01-01',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('contributions')->insert([
            'id' => 'contrib-1',
            'member_id' => $memberId,
            'amount' => 300,
            'month' => 1,
            'year' => 2026,
            'due_date' => '2026-01-31',
            'paid_date' => '2026-01-15',
            'status' => 'paid',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $service = app(ContributionReconciliationService::class);
        $result = $service->reconcileMember($memberId, true);

        $this->assertGreaterThanOrEqual(1, $result['paid_months']);
        $this->assertEquals(0, $result['credit']);
    }

    public function test_reconcile_member_with_large_payment(): void
    {
        $memberId = 'test-member-3';
        DB::table('members')->insert([
            'id' => $memberId,
            'name' => 'Test Member 3',
            'phone' => '254700000003',
            'member_id' => 'MEM-003',
            'total_contributions' => 0,
            'total_penalties' => 0,
            'is_active' => 1,
            'first_contribution_date' => '2026-01-01',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('contributions')->insert([
            'id' => 'contrib-2',
            'member_id' => $memberId,
            'amount' => 3600,
            'month' => 1,
            'year' => 2026,
            'due_date' => '2026-01-31',
            'paid_date' => '2026-01-15',
            'status' => 'paid',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $service = app(ContributionReconciliationService::class);
        $result = $service->reconcileMember($memberId, true);

        $this->assertGreaterThanOrEqual(12, $result['paid_months']);
        $this->assertEquals(0, $result['overdue_months']);
    }
}
