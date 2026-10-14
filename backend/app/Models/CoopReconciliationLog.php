<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CoopReconciliationLog extends Model
{
    protected $table = 'coop_reconciliation_logs';
    public $incrementing = false;
    protected $keyType = 'string';
    public $timestamps = false;
    protected $guarded = [];

    protected $casts = [
        'total_bank_transactions' => 'integer',
        'total_internal_transactions' => 'integer',
        'matched_count' => 'integer',
        'unmatched_bank_count' => 'integer',
        'unmatched_internal_count' => 'integer',
        'duplicate_count' => 'integer',
        'amount_variance' => 'decimal:2',
        'report_payload' => 'array',
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
    ];
}
