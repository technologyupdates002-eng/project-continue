<?php

declare(strict_types=1);

namespace App\Services\Statement;

class AllocationResult
{
    public ?string $memberId = null;
    public ?string $paymentDate = null;
    public int $monthsAllocated = 0;
    public int $monthsFullyPaid = 0;
    public int $monthsCreated = 0;
    public int $monthsUpdated = 0;
    public float $creditRemaining = 0.0;
    public bool $wasCreated = false;
    public bool $wasUpdated = false;
    public ?string $firstMonth = null;
    public ?string $lastMonth = null;
    public ?string $nextDueDate = null;
    public ?string $contributionId = null;
}