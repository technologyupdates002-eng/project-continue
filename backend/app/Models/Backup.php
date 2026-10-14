<?php

namespace App\Models;

use App\Support\Uuid;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Backup metadata record.
 *
 * Tracks every backup/restore artefact created by the Super Admin
 * Backup & Restore subsystem. The actual archive bytes live on the
 * private `backups` filesystem disk and are never web-served.
 */
class Backup extends Model
{
    protected $table = 'backups';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'name',
        'type',
        'disk',
        'path',
        'size_bytes',
        'status',
        'checksum',
        'checksum_algo',
        'verified_at',
        'verification_status',
        'verification_details',
        'failure_reason',
        'application_version',
        'laravel_version',
        'database_engine',
        'database_version',
        'metadata',
        'created_by',
        'safety_backup_id',
        'restored_from_backup_id',
        'is_safety',
        'started_at',
        'completed_at',
    ];

    protected $casts = [
        'size_bytes' => 'integer',
        'metadata' => 'array',
        'verification_details' => 'array',
        'is_safety' => 'boolean',
        'verified_at' => 'datetime',
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
    ];

    protected static function boot()
    {
        parent::boot();

        static::creating(function (self $model) {
            if (empty($model->id)) {
                $model->id = Uuid::v4();
            }
            if (empty($model->status)) {
                $model->status = 'pending';
            }
        });
    }

    // Status constants
    public const STATUS_PENDING = 'pending';
    public const STATUS_RUNNING = 'running';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_FAILED = 'failed';
    public const STATUS_DELETED = 'deleted';
    public const STATUS_RESTORING = 'restoring';
    public const STATUS_RESTORED = 'restored';

    // Type constants
    public const TYPE_FULL = 'full';
    public const TYPE_DATABASE = 'database';
    public const TYPE_STORAGE = 'storage';

    public const VERIFICATION_PENDING = 'pending';
    public const VERIFICATION_PASSED = 'passed';
    public const VERIFICATION_FAILED = 'failed';

    public function scopeCompleted(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_COMPLETED);
    }

    public function scopeNotSafety(Builder $query): Builder
    {
        return $query->where('is_safety', false);
    }

    public function isDownloadable(): bool
    {
        return in_array($this->status, [self::STATUS_COMPLETED, self::STATUS_RESTORED], true)
            && ! empty($this->path);
    }

    public function isRestorable(): bool
    {
        return $this->status === self::STATUS_COMPLETED
            && $this->verification_status === self::VERIFICATION_PASSED;
    }
}
