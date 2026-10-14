<?php

declare(strict_types=1);

namespace App\Services\Statement;

/**
 * Per-transaction outcome returned by StatementProcessor::process().
 */
class ProcessOutcome
{
    public int $credits = 0;
    public int $debits = 0;
    public int $matchedExisting = 0;
    public int $newMembers = 0;
    public int $contributionsCreated = 0;
    public int $contributionsUpdated = 0;
    public int $duplicatesSkipped = 0;
    public int $unmatched = 0;
    public int $invalidPhones = 0;
    public float $totalAmount = 0.0;
    public float $credit = 0.0;
    public float $debit = 0.0;

    public ?string $memberId = null;
    public ?string $bankTxId = null;
    public bool $wasMatched = false;
    public ?string $reason = null;

    public ?string $extractedPhone = null;
    public ?string $normalizedPhone = null;
    public ?string $extractedName = null;

    public function toArray(): array
    {
        return [
            'credits' => $this->credits,
            'debits' => $this->debits,
            'matched_existing' => $this->matchedExisting,
            'new_members' => $this->newMembers,
            'contributions_created' => $this->contributionsCreated,
            'contributions_updated' => $this->contributionsUpdated,
            'duplicates_skipped' => $this->duplicatesSkipped,
            'unmatched' => $this->unmatched,
            'invalid_phones' => $this->invalidPhones,
            'total_amount' => $this->totalAmount,
            'credit' => $this->credit,
            'debit' => $this->debit,
            'member_id' => $this->memberId,
            'bank_tx_id' => $this->bankTxId,
            'was_matched' => $this->wasMatched,
            'reason' => $this->reason,
        ];
    }
}