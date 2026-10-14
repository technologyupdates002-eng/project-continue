<?php

namespace App\Events\Banking;

use App\Models\CoopCallback;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class BankCallbackReceived
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public CoopCallback $callback,
        public array $payload
    ) {}
}
