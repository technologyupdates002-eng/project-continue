<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CoopAccountEnquiry extends Model
{
    protected $table = 'coop_account_enquiries';
    public $incrementing = false;
    protected $keyType = 'string';
    public $timestamps = false;
    protected $guarded = [];

    protected $casts = [
        'no_of_transactions' => 'integer',
        'response_payload' => 'array',
        'created_at' => 'datetime',
    ];
}
