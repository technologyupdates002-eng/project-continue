<?php

namespace Tests\Feature\Withdrawals;

use App\Services\JwtService;
use App\Services\WithdrawalApprovalService;
use App\Models\CoopTransferRequest;
use App\Support\Uuid;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class WithdrawalApprovalTest extends TestCase
{
    use RefreshDatabase;

    protected array $treasurerHeaders = [];
    protected array $chairpersonHeaders = [];
    protected array $secretaryHeaders = [];
    protected array $adminHeaders = [];
    protected array $memberHeaders = [];

    protected string $treasurerUserId;
    protected string $chairpersonUserId;
    protected string $secretaryUserId;
    protected string $adminUserId;
    protected array $signatureFiles = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->treasurerUserId = Uuid::v4();
        $this->chairpersonUserId = Uuid::v4();
        $this->secretaryUserId = Uuid::v4();
        $this->adminUserId = Uuid::v4();

        $memberId = Uuid::v4();

        $users = [
            'treasurer' => [$this->treasurerUserId, 'treasurer@welfare.local', ['member', 'treasurer']],
            'chairperson' => [$this->chairpersonUserId, 'chairperson@welfare.local', ['member', 'chairperson']],
            'secretary' => [$this->secretaryUserId, 'secretary@welfare.local', ['member', 'secretary']],
            'admin' => [$this->adminUserId, 'admin@welfare.local', ['member', 'admin', 'super_admin']],
            'member' => [Uuid::v4(), 'member@welfare.local', ['member']],
        ];

        foreach ($users as $role => [$userId, $email, $roles]) {
            DB::table('auth_users')->insert([
                'id' => $userId,
                'email' => $email,
                'encrypted_password' => password_hash('password', PASSWORD_BCRYPT),
                'is_banned' => 0,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            foreach ($roles as $userRole) {
                DB::table('user_roles')->insert([
                    'id' => Uuid::v4(),
                    'user_id' => $userId,
                    'role' => $userRole,
                    'is_active' => 1,
                ]);
            }
        }

        $this->treasurerHeaders = ['Authorization' => 'Bearer ' . JwtService::encode(JwtService::buildPayload($this->treasurerUserId, 'treasurer@welfare.local', ['member', 'treasurer']))];
        $this->chairpersonHeaders = ['Authorization' => 'Bearer ' . JwtService::encode(JwtService::buildPayload($this->chairpersonUserId, 'chairperson@welfare.local', ['member', 'chairperson']))];
        $this->secretaryHeaders = ['Authorization' => 'Bearer ' . JwtService::encode(JwtService::buildPayload($this->secretaryUserId, 'secretary@welfare.local', ['member', 'secretary']))];
        $this->adminHeaders = ['Authorization' => 'Bearer ' . JwtService::encode(JwtService::buildPayload($this->adminUserId, 'admin@welfare.local', ['member', 'admin', 'super_admin']))];
        $this->memberHeaders = ['Authorization' => 'Bearer ' . JwtService::encode(JwtService::buildPayload(Uuid::v4(), 'member@welfare.local', ['member']))];

        DB::table('penalty_wallet')->insert([
            'id' => Uuid::v4(),
            'total_balance' => 100000,
            'total_received' => 100000,
            'total_withdrawn' => 0,
        ]);

        DB::table('donation_wallet')->insert([
            'id' => Uuid::v4(),
            'total_balance' => 100000,
            'total_received' => 100000,
            'total_withdrawn' => 0,
        ]);

        config()->set('coop_bank.enable_real_calls', false);
        config()->set('coop_bank.consumer_key', null);
        config()->set('coop_bank.consumer_secret', null);
    }

    private function createWithdrawalRequest(
        string $walletType = 'penalty',
        string $memberId = null,
        float $amount = 5000,
        string $status = 'PENDING_SECRETARY_APPROVAL',
        ?string $phone = '254712345678'
    ): string {
        $withdrawalId = Uuid::v4();
        $signatoryRoles = ['secretary', 'chairperson'];
        $tableMap = [
            'penalty' => 'penalty_withdrawals',
            'donation' => 'donation_withdrawals',
            'operational' => 'operational_withdrawals',
        ];
        $signatoryTableMap = [
            'penalty' => 'withdrawal_signatories',
            'donation' => 'donation_withdrawal_signatories',
            'operational' => 'operational_withdrawal_signatories',
        ];

        $withdrawalTable = $tableMap[$walletType];
        $signatoryTable = $signatoryTableMap[$walletType];

        $columns = ['id', 'amount', 'reason', 'status', 'approval_stage', 'created_at', 'updated_at'];
        $data = [
            'id' => $withdrawalId,
            'amount' => $amount,
            'reason' => 'Test withdrawal',
            'status' => $status,
            'approval_stage' => 'treasurer',
            'created_at' => now(),
            'updated_at' => now(),
        ];

        if (SchemaHasColumn($withdrawalTable, 'requested_by')) {
            $columns[] = 'requested_by';
            $data['requested_by'] = $memberId ?? $this->treasurerUserId;
        }
        if (SchemaHasColumn($withdrawalTable, 'phone_number')) {
            $columns[] = 'phone_number';
            $data['phone_number'] = $phone;
        }
        if (SchemaHasColumn($withdrawalTable, 'submitted_at')) {
            $columns[] = 'submitted_at';
            $data['submitted_at'] = now();
        }

        DB::table($withdrawalTable)->insert($data);

        foreach ($signatoryRoles as $role) {
            $userMap = [
                'treasurer' => $this->treasurerUserId,
                'chairperson' => $this->chairpersonUserId,
                'secretary' => $this->secretaryUserId,
            ];

            DB::table($signatoryTable)->insert([
                'id' => Uuid::v4(),
                'withdrawal_id' => $withdrawalId,
                'signatory_role' => $role,
                'signatory_user_id' => $userMap[$role],
                'status' => 'pending',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        return $withdrawalId;
    }

    protected function tearDown(): void
    {
        foreach ($this->signatureFiles as $signatureFile) {
            if (is_file($signatureFile)) {
                unlink($signatureFile);
            }
        }
        parent::tearDown();
    }

    private function storeSignature(string $role): void
    {
        $userId = $role === 'secretary' ? $this->secretaryUserId : $this->chairpersonUserId;
        $headers = $role === 'secretary' ? $this->secretaryHeaders : $this->chairpersonHeaders;
        $fileName = $role . '-' . Uuid::v4() . '.png';
        $directory = storage_path('app/private/signatory-signatures');
        if (!is_dir($directory)) {
            mkdir($directory, 0775, true);
        }
        $filePath = $directory . '/' . $fileName;
        $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAIAAACQd1PeAAAADUlEQVR4nGP4z8AAAAMBAQDJ/pLvAAAAAElFTkSuQmCC', true);
        $this->assertNotFalse($png);
        $this->assertNotFalse(file_put_contents($filePath, $png));
        $this->signatureFiles[] = $filePath;

        $this->postJson('/api/signatures', [
            'signatory_role' => $role,
            'signature_url' => '/api/storage/v1/object/signatory-signatures/' . $fileName,
            'full_name' => $role === 'secretary' ? 'Test Secretary' : 'Test Chairperson',
        ], $headers)->assertStatus(201);
    }

    private function confirmProviderSuccess(string $withdrawalId): void
    {
        $transfer = CoopTransferRequest::query()
            ->where('internal_reference', $withdrawalId)
            ->where('transfer_type', 'B2C_MPESA')
            ->firstOrFail();
        $transfer->status = 'successful';
        $transfer->save();
        app(WithdrawalApprovalService::class)->applyProviderOutcome($transfer->fresh());
    }

    private function resolveWalletType(string $signatoryTable): string
    {
        return match ($signatoryTable) {
            'withdrawal_signatories' => 'penalty',
            'donation_withdrawal_signatories' => 'donation',
            'operational_withdrawal_signatories' => 'operational',
            default => throw new \InvalidArgumentException("Unknown signatory table: {$signatoryTable}"),
        };
    }

    public function test_treasurer_cannot_approve_withdrawal(): void
    {
        $withdrawalId = $this->createWithdrawalRequest('penalty', Uuid::v4());

        $response = $this->postJson('/api/withdrawal_signatories/update', [
            'withdrawal_id' => $withdrawalId,
            'signatory_role' => 'treasurer',
            'status' => 'approved',
            'approved_at' => now()->toIso8601String(),
            'signatory_user_id' => $this->treasurerUserId,
        ], $this->treasurerHeaders);

        $response->assertStatus(403);
        $response->assertJson(['error' => ['code' => 'invalid_signatory_role']]);
    }

    public function test_approval_chain_enforces_order(): void
    {
        $withdrawalId = $this->createWithdrawalRequest();

        $chairpersonResponse = $this->postJson('/api/withdrawal_signatories/update', [
            'withdrawal_id' => $withdrawalId,
            'signatory_role' => 'chairperson',
            'status' => 'approved',
            'approved_at' => now()->toIso8601String(),
            'signatory_user_id' => $this->chairpersonUserId,
        ], $this->chairpersonHeaders);

        $chairpersonResponse->assertStatus(409);
        $chairpersonResponse->assertJson(['error' => ['code' => 'invalid_approval_stage']]);

        $this->assertDatabaseHas('withdrawal_signatories', [
            'withdrawal_id' => $withdrawalId,
            'signatory_role' => 'chairperson',
            'status' => 'pending',
        ]);
    }

    public function test_secretary_cannot_approve_after_chairperson(): void
    {
        $withdrawalId = $this->createWithdrawalRequest();
        $this->storeSignature('secretary');

        $this->postJson('/api/withdrawal_signatories/update', [
            'withdrawal_id' => $withdrawalId,
            'signatory_role' => 'secretary',
            'status' => 'approved',
            'approved_at' => now()->toIso8601String(),
            'signatory_user_id' => $this->secretaryUserId,
        ], $this->secretaryHeaders)->assertStatus(200);

        $response = $this->postJson('/api/withdrawal_signatories/update', [
            'withdrawal_id' => $withdrawalId,
            'signatory_role' => 'secretary',
            'status' => 'approved',
            'approved_at' => now()->toIso8601String(),
            'signatory_user_id' => $this->secretaryUserId,
        ], $this->secretaryHeaders);

        $response->assertStatus(409);
    }

    public function test_full_approval_chain_triggers_b2c(): void
    {
        $withdrawalId = $this->createWithdrawalRequest('penalty', Uuid::v4());
        $this->storeSignature('secretary');
        $this->storeSignature('chairperson');

        $this->postJson('/api/withdrawal_signatories/update', [
            'withdrawal_id' => $withdrawalId,
            'signatory_role' => 'secretary',
            'status' => 'approved',
            'approved_at' => now()->toIso8601String(),
            'signatory_user_id' => $this->secretaryUserId,
        ], $this->secretaryHeaders)->assertStatus(200);

        $this->postJson('/api/withdrawal_signatories/update', [
            'withdrawal_id' => $withdrawalId,
            'signatory_role' => 'chairperson',
            'status' => 'approved',
            'approved_at' => now()->toIso8601String(),
            'signatory_user_id' => $this->chairpersonUserId,
        ], $this->chairpersonHeaders)->assertStatus(200);

        $this->assertDatabaseHas('penalty_withdrawals', ['id' => $withdrawalId, 'status' => 'processing']);

        $this->assertDatabaseHas('b2c_transactions', [
            'withdrawal_id' => $withdrawalId,
        ]);
        $this->confirmProviderSuccess($withdrawalId);
        $this->assertDatabaseHas('penalty_withdrawals', ['id' => $withdrawalId, 'status' => 'completed']);
    }

    public function test_rejection_sets_withdrawal_status_to_rejected(): void
    {
        $withdrawalId = $this->createWithdrawalRequest();

        $response = $this->postJson('/api/withdrawal_signatories/update', [
            'withdrawal_id' => $withdrawalId,
            'signatory_role' => 'secretary',
            'status' => 'rejected',
            'rejected_at' => now()->toIso8601String(),
            'rejection_reason' => 'Fraudulent request',
            'signatory_user_id' => $this->secretaryUserId,
        ], $this->secretaryHeaders);

        $response->assertStatus(200);

        $this->assertDatabaseHas('withdrawal_signatories', [
            'withdrawal_id' => $withdrawalId,
            'signatory_role' => 'secretary',
            'status' => 'rejected',
        ]);

        $this->assertDatabaseHas('penalty_withdrawals', [
            'id' => $withdrawalId,
            'status' => 'rejected',
        ]);
    }

    public function test_non_staff_cannot_approve(): void
    {
        $withdrawalId = $this->createWithdrawalRequest();

        $response = $this->postJson('/api/withdrawal_signatories/update', [
            'withdrawal_id' => $withdrawalId,
            'signatory_role' => 'treasurer',
            'status' => 'approved',
            'approved_at' => now()->toIso8601String(),
            'signatory_user_id' => Uuid::v4(),
        ], $this->memberHeaders);

        $response->assertStatus(403);
    }

    public function test_audit_log_created_on_approval(): void
    {
        $withdrawalId = $this->createWithdrawalRequest();
        $this->storeSignature('secretary');

        $this->postJson('/api/withdrawal_signatories/update', [
            'withdrawal_id' => $withdrawalId,
            'signatory_role' => 'secretary',
            'status' => 'approved',
            'approved_at' => now()->toIso8601String(),
            'signatory_user_id' => $this->secretaryUserId,
        ], $this->secretaryHeaders)->assertStatus(200);

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'withdrawal_signatory_updated',
        ]);
    }

    public function test_audit_log_created_on_b2c_trigger(): void
    {
        $withdrawalId = $this->createWithdrawalRequest('penalty', Uuid::v4());
        $this->storeSignature('secretary');
        $this->storeSignature('chairperson');

        foreach (['secretary', 'chairperson'] as $role) {
            $userId = match ($role) {
                'secretary' => $this->secretaryUserId,
                'chairperson' => $this->chairpersonUserId,
            };
            $headers = match ($role) {
                'secretary' => $this->secretaryHeaders,
                'chairperson' => $this->chairpersonHeaders,
            };

            $this->postJson('/api/withdrawal_signatories/update', [
                'withdrawal_id' => $withdrawalId,
                'signatory_role' => $role,
                'status' => 'approved',
                'approved_at' => now()->toIso8601String(),
                'signatory_user_id' => $userId,
            ], $headers)->assertStatus(200);
        }

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'withdrawal_b2c_initiated',
        ]);
    }

    public function test_donation_withdrawal_uses_correct_tables(): void
    {
        $withdrawalId = $this->createWithdrawalRequest('donation', Uuid::v4(), 3000, 'pending', '254723456789');
        $this->storeSignature('secretary');

        $this->postJson('/api/donation_withdrawal_signatories/update', [
            'withdrawal_id' => $withdrawalId,
            'signatory_role' => 'secretary',
            'status' => 'approved',
            'approved_at' => now()->toIso8601String(),
            'signatory_user_id' => $this->secretaryUserId,
        ], $this->secretaryHeaders)->assertStatus(200);

        $this->assertDatabaseHas('donation_withdrawal_signatories', [
            'withdrawal_id' => $withdrawalId,
            'signatory_role' => 'secretary',
            'status' => 'approved',
        ]);
    }

    public function test_withdrawal_without_phone_number_skips_b2c(): void
    {
        $withdrawalId = $this->createWithdrawalRequest('penalty', Uuid::v4(), 5000, 'pending', null);
        $this->storeSignature('secretary');
        $this->storeSignature('chairperson');

        foreach (['secretary', 'chairperson'] as $role) {
            $userId = match ($role) {
                'secretary' => $this->secretaryUserId,
                'chairperson' => $this->chairpersonUserId,
            };
            $headers = match ($role) {
                'secretary' => $this->secretaryHeaders,
                'chairperson' => $this->chairpersonHeaders,
            };

            $this->postJson('/api/withdrawal_signatories/update', [
                'withdrawal_id' => $withdrawalId,
                'signatory_role' => $role,
                'status' => 'approved',
                'approved_at' => now()->toIso8601String(),
                'signatory_user_id' => $userId,
            ], $headers)->assertStatus(200);
        }

        $this->assertDatabaseHas('penalty_withdrawals', [
            'id' => $withdrawalId,
            'status' => 'approved_no_phone',
        ]);

        $this->assertDatabaseMissing('b2c_transactions', [
            'withdrawal_id' => $withdrawalId,
        ]);
    }

    public function test_b2c_controller_blocks_unapproved_withdrawal(): void
    {
        $withdrawalId = $this->createWithdrawalRequest('penalty', Uuid::v4(), 5000, 'PENDING_SECRETARY_APPROVAL');

        $response = $this->postJson('/api/functions/v1/b2c-transfer', [
            'withdrawal_id' => $withdrawalId,
            'wallet_type' => 'penalty',
            'amount' => 5000,
            'phone_number' => '254712345678',
            'narration' => 'Test B2C',
        ], $this->adminHeaders);

        $response->assertStatus(409);
        $response->assertJson(['error' => ['code' => 'withdrawal_not_approved']]);
    }

    public function test_b2c_controller_blocks_rejected_withdrawal(): void
    {
        $withdrawalId = $this->createWithdrawalRequest('penalty', Uuid::v4(), 5000, 'rejected');

        $response = $this->postJson('/api/functions/v1/b2c-transfer', [
            'withdrawal_id' => $withdrawalId,
            'wallet_type' => 'penalty',
            'amount' => 5000,
            'phone_number' => '254712345678',
            'narration' => 'Test B2C',
        ], $this->adminHeaders);

        $response->assertStatus(409);
        $response->assertJson(['error' => ['code' => 'invalid_withdrawal_status']]);
    }

    public function test_receipt_contains_actual_approver_names(): void
    {
        $withdrawalId = $this->createWithdrawalRequest('penalty', Uuid::v4(), 5000, 'PENDING_SECRETARY_APPROVAL');
        $this->storeSignature('secretary');
        $this->storeSignature('chairperson');

        $this->postJson('/api/withdrawal_signatories/update', [
            'withdrawal_id' => $withdrawalId,
            'signatory_role' => 'secretary',
            'status' => 'approved',
            'approved_at' => now()->toIso8601String(),
            'signatory_user_id' => $this->secretaryUserId,
        ], $this->secretaryHeaders)->assertStatus(200);

        $this->postJson('/api/withdrawal_signatories/update', [
            'withdrawal_id' => $withdrawalId,
            'signatory_role' => 'chairperson',
            'status' => 'approved',
            'approved_at' => now()->toIso8601String(),
            'signatory_user_id' => $this->chairpersonUserId,
        ], $this->chairpersonHeaders)->assertStatus(200);

        $this->confirmProviderSuccess($withdrawalId);
        $receiptUrl = DB::table('penalty_withdrawals')->where('id', $withdrawalId)->value('receipt_url');

        $this->assertNotNull($receiptUrl);

        $relativePath = preg_replace('#^https?://[^/]+#', '', $receiptUrl);
        $relativePath = ltrim($relativePath, '/');
        $relativePath = preg_replace('#^storage/#', '', $relativePath);
        $storagePath = storage_path('app/public/' . $relativePath);

        $this->assertFileExists($storagePath, 'Receipt file should exist in storage');

        $html = file_get_contents($storagePath);
        $this->assertStringContainsString('Test withdrawal', $html);
        $this->assertStringContainsString('5,000', $html);
        $this->assertStringContainsString('data:image/png;base64,', $html);
    }
}

function SchemaHasColumn(string $table, string $column): bool
{
    try {
        $columns = DB::getSchemaBuilder()->getColumnListing($table);
        return in_array($column, $columns, true);
    } catch (\Throwable $e) {
        return false;
    }
}
