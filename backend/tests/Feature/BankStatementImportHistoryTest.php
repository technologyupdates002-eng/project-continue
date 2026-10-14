<?php

namespace Tests\Feature;

use App\Services\ImportService;
use App\Services\JwtService;
use App\Support\Uuid;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class BankStatementImportHistoryTest extends TestCase
{
    use RefreshDatabase;

    private function tokenFor(string $role): string
    {
        $userId = Uuid::v4();
        $email = "{$userId}@welfare.local";
        DB::table('auth_users')->insert([
            'id' => $userId,
            'email' => $email,
            'phone' => '+2547'.random_int(10000000, 99999999),
            'encrypted_password' => bcrypt('Password123!'),
            'must_change_password' => 0,
        ]);
        DB::table('user_roles')->insert([
            'id' => Uuid::v4(),
            'user_id' => $userId,
            'role' => $role,
            'is_active' => 1,
        ]);

        return JwtService::encode(JwtService::buildPayload($userId, $email, [$role]));
    }

    public function test_admin_can_view_paginated_statement_import_history_without_credentials_or_raw_payload(): void
    {
        $batchId = Uuid::v4();
        DB::table('member_import_batches')->insert([
            'id' => $batchId,
            'source_name' => 'bank_statement',
            'file_name' => 'october.xlsx',
            'total_rows' => 1,
            'status' => 'completed',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('member_import_rows')->insert([
            'id' => Uuid::v4(),
            'batch_id' => $batchId,
            'row_number' => 1,
            'trans_date' => '2026-10-01',
            'reference_no' => 'BANK-REF-1',
            'credit' => 300,
            'member_name' => 'A Member',
            'phone' => '+254712345678',
            'member_id_ref' => null,
            'status' => 'new_member',
            'match_status' => 'matched',
            'message' => 'New member created and contribution recorded',
            'raw_payload' => json_encode(['transaction_details' => 'sensitive source row']),
            'contribution_amount' => 300,
            'contribution_month' => 10,
            'contribution_year' => 2026,
            'contribution_status' => 'paid',
            'auth_user_created' => 1,
            'member_dashboard_created' => 1,
            'default_password' => 'must-not-be-returned',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->withHeader('Authorization', 'Bearer '.$this->tokenFor('admin'))
            ->getJson('/api/admin/bank-statement-imports?status=new_member')
            ->assertOk()
            ->assertJsonPath('data.meta.total', 1)
            ->assertJsonPath('data.rows.0.member_name', 'A Member')
            ->assertJsonPath('data.rows.0.member_phone', '+254712345678')
            ->assertJsonPath('data.rows.0.contribution_status', 'paid')
            ->assertJsonPath('data.rows.0.member_dashboard_created', 1)
            ->assertJsonMissingPath('data.rows.0.default_password')
            ->assertJsonMissingPath('data.rows.0.raw_payload');
    }

    public function test_super_admin_can_view_import_history_and_member_cannot(): void
    {
        $this->withHeader('Authorization', 'Bearer '.$this->tokenFor('super_admin'))
            ->getJson('/api/admin/bank-statement-imports')
            ->assertOk();

        $this->withHeader('Authorization', 'Bearer '.$this->tokenFor('member'))
            ->getJson('/api/admin/bank-statement-imports')
            ->assertForbidden();
    }

    public function test_history_shows_current_dashboard_contribution_totals_after_statement_import(): void
    {
        $result = app(ImportService::class)->importMemberStatement([[
            'trans_date' => '2026-10-01',
            'value_date' => '2026-10-01',
            'transaction_details' => 'Confirm. JANE DOE 0712345679',
            'reference_no' => 'DASHBOARD-REF-1',
            'credit' => 600,
            'debit' => 0,
            'book_balance' => 600,
        ]], 'bank_statement', 'dashboard-check.xlsx', null);
        $token = $this->tokenFor('admin');

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/admin/bank-statement-imports?status=new_member')
            ->assertOk()
            ->assertJsonPath('data.rows.0.contribution_status', 'paid');

        $row = $response->json('data.rows.0');
        $this->assertContains($row['dashboard_status'], ['up_to_date', 'balance_due']);
        $this->assertEquals(600, $row['dashboard_paid_total']);
        $this->assertSame($result['batch_id'], $row['batch_id']);
    }
}
