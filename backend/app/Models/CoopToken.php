<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CoopToken extends Model
{
    protected $table = 'coop_tokens';
    public $incrementing = false;
    protected $keyType = 'string';
    public $timestamps = false;
    protected $guarded = [];

    protected $casts = [
        'expires_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];
}
