<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class MemberPayoutService
{
    public function markWithdrawalCompleted(string $withdrawalId): void
    {
        $payout = DB::transaction(function () use ($withdrawalId) {
            $payout = DB::table('payouts')
                ->where('payment_reference', $withdrawalId)
                ->whereIn('status', ['pending', 'approved'])
                ->lockForUpdate()
                ->first();
            if (!$payout) {
                return null;
            }

            DB::table('payouts')->where('id', $payout->id)->update([
                'status' => 'paid',
                'paid_at' => now(),
                'updated_at' => now(),
            ]);

            return $payout;
        });

        if (!$payout) {
            return;
        }

        try {
            $member = DB::table('members')->where('id', $payout->member_id)->first();
            if (!$member || !$member->phone) {
                return;
            }
            $organizationName = DB::table('organization_settings')->value('organization_name') ?: 'KHWWC';
            $reference = 'PAY-' . strtoupper(substr((string) $payout->id, 0, 8));
            $message = "Dear {$member->name}, your {$organizationName} payout of KSh "
                . number_format((float) $payout->amount, 2)
                . " has been successfully processed. Reference: {$reference}. Thank you.";

            app(SmsService::class)->send(
                (string) $member->phone,
                $message,
                'member_payout',
                (string) $payout->id
            );
        } catch (\Throwable $e) {
            Log::warning('Member payout SMS failed after payout completion', [
                'payout_id' => $payout->id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
