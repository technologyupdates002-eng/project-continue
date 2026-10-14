<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BankDiagnosticRun extends Model
{
    protected $table = 'bank_diagnostic_runs';

    protected $primaryKey = 'id';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'diagnostic_id',
        'test_type',
        'environment',
        'status',
        'error_category',
        'http_status',
        'bank_response_code',
        'bank_response_message',
        'message_reference',
        'bank_reference',
        'duration_ms',
        'endpoint',
        'http_method',
        'executed_by',
        'request_summary',
        'response_summary',
        'raw_response',
        'conclusion',
        'human_explanation',
    ];

    protected $casts = [
        'request_summary' => 'array',
        'response_summary' => 'array',
        'raw_response' => 'array',
        'duration_ms' => 'integer',
        'http_status' => 'integer',
    ];
}
