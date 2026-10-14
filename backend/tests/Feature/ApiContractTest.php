<?php

namespace Tests\Feature;

use App\Services\JwtService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ApiContractTest extends TestCase
{
    use RefreshDatabase;

    protected array $adminHeaders = [];

    protected function setUp(): void
    {
        parent::setUp();

        $adminToken = JwtService::encode(JwtService::buildPayload(
            '05ade1a2-2aba-425c-b298-3b27f1d2c13b',
            '254712345678@welfare.local',
            ['member', 'admin', 'super_admin', 'treasurer']
        ));
        $this->adminHeaders = ['Authorization' => 'Bearer ' . $adminToken];
    }

    public function test_health_endpoint_matches_legacy_contract(): void
    {
        $response = $this->getJson('/api/health');

        $response->assertOk()
            ->assertJsonPath('service', 'khcww-welfare-api')
            ->assertJsonPath('status', 'ok');
    }

    public function test_password_grant_route_exists(): void
    {
        $response = $this->postJson('/api/auth/v1/token?grant_type=password', [
            'email' => 'member@example.com',
            'password' => 'secret',
        ]);

        $this->assertTrue(in_array($response->status(), [200, 400, 401, 403], true));
    }

    public function test_legacy_rest_api_blocks_wallet_mutation(): void
    {
        $response = $this->withHeaders($this->adminHeaders)
            ->postJson('/api/rest/v1/donation_wallet', [
                'total_balance' => 999999,
            ]);

        $response->assertStatus(403)
            ->assertJsonPath('error.code', 'wallet_immutable');
    }

    public function test_legacy_rest_api_allows_wallet_read(): void
    {
        $response = $this->withHeaders($this->adminHeaders)
            ->getJson('/api/rest/v1/donation_wallet');

        $response->assertStatus(200);
    }
}
