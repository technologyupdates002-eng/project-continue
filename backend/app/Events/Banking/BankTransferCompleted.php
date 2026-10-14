<?php

namespace App\Events\Banking;

use App\Models\CoopTransferRequest;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class BankTransferCompleted
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public CoopTransferRequest $transfer,
        public string $finalStatus,
        public ?string $bankTransactionId = null
    ) {}
}
