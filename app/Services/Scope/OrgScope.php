<?php

namespace App\Services\Scope;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Support\Facades\Auth;

/**
 * The admin console's active-organization scope. The sidebar switch selects either a
 * single organization or "all". The selection both filters what is listed and sets the
 * organization new objects are created in. It is always clamped to the organizations
 * the current user may administer, so it can never widen access.
 */
class OrgScope
{
    private const SESSION_KEY = 'admin.scope_org_id';

    /**
     * Organizations the current user may administer, as {id,name}.
     *
     * @return list<array{id: string, name: string}>
     */
    public function organizations(): array
    {
        $user = $this->user();
        if ($user === null) {
            return [];
        }

        return Organization::whereIn('id', $user->administeredOrganizationIds())
            ->orderBy('name')->get(['id', 'name'])
            ->map(fn (Organization $o) => ['id' => $o->id, 'name' => $o->name])
            ->all();
    }

    /**
     * The active organization id, or null for "all". Null is only honoured when the
     * user administers more than one org (or is a super-admin); a single-org admin is
     * always pinned to that org.
     */
    public function activeId(): ?string
    {
        $user = $this->user();
        if ($user === null) {
            return null;
        }

        $administered = $user->administeredOrganizationIds();

        // A non-super-admin who administers exactly one org is always scoped to it.
        if (! $user->isSuperAdmin() && count($administered) === 1) {
            return $administered[0];
        }

        $selected = session(self::SESSION_KEY);

        return is_string($selected) && in_array($selected, $administered, true) ? $selected : null;
    }

    public function set(?string $organizationId): void
    {
        if ($organizationId === null || $organizationId === '') {
            session()->forget(self::SESSION_KEY);

            return;
        }

        // Only allow selecting an org the user actually administers.
        if (in_array($organizationId, $this->user()?->administeredOrganizationIds() ?? [], true)) {
            session([self::SESSION_KEY => $organizationId]);
        }
    }

    /**
     * The organization ids queries should be constrained to: the active org when one is
     * selected, otherwise every organization the user may administer. This is the
     * security boundary the admin controllers filter on.
     *
     * @return list<string>
     */
    public function ids(): array
    {
        $active = $this->activeId();

        return $active !== null ? [$active] : ($this->user()?->administeredOrganizationIds() ?? []);
    }

    /** The organization new objects default to, or null when scoped to "all". */
    public function creationOrganizationId(): ?string
    {
        return $this->activeId();
    }

    /**
     * True only for a super-admin whose active scope is "all". In that state the console
     * spans every organization and even org-less rows (e.g. packages not yet attached to
     * any registry) are in scope — so listings need no organization filter at all.
     */
    public function spansAllOrganizations(): bool
    {
        return $this->activeId() === null && (bool) $this->user()?->isSuperAdmin();
    }

    /** Whether the user can meaningfully switch scope (more than one administered org). */
    public function canSelectAll(): bool
    {
        $user = $this->user();

        return $user !== null && ($user->isSuperAdmin() || count($user->administeredOrganizationIds()) > 1);
    }

    /**
     * @return array<string, mixed>
     */
    public function share(): array
    {
        return [
            'active' => $this->activeId(),
            'organizations' => $this->organizations(),
            'canSelectAll' => $this->canSelectAll(),
        ];
    }

    private function user(): ?User
    {
        $user = Auth::user();

        return $user instanceof User ? $user : null;
    }
}
