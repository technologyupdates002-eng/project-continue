<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CoopTransferRequest extends Model
{
    protected $table = 'coop_transfer_requests';
    public $incrementing = false;
    protected $keyType = 'string';
    public $timestamps = true;
    protected $guarded = [];

    protected $casts = [
        'id' => 'string',
        'amount' => 'decimal:2',
        'request_payload' => 'array',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function responses(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(CoopTransferResponse::class, 'transfer_request_id');
    }

    public function latestResponse(): ?CoopTransferResponse
    {
        return $this->responses()->latest('received_at')->first();
    }

    public function isComplete(): bool
    {
        return in_array($this->status, ['successful', 'failed', 'reversed']);
    }

    public function isPending(): bool
    {
        return in_array($this->status, ['initiated', 'submitted', 'processing']);
    }
}
