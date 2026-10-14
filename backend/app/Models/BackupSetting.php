<?php

namespace App\Models;

use App\Support\Uuid;
use Illuminate\Database\Eloquent\Model;

/**
 * Persisted Backup & Restore settings (single row).
 */
class BackupSetting extends Model
{
    protected $table = 'backup_settings';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'settings',
        'updated_by',
    ];

    protected $casts = [
        'settings' => 'array',
    ];

    protected static function boot()
    {
        parent::boot();

        static::creating(function (self $model) {
            if (empty($model->id)) {
                $model->id = Uuid::v4();
            }
        });
    }

    /**
     * The single settings row, created on first access.
     */
    public static function current(): self
    {
        return self::query()->orderBy('created_at')->first()
            ?? self::query()->create(['settings' => []]);
    }
}
