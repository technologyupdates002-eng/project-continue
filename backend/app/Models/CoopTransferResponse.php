<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CoopTransferResponse extends Model
{
    protected $table = 'coop_transfer_responses';
    public $incrementing = false;
    protected $keyType = 'string';
    public $timestamps = false;
    protected $guarded = [];

    protected $casts = [
        'response_payload' => 'array',
        'received_at' => 'datetime',
        'processed_at' => 'datetime',
    ];

    public function transferRequest(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(CoopTransferRequest::class, 'transfer_request_id');
    }
}
