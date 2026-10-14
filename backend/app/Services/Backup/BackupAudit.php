<?php

namespace App\Services\Backup;

use App\Models\AuditLog;
use App\Support\Uuid;

/**
 * Writes Backup & Restore events to the application's existing audit_logs
 * table. Reuses the legacy-compatible schema (super_admin_id, action,
 * target_user_id, target_member_id, details, ip_address, created_at) rather
 * than introducing a parallel audit system.
 */
class BackupAudit
{
    public const BACKUP_CREATED = 'BACKUP_CREATED';
    public const BACKUP_VERIFIED = 'BACKUP_VERIFIED';
    public const BACKUP_DOWNLOADED = 'BACKUP_DOWNLOADED';
    public const BACKUP_DELETED = 'BACKUP_DELETED';
    public const RESTORE_STARTED = 'RESTORE_STARTED';
    public const RESTORE_COMPLETED = 'RESTORE_COMPLETED';
    public const RESTORE_FAILED = 'RESTORE_FAILED';
    public const SAFETY_BACKUP_CREATED = 'SAFETY_BACKUP_CREATED';
    public const BACKUP_PRUNED = 'BACKUP_PRUNED';
    public const BACKUP_SETTINGS_UPDATED = 'BACKUP_SETTINGS_UPDATED';

    public static function log(
        string $action,
        ?string $superAdminId,
        array $details = [],
        ?string $ipAddress = null
    ): void {
        AuditLog::insert([
            'id' => Uuid::v4(),
            'super_admin_id' => $superAdminId,
            'action' => $action,
            'target_user_id' => $details['target_user_id'] ?? null,
            'target_member_id' => $details['target_member_id'] ?? null,
            'details' => json_encode(array_diff_key($details, array_flip(['target_user_id', 'target_member_id']))),
            'ip_address' => $ipAddress,
            'created_at' => now(),
        ]);
    }
}
