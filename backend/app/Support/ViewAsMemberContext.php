<?php

namespace App\Support;

use Illuminate\Http\Request;

/**
 * Server-side "View as Member" request context.
 *
 * Centralizes how controllers/services read the view-as-member state from a
 * request without scattering header parsing or attribute access throughout the
 * codebase.
 *
 * Usage:
 *   $ctx = ViewAsMemberContext::from($request);
 *   if ($ctx->isActive()) { ... }
 *   $memberId = $ctx->viewedMemberId();
 */
class ViewAsMemberContext
{
    public function __construct(
        private bool $active,
        private ?string $viewedMemberId,
        private ?array $viewedMember,
        private ?string $actingAdminUserId,
    ) {
    }

    public static function from(Request $request): self
    {
        $active = (bool) $request->attributes->get('is_view_as_member', false);

        return new self(
            active: $active,
            viewedMemberId: $request->attributes->get('viewing_member_id'),
            viewedMember: $request->attributes->get('viewed_member'),
            actingAdminUserId: $request->attributes->get('acting_admin_user_id'),
        );
    }

    public function isActive(): bool
    {
        return $this->active;
    }

    public function viewedMemberId(): ?string
    {
        return $this->viewedMemberId;
    }

    public function viewedMember(): ?array
    {
        return $this->viewedMember;
    }

    public function actingAdminUserId(): ?string
    {
        return $this->actingAdminUserId;
    }

    /**
     * Resolve the authoritative member_id for read operations.
     *
     * When view-as is active the viewed member is authoritative. Otherwise
     * fall back to the caller-supplied value (query/body param) or the
     * authenticated identity's own member_id.
     */
    public function resolveMemberId(?string $fallback = null): ?string
    {
        if ($this->active && $this->viewedMemberId !== null) {
            return $this->viewedMemberId;
        }

        return $fallback;
    }
}