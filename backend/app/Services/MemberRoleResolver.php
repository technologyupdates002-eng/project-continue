<?php

declare(strict_types=1);

namespace App\Services;

use App\Support\Uuid;
use Illuminate\Support\Facades\DB;

/**
 * Centralized role / permission resolution.
 *
 * The application's source of truth for "is this user an executive?" lives
 * in the `user_roles` table. The `members` table has no role column. This
 * service is the single place that asks "does this user hold role X?" and
 * "is this member an executive of the welfare scheme?".
 */
class MemberRoleResolver
{
    /** Roles that grant executive privileges for executive-minute visibility. */
    public const EXECUTIVE_ROLES = [
        'admin',
        'super_admin',
        'treasurer',
        'chairperson',
        'vice_chairperson',
        'secretary',
        'vice_secretary',
        'patron',
    ];

    /** Roles that may sign documents as officers. */
    public const SIGNATORY_ROLES = [
        'admin' => 'admin',
        'treasurer' => 'treasurer',
        'secretary' => 'secretary',
        'chairperson' => 'chairperson',
        'vice_chairperson' => 'vice_chairperson',
        'vice_secretary' => 'vice_secretary',
        'patron' => 'patron',
    ];

    /**
     * @return list<string> Active roles for the given user.
     */
    public function rolesFor(string $userId): array
    {
        return DB::table('user_roles')
            ->where('user_id', $userId)
            ->where('is_active', 1)
            ->pluck('role')
            ->map(fn ($r) => (string) $r)
            ->all();
    }

    public function hasRole(string $userId, string $role): bool
    {
        return DB::table('user_roles')
            ->where('user_id', $userId)
            ->where('role', $role)
            ->where('is_active', 1)
            ->exists();
    }

    public function isExecutive(string $userId): bool
    {
        return DB::table('user_roles')
            ->where('user_id', $userId)
            ->where('is_active', 1)
            ->whereIn('role', self::EXECUTIVE_ROLES)
            ->exists();
    }

    /**
     * Returns the first active user_id that holds the given role. Used to
     * resolve the current Chairperson / Secretary for minute auto-fill.
     */
    public function userIdForRole(string $role): ?string
    {
        $row = DB::table('user_roles')
            ->where('role', $role)
            ->where('is_active', 1)
            ->first(['user_id']);
        return $row?->user_id ? (string) $row->user_id : null;
    }

    public function memberIdForUser(string $userId): ?string
    {
        $row = DB::table('members')
            ->where('user_id', $userId)
            ->first(['id', 'name']);
        return $row?->id ? (string) $row->id : null;
    }

    public function memberNameForUser(string $userId): ?string
    {
        $row = DB::table('members')
            ->where('user_id', $userId)
            ->first(['name']);
        return $row?->name ? (string) $row->name : null;
    }

    /**
     * Returns a user's signature for the given role. When a user ID is
     * supplied, never fall back to another or former role holder's signature.
     */
    public function signatureUrlFor(string $signatoryRole, ?string $userId = null): ?string
    {
        if ($userId) {
            $row = DB::table('signatory_signatures')
                ->where('signatory_role', $signatoryRole)
                ->where('user_id', $userId)
                ->orderByDesc('updated_at')
                ->first(['signature_url']);
            if ($row?->signature_url) {
                return (string) $row->signature_url;
            }

            return null;
        }
        $row = DB::table('signatory_signatures')
            ->where('signatory_role', $signatoryRole)
            ->orderByDesc('updated_at')
            ->first(['signature_url']);
        return $row?->signature_url ? (string) $row->signature_url : null;
    }
}
