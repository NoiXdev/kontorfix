<?php

use App\Models\User;
use Illuminate\Support\Facades\Broadcast;

/**
 * Private per-user channel (used by broadcast notifications). Compared as strings:
 * every model uses UUIDv7 keys, and the scaffolded `(int)` cast — written for
 * auto-increment ids — stopped at the first non-digit, collapsing every UUID to the
 * same leading number and authorizing any user for any other user's channel.
 */
Broadcast::channel('App.Models.User.{id}', function (User $user, string $id) {
    return hash_equals((string) $user->getKey(), $id);
});

/**
 * Instance-wide operator channel: PackageSynced / PackageSyncFailed are dispatched for
 * every organization on the instance and carry the raw sync error text. Belonging to the
 * operator organization is therefore not a sufficient predicate — an operator-org
 * maintainer or member is scoped to their own organization in every other read path
 * (OrgScope / ScopesToAdministeredOrgs), so admitting them here would leak other tenants'
 * package names and error output. Super-admin is the only role with instance-wide reach.
 */
Broadcast::channel('operator', function (User $user) {
    return $user->isSuperAdmin();
});
