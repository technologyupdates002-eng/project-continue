<?php

namespace Tests\Feature\Wallets;

use App\Support\Uuid;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class WalletTest extends TestCase
{
    private function uniqueEmail(): string
    {
        return 'test-wallet-'.Uuid::v4().'@welfare.local';
    }

    private function authHeader(string $role = 'admin'): array
    {
        $userId = Uuid::v4();
        $email = $this->uniqueEmail();

        DB::table('auth_users')->insert([
            'id' => $userId,
            'email' => $email,
            'encrypted_password' => password_hash('password', PASSWORD_BCRYPT),
            'is_banned' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('user_roles')->insert([
            'id' => Uuid::v4(),
            'user_id' => $userId,
            'role' => $role,
            'is_active' => 1,
        ]);

        $token = \App\Services\AuthService::issueSession([
            'id' => $userId,
            'email' => $email,
        ])['access_token'];

        return ['Authorization' => 'Bearer '.$token];
    }

    private function resetWallet(string $walletType): void
    {
        $walletTable = $walletType.'_wallet';
        $walletId = DB::table($walletTable)->value('id');

        if ($walletId) {
            DB::table('wallet_transactions')->where('wallet_type', $walletType)->delete();
            DB::table($walletTable)->where('id', $walletId)->delete();
        }
    }

    public function test_wallet_credit_creates_transaction(): void
    {
        $this->resetWallet('donation');
        $headers = $this->authHeader();

        $response = $this->postJson('/api/wallets/donation/credit', [
            'member_id' => '00000000-0000-0000-0000-000000000000',
            'amount' => 500,
            'source' => 'test_credit',
            'notes' => 'Test credit',
        ], $headers);

        $response->assertStatus(200)
            ->assertJsonStructure(['success', 'wallet_type', 'amount', 'new_balance', 'transaction_id'])
            ->assertJson(['success' => true, 'wallet_type' => 'donation', 'amount' => 500]);

        $this->assertDatabaseHas('wallet_transactions', [
            'wallet_type' => 'donation',
            'direction' => 'credit',
            'gross_amount' => 500,
        ]);
    }

    public function test_wallet_debit_reduces_balance(): void
    {
        $this->resetWallet('operational');
        $headers = $this->authHeader();

        $this->postJson('/api/wallets/operational/credit', [
            'member_id' => '00000000-0000-0000-0000-000000000001',
            'amount' => 500,
            'source' => 'test_debit',
        ], $headers);

        $response = $this->postJson('/api/wallets/operational/debit', [
            'member_id' => '00000000-0000-0000-0000-000000000001',
            'amount' => 200,
            'reason' => 'Test withdrawal',
        ], $headers);

        $response->assertStatus(200)
            ->assertJson(['success' => true])
            ->assertJsonFragment(['amount' => 200]);
    }

    public function test_wallet_debit_fails_on_insufficient_balance(): void
    {
        $this->resetWallet('penalty');
        $headers = $this->authHeader();

        $response = $this->postJson('/api/wallets/penalty/debit', [
            'member_id' => '00000000-0000-0000-0000-000000000002',
            'amount' => 1000,
            'reason' => 'Test overdraft',
        ], $headers);

        $response->assertStatus(422)
            ->assertJsonFragment(['code' => 'insufficient_balance']);
    }

    public function test_wallet_balance_returns_current_state(): void
    {
        $this->resetWallet('operational');
        $headers = $this->authHeader();

        $this->postJson('/api/wallets/operational/credit', [
            'member_id' => '00000000-0000-0000-0000-000000000003',
            'amount' => 750,
            'source' => 'test_balance',
        ], $headers);

        $response = $this->getJson('/api/wallets/operational/balance', $headers);

        $response->assertStatus(200)
            ->assertJsonStructure(['wallet_type', 'total_balance', 'total_received', 'total_withdrawn'])
            ->assertJson(['wallet_type' => 'operational'])
            ->assertJsonFragment(['total_balance' => 750]);
    }

    public function test_wallet_transactions_returns_history(): void
    {
        $this->resetWallet('donation');
        $headers = $this->authHeader();

        $this->postJson('/api/wallets/donation/credit', [
            'member_id' => '00000000-0000-0000-0000-000000000004',
            'amount' => 300,
            'source' => 'test_history',
        ], $headers);

        $response = $this->getJson('/api/wallets/donation/transactions?member_id=00000000-0000-0000-0000-000000000004', $headers);

        $response->assertStatus(200)
            ->assertJsonStructure(['data'])
            ->assertJsonCount(1, 'data');
    }
}
