<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BankSyncAudit extends Model
{
    protected $table = 'bank_sync_audit';
    public $incrementing = false;
    protected $keyType = 'string';
    public $timestamps = false;
    protected $guarded = [];

    protected $casts = [
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'total_bank_transactions' => 'integer',
        'new_transactions' => 'integer',
        'duplicate_skipped' => 'integer',
        'matched' => 'integer',
        'unmatched' => 'integer',
    ];
}
