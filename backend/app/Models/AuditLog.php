<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Flat Eloquent model for the "audit_logs" table.
 *
 * Mirrors the legacy schema: UUID (CHAR36) primary key, no auto-increment,
 * no Eloquent timestamps (managed via Hooks / triggers), all columns
 * mass-assignable. Intentionally has no relationships to match the legacy
 * REST behaviour exactly.
 */
class AuditLog extends Model
{
    protected $table = 'audit_logs';

    public $incrementing = false;

    protected $keyType = 'string';

    public $timestamps = false;

    protected $guarded = [];
}
