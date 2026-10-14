<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class ContributionAdjustmentService
{
    public function __construct(
        private FinancialReadModel $financialReadModel,
        private MemberFinancialProjectionService $projection,
    ) {}

    public function create(string $memberId, int $amountMinor, string $category, string $reason, string $actorId): array
    {
        if ($amountMinor === 0) {
            throw new \InvalidArgumentException('Adjustment amount must not be zero.');
        }

        $adjustment = DB::transaction(function () use ($memberId, $amountMinor, $category, $reason, $actorId): object {
            if (! DB::table('members')->where('id', $memberId)->exists()) {
                throw new \InvalidArgumentException('Member not found.');
            }

            $id = (string) Str::uuid();
            DB::table('contribution_adjustments')->insert([
                'id' => $id,
                'member_id' => $memberId,
                'amount_minor' => $amountMinor,
                'category' => $category,
                'reason' => $reason,
                'created_by' => $actorId,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $member = DB::table('members')->where('id', $memberId)->first(['name', 'user_id']);
            if ($member?->user_id && Schema::hasTable('notifications')) {
                DB::table('notifications')->insert([
                    'id' => (string) Str::uuid(),
                    'user_id' => $member->user_id,
                    'title' => 'Contribution adjusted',
                    'message' => sprintf(
                        '%s contribution was adjusted by KES %s. Reason: %s',
                        $amountMinor > 0 ? 'Your' : 'Your',
                        number_format(abs($amountMinor) / 100, 2),
                        $reason,
                    ),
                    'is_read' => false,
                    'type' => 'contribution_adjustment',
                    'created_at' => now(),
                ]);
            }

            return DB::table('contribution_adjustments')->where('id', $id)->first();
        });

        $this->projection->syncMember($memberId);

        return [
            'adjustment' => (array) $adjustment,
            'overview' => $this->financialReadModel->memberOverview($memberId),
        ];
    }
}
