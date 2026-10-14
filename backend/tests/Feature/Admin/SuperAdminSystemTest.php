<?php

namespace Tests\Feature\Admin;

use App\Services\JwtService;
use Tests\TestCase;

class SuperAdminSystemTest extends TestCase
{
    private string $adminToken;

    protected function setUp(): void
    {
        parent::setUp();

        $this->adminToken = JwtService::encode(JwtService::buildPayload(
            '11111111-1111-1111-1111-111111111111',
            'superadmin@welfare.local',
            ['super_admin', 'admin']
        ));
    }

    private function withAdminSession()
    {
        return $this->withSession(['admin_jwt' => $this->adminToken]);
    }

    public function test_system_health_endpoint_returns_application_info(): void
    {
        $this->withAdminSession()->get('/admin/api/system/health')
            ->assertStatus(200)
            ->assertJsonStructure([
                'application',
                'database',
                'redis',
                'queue',
                'storage',
                'scheduler',
                'cache',
            ]);
    }

    public function test_admin_role_can_access_super_admin_system_endpoints(): void
    {
        $adminToken = JwtService::encode(JwtService::buildPayload(
            '22222222-2222-2222-2222-222222222222',
            'admin@welfare.local',
            ['admin']
        ));

        $this->withSession(['admin_jwt' => $adminToken])
            ->get('/admin/api/system/health')
            ->assertStatus(200)
            ->assertJsonStructure(['application', 'database', 'redis', 'queue']);
    }

    public function test_queue_overview_endpoint_returns_status(): void
    {
        $this->withAdminSession()->get('/admin/api/system/queue')
            ->assertStatus(200)
            ->assertJsonStructure([
                'connection',
                'driver',
                'status',
            ]);
    }

    public function test_queue_failed_endpoint_returns_empty_array_when_no_failures(): void
    {
        $this->withAdminSession()->get('/admin/api/system/queue/failed')
            ->assertStatus(200)
            ->assertJsonStructure([
                'count',
                'jobs',
            ]);
    }

    public function test_cache_overview_endpoint_returns_configuration(): void
    {
        $this->withAdminSession()->get('/admin/api/system/cache')
            ->assertStatus(200)
            ->assertJsonStructure([
                'default',
                'stores',
            ]);
    }

    public function test_financial_overview_endpoint_returns_data(): void
    {
        $this->withAdminSession()->get('/admin/api/system/financial')
            ->assertStatus(200)
            ->assertJsonStructure([
                'contributions',
                'wallets',
                'stk_transfers',
            ]);
    }

    public function test_database_health_endpoint_returns_status(): void
    {
        $this->withAdminSession()->get('/admin/api/system/database')
            ->assertStatus(200)
            ->assertJsonStructure([
                'status',
                'connection',
                'database',
                'latency_ms',
                'table_count',
            ]);
    }

    public function test_bank_monitoring_endpoint_returns_stk_data(): void
    {
        $this->withAdminSession()->get('/admin/api/system/bank')
            ->assertStatus(200)
            ->assertJsonStructure([
                'stk',
                'last_backup',
            ]);
    }

    public function test_transaction_trace_endpoint_requires_query(): void
    {
        $this->withAdminSession()->get('/admin/api/system/trace')
            ->assertStatus(422);
    }

    public function test_transaction_trace_endpoint_returns_results_for_valid_query(): void
    {
        $this->withAdminSession()->get('/admin/api/system/trace?query=test')
            ->assertStatus(200);
    }
}
