<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Flat Eloquent model for the "beneficiary_import_rows" table.
 *
 * Mirrors the legacy schema: UUID (CHAR36) primary key, no auto-increment,
 * no Eloquent timestamps (managed via Hooks / triggers), all columns
 * mass-assignable. Intentionally has no relationships to match the legacy
 * REST behaviour exactly.
 */
class BeneficiaryImportRow extends Model
{
    protected $table = 'beneficiary_import_rows';

    public $incrementing = false;

    protected $keyType = 'string';

    public $timestamps = false;

    protected $guarded = [];
}
