<?php

namespace Tests\Feature;

use App\Services\JwtService;
use App\Support\Uuid;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class AssignUserRoleTest extends TestCase
{
    use RefreshDatabase;

    private function adminHeaders(): array
    {
        $token = JwtService::encode(JwtService::buildPayload(
            Uuid::v4(),
            'admin@welfare.local',
            ['member', 'admin', 'super_admin']
        ));

        return ['Authorization' => 'Bearer ' . $token];
    }

    public function test_role_assignment_uses_existing_schema_and_is_idempotent(): void
    {
        $userId = Uuid::v4();
        $roleRowId = Uuid::v4();

        DB::table('user_roles')->insert([
            'id' => $roleRowId,
            'user_id' => $userId,
            'role' => 'secretary',
            'is_active' => 0,
        ]);

        $payload = [
            'user_id_param' => $userId,
            'role_param' => 'secretary',
        ];

        $this->withHeaders($this->adminHeaders())
            ->postJson('/api/functions/v1/rpc/assign_user_role', $payload)
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->withHeaders($this->adminHeaders())
            ->postJson('/api/functions/v1/rpc/assign_user_role', $payload)
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->assertDatabaseCount('user_roles', 1);
        $this->assertDatabaseHas('user_roles', [
            'id' => $roleRowId,
            'user_id' => $userId,
            'role' => 'secretary',
            'is_active' => 1,
        ]);
    }
}
