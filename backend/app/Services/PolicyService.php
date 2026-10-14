<?php

namespace App\Services;

use App\Exceptions\HttpException;

/**
 * Row-level security / authorization matrix.
 *
 * 1:1 port of the legacy Policy.php TABLES structure. Each table entry
 * describes who may read all rows, who may write all rows, the column used
 * for owner-scoping, and whether unauthenticated inserts are allowed.
 *
 * read  : '*'        => any authenticated user may read all rows
 *         'STAFF'    => only staff may read all rows (others scoped to own)
 *         [roles..]  => only those roles may read all rows
 * write : '*'        => any authenticated user may write all rows
 *         [roles..]  => only those roles may write all rows
 * own   : column name used for owner scoping (member_id / user_id) or null
 * ownWrite : member may insert/update their own rows
 * publicInsert : no authentication required for INSERT
 * staffOnlyRead : read-all requires staff; non-staff are scoped to own rows
 */
class PolicyService
{
    public const STAFF_SET = [
        'admin', 'super_admin', 'treasurer', 'chairperson',
        'vice_chairperson', 'secretary', 'vice_secretary', 'patron',
    ];

    public const TABLES = [
        'members' => ['read' => '*', 'write' => ['admin', 'super_admin'], 'own' => 'user_id', 'ownWrite' => true],
        'user_roles' => ['read' => '*', 'write' => ['admin', 'super_admin']],
        'beneficiaries' => ['read' => 'STAFF', 'write' => ['admin', 'super_admin'], 'own' => 'member_id', 'ownWrite' => true, 'staffOnlyRead' => true],
        'beneficiary_requests' => ['read' => 'STAFF', 'write' => ['admin', 'super_admin'], 'own' => 'member_id', 'ownWrite' => true, 'staffOnlyRead' => true],
        'documents' => ['read' => 'STAFF', 'write' => ['admin', 'super_admin'], 'own' => 'member_id', 'ownWrite' => true, 'staffOnlyRead' => true],
        'contributions' => ['read' => 'STAFF', 'write' => ['admin', 'super_admin', 'treasurer'], 'own' => 'member_id', 'staffOnlyRead' => true],
        'penalties' => ['read' => 'STAFF', 'write' => ['admin', 'super_admin', 'treasurer'], 'own' => 'member_id', 'staffOnlyRead' => true],
        'penalty_payments' => ['read' => 'STAFF', 'write' => ['admin', 'super_admin', 'treasurer'], 'own' => 'member_id', 'ownWrite' => true, 'staffOnlyRead' => true],
        'penalty_payment_records' => ['read' => 'STAFF', 'write' => ['admin', 'super_admin', 'treasurer'], 'own' => 'member_id', 'ownWrite' => true, 'staffOnlyRead' => true],
        'donation_payment_records' => ['read' => 'STAFF', 'write' => ['admin', 'super_admin', 'treasurer'], 'own' => 'member_id', 'ownWrite' => true, 'staffOnlyRead' => true],
        'operational_payment_records' => ['read' => 'STAFF', 'write' => ['admin', 'super_admin', 'treasurer'], 'own' => 'member_id', 'ownWrite' => true, 'staffOnlyRead' => true],
        'payments' => ['read' => 'STAFF', 'write' => ['admin', 'super_admin', 'treasurer'], 'own' => 'member_id', 'staffOnlyRead' => true],
        'payouts' => ['read' => 'STAFF', 'write' => [], 'own' => 'member_id', 'staffOnlyRead' => true],
        'bank_transactions' => ['read' => 'STAFF', 'write' => ['admin', 'super_admin', 'treasurer'], 'own' => 'member_id', 'staffOnlyRead' => true],
        'news' => ['read' => '*', 'write' => ['admin', 'super_admin', 'secretary', 'vice_secretary']],
        'events' => ['read' => '*', 'write' => ['admin', 'super_admin', 'secretary', 'vice_secretary']],
        'donation_campaigns' => ['read' => '*', 'write' => ['admin', 'super_admin', 'treasurer']],
        'executive_badges' => ['read' => '*', 'write' => ['admin', 'super_admin']],
        'member_executive_roles' => ['read' => '*', 'write' => ['admin', 'super_admin']],
        'office_bearer_signatures' => ['read' => '*', 'write' => ['admin', 'super_admin']],
        'organization_settings' => ['read' => '*', 'write' => ['admin', 'super_admin']],
        'welfare_settings' => ['read' => '*', 'write' => ['admin', 'super_admin']],
        'registration_config' => ['read' => '*', 'write' => ['admin', 'super_admin']],
        'memo_templates' => ['read' => 'STAFF', 'write' => ['admin', 'super_admin', 'secretary', 'treasurer'], 'staffOnlyRead' => true],
        'notifications' => ['read' => ['admin', 'super_admin'], 'write' => ['admin', 'super_admin'], 'own' => 'user_id', 'ownWrite' => true, 'staffOnlyRead' => true],
        'news_read' => ['read' => '*', 'write' => ['admin', 'super_admin'], 'own' => 'user_id', 'ownWrite' => true],
        'push_tokens' => ['read' => ['admin', 'super_admin'], 'write' => ['admin', 'super_admin'], 'own' => 'user_id', 'ownWrite' => true, 'staffOnlyRead' => true],
        'user_presence' => ['read' => '*', 'write' => '*', 'own' => 'user_id', 'ownWrite' => true],
        'dashboard_security' => ['read' => [], 'write' => [], 'own' => 'user_id', 'ownWrite' => true, 'staffOnlyRead' => true],
        'signatory_signatures' => ['read' => 'STAFF', 'write' => ['admin', 'super_admin'], 'own' => 'user_id', 'ownWrite' => true, 'staffOnlyRead' => true],
        'conversations' => ['read' => '*', 'write' => '*'],
        'conversation_participants' => ['read' => '*', 'write' => '*'],
        'messages' => ['read' => '*', 'write' => '*'],
        'message_reactions' => ['read' => '*', 'write' => '*'],
        'memos' => ['read' => 'STAFF', 'write' => ['admin', 'super_admin', 'secretary', 'vice_secretary', 'treasurer', 'chairperson'], 'staffOnlyRead' => true],
        'memo_recipients' => ['read' => 'STAFF', 'write' => ['admin', 'super_admin', 'secretary', 'vice_secretary', 'treasurer', 'chairperson'], 'own' => 'member_id', 'ownWrite' => true, 'staffOnlyRead' => true],
        'meeting_minutes' => ['read' => 'STAFF', 'write' => ['admin', 'super_admin', 'secretary', 'vice_secretary', 'chairperson'], 'staffOnlyRead' => true],
        'meeting_attendance' => ['read' => 'STAFF', 'write' => ['admin', 'super_admin', 'secretary', 'vice_secretary'], 'staffOnlyRead' => true],
        'action_items' => ['read' => 'STAFF', 'write' => ['admin', 'super_admin', 'secretary', 'vice_secretary'], 'staffOnlyRead' => true],
        'penalty_wallet' => ['read' => '*', 'write' => ['admin', 'super_admin', 'treasurer'], 'immutable' => true],
        'donation_wallet' => ['read' => '*', 'write' => ['admin', 'super_admin', 'treasurer'], 'immutable' => true],
        'operational_wallet' => ['read' => '*', 'write' => ['admin', 'super_admin', 'treasurer'], 'immutable' => true],
        'wallet_transactions' => ['read' => 'STAFF', 'write' => ['admin', 'super_admin', 'treasurer'], 'staffOnlyRead' => true],
        'book_balance' => ['read' => 'STAFF', 'write' => ['admin', 'super_admin', 'treasurer'], 'staffOnlyRead' => true],
        'expenses' => ['read' => 'STAFF', 'write' => ['admin', 'super_admin', 'treasurer'], 'staffOnlyRead' => true],
        'financial_reports' => ['read' => 'STAFF', 'write' => ['admin', 'super_admin', 'treasurer'], 'staffOnlyRead' => true],
        'penalty_withdrawals' => ['read' => 'STAFF', 'write' => ['admin', 'super_admin', 'treasurer'], 'own' => 'requested_by', 'staffOnlyRead' => true],
        'donation_withdrawals' => ['read' => 'STAFF', 'write' => ['admin', 'super_admin', 'treasurer'], 'own' => 'requested_by', 'staffOnlyRead' => true],
        'operational_withdrawals' => ['read' => 'STAFF', 'write' => ['admin', 'super_admin', 'treasurer'], 'own' => 'requested_by', 'staffOnlyRead' => true],
        'withdrawal_signatories' => ['read' => 'STAFF', 'write' => 'STAFF', 'staffOnlyRead' => true],
        'donation_withdrawal_signatories' => ['read' => 'STAFF', 'write' => 'STAFF', 'staffOnlyRead' => true],
        'operational_withdrawal_signatories' => ['read' => 'STAFF', 'write' => 'STAFF', 'staffOnlyRead' => true],
        'withdrawal_receipts' => ['read' => 'STAFF', 'write' => ['admin', 'super_admin', 'treasurer'], 'staffOnlyRead' => true],
        'b2c_transactions' => ['read' => 'STAFF', 'write' => ['admin', 'super_admin', 'treasurer'], 'own' => 'member_id', 'staffOnlyRead' => true],
        'unmatched_payments' => ['read' => 'STAFF', 'write' => ['admin', 'super_admin', 'treasurer'], 'own' => 'member_id', 'staffOnlyRead' => true],
        'member_registrations' => ['read' => ['admin', 'super_admin'], 'write' => ['admin', 'super_admin'], 'publicInsert' => true, 'staffOnlyRead' => true],
        'registrations' => ['read' => ['admin', 'super_admin'], 'write' => ['admin', 'super_admin'], 'publicInsert' => true, 'staffOnlyRead' => true],
        'withdrawals' => ['read' => 'STAFF', 'write' => ['admin', 'super_admin', 'treasurer'], 'own' => 'member_id', 'staffOnlyRead' => true],
        'registration_fees' => ['read' => ['admin', 'super_admin'], 'write' => ['admin', 'super_admin'], 'publicInsert' => true, 'staffOnlyRead' => true],
        'registration_access_links' => ['read' => ['admin', 'super_admin'], 'write' => ['admin', 'super_admin'], 'staffOnlyRead' => true],
        'audit_logs' => ['read' => ['super_admin'], 'write' => ['super_admin', 'admin'], 'staffOnlyRead' => true],
        'member_access_logs' => ['read' => ['super_admin'], 'write' => ['super_admin', 'admin'], 'staffOnlyRead' => true],
        'system_logs' => ['read' => ['super_admin'], 'write' => ['super_admin', 'admin'], 'staffOnlyRead' => true],
        'system_health' => ['read' => ['super_admin'], 'write' => ['super_admin'], 'staffOnlyRead' => true],
        'sms_logs' => ['read' => ['admin', 'super_admin'], 'write' => ['admin', 'super_admin'], 'staffOnlyRead' => true],
        'password_resets' => ['read' => ['super_admin'], 'write' => ['super_admin'], 'staffOnlyRead' => true],
        'coop_tokens' => ['read' => ['super_admin'], 'write' => ['super_admin']],
        'coop_transfer_requests' => ['read' => ['admin', 'treasurer'], 'write' => ['admin', 'treasurer']],
        'coop_transfer_responses' => ['read' => ['admin', 'treasurer'], 'write' => ['admin', 'treasurer']],
        'coop_notifications_ins' => ['read' => ['admin', 'treasurer'], 'write' => ['admin', 'treasurer']],
        'coop_callbacks' => ['read' => ['admin', 'treasurer'], 'write' => ['admin', 'treasurer']],
        'coop_reconciliation_logs' => ['read' => ['admin', 'treasurer'], 'write' => ['admin', 'treasurer']],
        'storage_objects' => ['read' => '*', 'write' => ['admin', 'super_admin', 'treasurer', 'secretary'], 'own' => 'owner_id', 'ownWrite' => true],
        'member_import_batches' => ['read' => ['admin', 'treasurer'], 'write' => ['admin', 'treasurer'], 'staffOnlyRead' => true],
        'member_import_rows' => ['read' => ['admin', 'treasurer'], 'write' => [], 'staffOnlyRead' => true],
        'beneficiary_import_batches' => ['read' => ['admin', 'treasurer'], 'write' => ['admin', 'treasurer'], 'staffOnlyRead' => true],
        'beneficiary_import_rows' => ['read' => ['admin', 'treasurer'], 'write' => [], 'staffOnlyRead' => true],
    ];

    public static function rules(string $table): ?array
    {
        return self::TABLES[$table] ?? null;
    }

    public static function canReadAll(string $table, array $roles): bool
    {
        $rule = self::TABLES[$table] ?? null;
        if ($rule === null) {
            return false;
        }

        $read = $rule['read'];

        if ($read === '*') {
            return true;
        }

        if ($read === 'STAFF') {
            return AuthService::isStaff($roles);
        }

        return count(array_intersect($roles, $read)) > 0;
    }

    public static function canWriteAll(string $table, array $roles): bool
    {
        $rule = self::TABLES[$table] ?? null;
        if ($rule === null) {
            return false;
        }

        $write = $rule['write'];

        if ($write === '*') {
            return true;
        }

        if ($write === 'STAFF') {
            return AuthService::isStaff($roles);
        }

        if (! is_array($write)) {
            return false;
        }

        return count(array_intersect($roles, $write)) > 0;
    }

    public static function publicInsert(string $table): bool
    {
        return (self::TABLES[$table]['publicInsert'] ?? false) === true;
    }

    public static function staffOnlyRead(string $table): bool
    {
        return (self::TABLES[$table]['staffOnlyRead'] ?? false) === true;
    }

    public static function ownColumn(string $table): ?string
    {
        return self::TABLES[$table]['own'] ?? null;
    }

    public static function ownWrite(string $table): bool
    {
        return (self::TABLES[$table]['ownWrite'] ?? false) === true;
    }

    /**
     * Return the owner-scoping clause for a table, or null when the caller
     * may read all rows.
     *
     * @return array{column:string,value:string}|null
     */
    public static function readScope(string $table, array $identity): ?array
    {
        // When view-as-member is active, scope reads to the viewed member
        // regardless of the admin's normal read permissions. This prevents
        // an admin from reading other members' data while inspecting a
        // specific viewed member.
        if (! empty($identity['is_view_as_member'])) {
            $own = self::ownColumn($table);
            if ($own === null) {
                return null;
            }

            $viewedMemberId = $identity['viewing_member_id'] ?? null;
            $viewedMemberUserId = $identity['viewing_member_user_id'] ?? null;

            if ($own === 'user_id') {
                $value = $viewedMemberUserId;
            } else {
                $value = $viewedMemberId;
            }

            if (empty($value)) {
                return null;
            }

            return ['column' => $own, 'value' => $value];
        }

        if (self::canReadAll($table, $identity['roles'])) {
            return null;
        }

        $own = self::ownColumn($table);
        if ($own === null) {
            return null;
        }

        $value = $own === 'user_id' ? $identity['user_id'] : $identity['member_id'];

        if (empty($value)) {
            return null;
        }

        return ['column' => $own, 'value' => $value];
    }

    /**
     * Ensure the current identity may write to the given row. Throws 403
     * (insufficient_permissions) when not permitted.
     */
    public static function assertWrite(string $table, array $identity, ?array $row = null): void
    {
        if (! self::tableExists($table)) {
            throw new HttpException(403, 'table_not_allowed', 'Table not allowed.');
        }

        if (self::canWriteAll($table, $identity['roles'])) {
            return;
        }

        $own = self::ownColumn($table);
        if ($own !== null && self::ownWrite($table) && $row !== null) {
            $value = $own === 'user_id' ? $identity['user_id'] : $identity['member_id'];
            if (! empty($value) && ($row[$own] ?? null) == $value) {
                return;
            }
        }

        throw new HttpException(403, 'insufficient_permissions', 'You do not have permission to write to this resource.');
    }

    public static function tableExists(string $table): bool
    {
        return isset(self::TABLES[$table]);
    }
}
