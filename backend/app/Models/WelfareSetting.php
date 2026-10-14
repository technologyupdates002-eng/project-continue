<?php

namespace App\Models;

use App\Support\Uuid;
use Illuminate\Database\Eloquent\Model;

/**
 * Flat Eloquent model for the "welfare_settings" table.
 *
 * Mirrors the legacy schema: UUID (CHAR36) primary key, no auto-increment,
 * no Eloquent timestamps (managed via Hooks / triggers), all columns
 * mass-assignable. Intentionally has no relationships to match the legacy
 * REST behaviour exactly.
 */
class WelfareSetting extends Model
{
    protected $table = 'welfare_settings';

    public $incrementing = false;

    protected $keyType = 'string';

    public $timestamps = false;

    protected $guarded = [];

    protected static function boot()
    {
        parent::boot();

        static::creating(function (self $model) {
            if (empty($model->id)) {
                $model->id = Uuid::v4();
            }
        });
    }

    public static function current(): self
    {
        return self::query()->orderBy('created_at')->first()
            ?? self::query()->create([
                'name' => 'Welfare',
                'monthly_contribution_amount' => 500,
                'contribution_due_day' => 5,
                'penalty_amount' => 100,
                'penalty_grace_days' => 7,
            ]);
    }
}
