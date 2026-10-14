<?php

namespace Tests\Unit;

use App\Exceptions\HttpException;
use App\Services\PolicyService;
use Tests\TestCase;

class PolicyServiceTest extends TestCase
{
    public function test_can_read_all_for_public_table(): void
    {
        $this->assertTrue(PolicyService::canReadAll('members', ['member']));
        $this->assertTrue(PolicyService::canReadAll('news', ['member']));
    }

    public function test_staff_only_tables_require_staff_to_read_all(): void
    {
        $this->assertFalse(PolicyService::canReadAll('beneficiaries', ['member']));
        $this->assertTrue(PolicyService::canReadAll('beneficiaries', ['admin']));
        $this->assertTrue(PolicyService::canReadAll('beneficiaries', ['super_admin']));
    }

    public function test_can_write_all_respects_role_lists(): void
    {
        $this->assertFalse(PolicyService::canWriteAll('members', ['member']));
        $this->assertTrue(PolicyService::canWriteAll('members', ['admin']));
        $this->assertTrue(PolicyService::canWriteAll('user_presence', ['member']));
    }

    public function test_public_insert_tables(): void
    {
        $this->assertTrue(PolicyService::publicInsert('member_registrations'));
        $this->assertTrue(PolicyService::publicInsert('registration_fees'));
        $this->assertFalse(PolicyService::publicInsert('members'));
    }

    public function test_table_existence(): void
    {
        $this->assertTrue(PolicyService::tableExists('members'));
        $this->assertFalse(PolicyService::tableExists('does_not_exist'));
    }

    public function test_assert_write_blocks_unauthorized(): void
    {
        // 'members' is owner-scoped on user_id; a member writing a row that
        // does not belong to them must be denied with HTTP 403.
        try {
            PolicyService::assertWrite('members', [
                'user_id' => 'u1',
                'roles' => ['member'],
                'member_id' => 'm1',
            ], [
                'user_id' => 'someone-else',
            ]);
            $this->fail('Expected HttpException was not thrown.');
        } catch (HttpException $e) {
            $this->assertSame(403, $e->httpStatus);
        }
    }

    public function test_assert_write_allows_staff(): void
    {
        PolicyService::assertWrite('members', [
            'user_id' => 'u1',
            'roles' => ['admin'],
            'member_id' => 'm1',
        ]);

        $this->assertTrue(true);
    }
}
