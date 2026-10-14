<?php

declare(strict_types=1);

namespace Tests\Feature\Payments;

use App\Support\Uuid;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class DonationWalletBackfillTest extends TestCase
{
    use RefreshDatabase;

    public function test_dry_run_reports_missing_credits_without_changing_wallet(): void
    {
        $this->createDonation(125, 'verified');

        $this->artisan('donations:backfill-wallet')
            ->assertExitCode(0);

        $this->assertSame(0, DB::table('wallet_transactions')->count());
        $this->assertSame(0.0, (float) (DB::table('donation_wallet')->value('total_balance') ?? 0));
    }

    public function test_apply_credits_only_missing_verified_donations_and_is_idempotent(): void
    {
        $firstId = $this->createDonation(125, 'verified');
        $secondId = $this->createDonation(75, 'verified');
        $this->createDonation(999, 'pending');

        $this->artisan('donations:backfill-wallet')
            ->assertExitCode(0);
        $this->assertSame(0, DB::table('wallet_transactions')->count());

        $this->artisan('donations:backfill-wallet', ['--apply' => true])
            ->assertExitCode(0);
        $this->artisan('donations:backfill-wallet', ['--apply' => true])
            ->assertExitCode(0);

        $this->assertSame(2, DB::table('wallet_transactions')
            ->where('wallet_type', 'donation')
            ->where('direction', 'credit')
            ->count());
        $this->assertSame(200.0, (float) DB::table('donation_wallet')->value('total_balance'));
        $this->assertSame(1, DB::table('donation_payment_records')
            ->where('status', 'pending')
            ->count());
        $this->assertSame(1, DB::table('wallet_transactions')->where('reference_id', $firstId)->count());
        $this->assertSame(1, DB::table('wallet_transactions')->where('reference_id', $secondId)->count());
    }

    private function createDonation(float $amount, string $status): string
    {
        $id = Uuid::v4();
        DB::table('donation_payment_records')->insert([
            'id' => $id,
            'campaign_id' => null,
            'member_id' => null,
            'amount' => $amount,
            'status' => $status,
            'mpesa_transaction_id' => 'DONATION-'.strtoupper(Uuid::v4()),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $id;
    }
}
