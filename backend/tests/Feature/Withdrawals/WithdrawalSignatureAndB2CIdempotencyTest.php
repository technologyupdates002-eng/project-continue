<?php

namespace Tests\Feature\Withdrawals;

use App\Services\JwtService;
use App\Services\WithdrawalApprovalService;
use App\Services\CoopBank\CoopBankClient;
use App\Services\CoopBank\CoopBankTransferService;
use App\Exceptions\CoopBankException;
use App\Support\Uuid;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use App\Models\CoopTransferRequest;
use Tests\TestCase;

class WithdrawalSignatureAndB2CIdempotencyTest extends TestCase
{
    use RefreshDatabase;

    protected array $secretaryHeaders = [];
    protected array $chairpersonHeaders = [];
    protected array $adminHeaders = [];

    protected string $secretaryUserId;
    protected string $chairpersonUserId;
    protected array $signatureFiles = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->secretaryUserId = Uuid::v4();
        $this->chairpersonUserId = Uuid::v4();

        $users = [
            'secretary' => [$this->secretaryUserId, 'secretary@welfare.local', ['member', 'secretary']],
            'chairperson' => [$this->chairpersonUserId, 'chairperson@welfare.local', ['member', 'chairperson']],
            'admin' => [Uuid::v4(), 'admin@welfare.local', ['member', 'admin', 'super_admin']],
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

            DB::table('members')->insert([
                'id' => Uuid::v4(),
                'user_id' => $userId,
                'name' => match ($role) {
                    'secretary' => 'Test Secretary',
                    'chairperson' => 'Test Chairperson',
                    'admin' => 'Test Admin',
                },
                'phone' => '25470000000' . ($role === 'secretary' ? '1' : ($role === 'chairperson' ? '2' : '3')),
                'member_id' => 'MEM' . substr($userId, 0, 8),
                'total_contributions' => 0,
                'total_penalties' => 0,
                'is_active' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $this->secretaryHeaders = ['Authorization' => 'Bearer ' . JwtService::encode(JwtService::buildPayload($this->secretaryUserId, 'secretary@welfare.local', ['member', 'secretary']))];
        $this->chairpersonHeaders = ['Authorization' => 'Bearer ' . JwtService::encode(JwtService::buildPayload($this->chairpersonUserId, 'chairperson@welfare.local', ['member', 'chairperson']))];
        $this->adminHeaders = ['Authorization' => 'Bearer ' . JwtService::encode(JwtService::buildPayload(Uuid::v4(), 'admin@welfare.local', ['member', 'admin', 'super_admin']))];

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

    private function createWithdrawalRequest(string $status = 'PENDING_SECRETARY_APPROVAL'): string
    {
        $withdrawalId = Uuid::v4();

        DB::table('penalty_withdrawals')->insert([
            'id' => $withdrawalId,
            'amount' => 5000,
            'reason' => 'Test withdrawal',
            'status' => $status,
            'approval_stage' => 'secretary',
            'requested_by' => Uuid::v4(),
            'phone_number' => '254712345678',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        foreach (['secretary', 'chairperson'] as $role) {
            DB::table('withdrawal_signatories')->insert([
                'id' => Uuid::v4(),
                'withdrawal_id' => $withdrawalId,
                'signatory_role' => $role,
                'signatory_user_id' => match ($role) {
                    'secretary' => $this->secretaryUserId,
                    'chairperson' => $this->chairpersonUserId,
                },
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

    private function storeSignature(string $role, string $userId): string
    {
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

        $headers = $role === 'secretary' ? $this->secretaryHeaders : $this->chairpersonHeaders;
        $response = $this->postJson('/api/signatures', [
            'signatory_role' => $role,
            'signature_url' => '/api/storage/v1/object/signatory-signatures/' . $fileName,
            'full_name' => ucfirst($role) . ' Name',
            'user_id' => $userId,
        ], $headers);
        $response->assertStatus(201);

        return '/api/storage/v1/object/signatory-signatures/' . $fileName;
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

    private function approveAndSubmit(string $withdrawalId): void
    {
        $this->storeSignature('secretary', $this->secretaryUserId);
        $this->storeSignature('chairperson', $this->chairpersonUserId);

        $this->postJson('/api/withdrawal_signatories/update', [
            'withdrawal_id' => $withdrawalId,
            'signatory_role' => 'secretary',
            'status' => 'approved',
        ], $this->secretaryHeaders)->assertStatus(200);
        $this->postJson('/api/withdrawal_signatories/update', [
            'withdrawal_id' => $withdrawalId,
            'signatory_role' => 'chairperson',
            'status' => 'approved',
        ], $this->chairpersonHeaders)->assertStatus(200);
    }

    public function test_signature_upload_persists_across_sessions(): void
    {
        $signatureUrl = $this->storeSignature('secretary', $this->secretaryUserId);

        $response = $this->getJson('/api/signatures/current?role=secretary', $this->secretaryHeaders);
        $response->assertStatus(200);
        $response->assertJsonPath('data.signature_url', $signatureUrl);
        $response->assertJsonPath('data.full_name', 'Secretary Name');

        $newHeaders = ['Authorization' => 'Bearer ' . JwtService::encode(JwtService::buildPayload($this->secretaryUserId, 'secretary@welfare.local', ['member', 'secretary']))];

        $response2 = $this->getJson('/api/signatures/current?role=secretary', $newHeaders);
        $response2->assertStatus(200);
        $response2->assertJsonPath('data.signature_url', $signatureUrl);
        $response2->assertJsonPath('data.full_name', 'Secretary Name');
    }

    public function test_secretary_signature_appears_in_receipt(): void
    {
        $withdrawalId = $this->createWithdrawalRequest();

        $this->storeSignature('secretary', $this->secretaryUserId);
        $this->storeSignature('chairperson', $this->chairpersonUserId);

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

        $this->assertFileExists($storagePath);
        $html = file_get_contents($storagePath);

        $this->assertStringContainsString('Secretary Name', $html);
        $this->assertStringContainsString('data:image/png;base64,', $html);
    }

    public function test_chairperson_signature_appears_in_receipt(): void
    {
        $withdrawalId = $this->createWithdrawalRequest();
        $this->storeSignature('secretary', $this->secretaryUserId);
        $this->storeSignature('chairperson', $this->chairpersonUserId);

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

        $this->assertFileExists($storagePath);
        $html = file_get_contents($storagePath);

        $this->assertStringContainsString('Chairperson Name', $html);
        $this->assertStringContainsString('data:image/png;base64,', $html);
    }

    public function test_b2c_is_idempotent_on_duplicate_request(): void
    {
        $withdrawalId = $this->createWithdrawalRequest();
        $this->approveAndSubmit($withdrawalId);

        $b2cCountBefore = DB::table('b2c_transactions')->where('withdrawal_id', $withdrawalId)->count();
        $this->assertEquals(1, $b2cCountBefore, 'One B2C transaction should be created after approval');

        $withdrawal = DB::table('penalty_withdrawals')->where('id', $withdrawalId)->first();
        $this->assertEquals('processing', $withdrawal->status);
        $this->assertSame(0, DB::table('wallet_transactions')->where('reference_id', $withdrawalId)->where('direction', 'debit')->count());

        $this->confirmProviderSuccess($withdrawalId);
        $this->confirmProviderSuccess($withdrawalId);

        $this->assertDatabaseHas('penalty_withdrawals', ['id' => $withdrawalId, 'status' => 'completed']);
        $this->assertSame(1, DB::table('wallet_transactions')->where('reference_id', $withdrawalId)->where('direction', 'debit')->count());
        $this->assertSame(1, DB::table('wallet_transactions')->where('reference_id', $withdrawalId)->where('direction', 'reserve')->count());
    }

    public function test_missing_approver_signature_blocks_approval_until_uploaded(): void
    {
        $withdrawalId = $this->createWithdrawalRequest();
        $this->storeSignature('secretary', $this->secretaryUserId);

        $this->postJson('/api/withdrawal_signatories/update', [
            'withdrawal_id' => $withdrawalId,
            'signatory_role' => 'secretary',
            'status' => 'approved',
        ], $this->secretaryHeaders)->assertStatus(200);

        $this->postJson('/api/withdrawal_signatories/update', [
            'withdrawal_id' => $withdrawalId,
            'signatory_role' => 'chairperson',
            'status' => 'approved',
        ], $this->chairpersonHeaders)
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'signature_required');

        $this->assertDatabaseHas('penalty_withdrawals', [
            'id' => $withdrawalId,
            'status' => 'awaiting_signature',
        ]);
        $this->assertDatabaseHas('withdrawal_signatories', [
            'withdrawal_id' => $withdrawalId,
            'signatory_role' => 'chairperson',
            'status' => 'pending',
        ]);
        $this->assertDatabaseMissing('coop_transfer_requests', [
            'internal_reference' => $withdrawalId,
            'transfer_type' => 'B2C_MPESA',
        ]);

        $this->storeSignature('chairperson', $this->chairpersonUserId);
        $this->postJson('/api/withdrawal_signatories/update', [
            'withdrawal_id' => $withdrawalId,
            'signatory_role' => 'chairperson',
            'status' => 'approved',
        ], $this->chairpersonHeaders)->assertStatus(200);
        $this->assertDatabaseHas('penalty_withdrawals', [
            'id' => $withdrawalId,
            'status' => 'processing',
        ]);
    }

    public function test_provider_failure_releases_reservation_once(): void
    {
        $withdrawalId = $this->createWithdrawalRequest();
        $this->approveAndSubmit($withdrawalId);

        $transfer = CoopTransferRequest::query()->where('internal_reference', $withdrawalId)->firstOrFail();
        $transfer->status = 'failed';
        $transfer->save();
        app(WithdrawalApprovalService::class)->applyProviderOutcome($transfer->fresh());
        app(WithdrawalApprovalService::class)->applyProviderOutcome($transfer->fresh());

        $wallet = DB::table('penalty_wallet')->first();
        $this->assertEquals(100000, (float) $wallet->total_balance);
        $this->assertEquals(0, (float) $wallet->reserved_balance);
        $this->assertDatabaseHas('penalty_withdrawals', ['id' => $withdrawalId, 'status' => 'failed']);
        $this->assertSame(0, DB::table('wallet_transactions')->where('reference_id', $withdrawalId)->where('direction', 'debit')->count());
        $this->assertSame(1, DB::table('wallet_transactions')->where('reference_id', $withdrawalId)->where('direction', 'release')->count());
    }

    public function test_provider_reversal_credits_settlement_once(): void
    {
        $withdrawalId = $this->createWithdrawalRequest();
        $this->approveAndSubmit($withdrawalId);
        $this->confirmProviderSuccess($withdrawalId);

        $transfer = CoopTransferRequest::query()->where('internal_reference', $withdrawalId)->firstOrFail();
        $transfer->status = 'reversed';
        $transfer->save();
        app(WithdrawalApprovalService::class)->applyProviderOutcome($transfer->fresh());
        app(WithdrawalApprovalService::class)->applyProviderOutcome($transfer->fresh());

        $wallet = DB::table('penalty_wallet')->first();
        $this->assertEquals(100000, (float) $wallet->total_balance);
        $this->assertEquals(0, (float) $wallet->total_withdrawn);
        $this->assertDatabaseHas('penalty_withdrawals', ['id' => $withdrawalId, 'status' => 'reversed']);
        $this->assertSame(1, DB::table('wallet_transactions')
            ->where('reference_id', $withdrawalId)
            ->where('source', 'bank_withdrawal_reversal')
            ->count());
    }

    public function test_execute_withdrawal_b2c_job_can_be_dispatched(): void
    {
        $transactionId = Uuid::v4();
        $withdrawalId = Uuid::v4();

        DB::table('penalty_withdrawals')->insert([
            'id' => $withdrawalId,
            'amount' => 5000,
            'reason' => 'Test withdrawal',
            'status' => 'approved',
            'approval_stage' => 'chairperson',
            'requested_by' => Uuid::v4(),
            'phone_number' => '254712345678',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('b2c_transactions')->insert([
            'id' => $transactionId,
            'withdrawal_id' => $withdrawalId,
            'wallet_type' => 'penalty',
            'mpesa_transaction_id' => 'TEST' . time(),
            'phone_number' => '254712345678',
            'amount' => 5000,
            'status' => 'pending',
            'initiated_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $job = new \App\Jobs\Banking\ExecuteWithdrawalB2CJob(
            $transactionId,
            'penalty',
            $withdrawalId,
            5000,
            '254712345678',
            'Test B2C',
            Uuid::v4()
        );

        $this->assertInstanceOf(\Illuminate\Contracts\Queue\ShouldQueue::class, $job);
        $this->assertEquals(3, $job->tries);
        $this->assertEquals([60, 300, 900], $job->backoff);
    }

    public function test_production_approval_does_not_reserve_or_submit_b2c(): void
    {
        $withdrawalId = $this->createWithdrawalRequest('approved');
        config()->set('app.env', 'production');
        config()->set('coop_bank.enable_real_calls', true);
        config()->set('coop_bank.consumer_key', 'configured');
        config()->set('coop_bank.consumer_secret', 'configured');

        app(WithdrawalApprovalService::class)->triggerB2CWithdrawal('penalty', $withdrawalId, [
            'user_id' => $this->secretaryUserId,
            'roles' => ['secretary'],
        ]);

        $this->assertDatabaseHas('penalty_withdrawals', [
            'id' => $withdrawalId,
            'status' => 'approved',
        ]);
        $this->assertEquals(0, (float) DB::table('penalty_wallet')->value('reserved_balance'));
        $this->assertSame(0, CoopTransferRequest::query()->where('internal_reference', $withdrawalId)->count());
    }

    public function test_production_provider_service_fails_closed_without_calling_bank(): void
    {
        config()->set('app.env', 'production');
        config()->set('coop_bank.enable_real_calls', true);
        config()->set('coop_bank.consumer_key', 'configured');
        config()->set('coop_bank.consumer_secret', 'configured');

        $client = $this->mock(CoopBankClient::class);
        $client->shouldNotReceive('request');

        try {
            app(CoopBankTransferService::class)->sendToMpesa(
                Uuid::v4(),
                'penalty_withdrawals',
                ['wallet' => 'penalty_wallet'],
                100,
                '254712345678',
                'Production safety test'
            );
            $this->fail('Production provider submission should be blocked.');
        } catch (CoopBankException $exception) {
            $this->assertSame('B2C_DISABLED', $exception->getBankErrorCode());
        }
    }
}
