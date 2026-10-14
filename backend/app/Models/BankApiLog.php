<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BankApiLog extends Model
{
    protected $table = 'bank_api_logs';

    protected $primaryKey = 'id';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'provider',
        'environment',
        'operation',
        'endpoint',
        'http_method',
        'message_reference',
        'correlation_id',
        'request_payload_redacted',
        'response_payload_redacted',
        'http_status',
        'bank_status',
        'bank_error_code',
        'bank_error_message',
        'transaction_status',
        'duration_ms',
        'success',
        'error_stage',
        'exception_class',
    ];
}
