<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CoopCallback extends Model
{
    protected $table = 'coop_callbacks';
    public $incrementing = false;
    protected $keyType = 'string';
    public $timestamps = false;
    protected $guarded = [];

    protected $casts = [
        'request_payload' => 'array',
        'response_payload' => 'array',
        'received_at' => 'datetime',
        'processed_at' => 'datetime',
    ];
}
