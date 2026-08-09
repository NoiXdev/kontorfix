<?php

namespace App\Services\Auth\Oidc;

use App\Enums\UserRole;
use App\Models\OidcProvider;
use App\Models\User;
use Illuminate\Support\Str;
use RuntimeException;

class OidcUserResolver
{
    /** @param array<string,mixed> $claims */
    public function resolve(OidcProvider $provider, array $claims): User
    {
        $subject = (string) ($claims['sub'] ?? '');
        if ($subject === '') {
            throw new RuntimeException('id_token ohne sub.');
        }

        $identity = $provider->identities()->where('subject', $subject)->first();
        if ($identity !== null) {
            $identity->update(['last_login_at' => now()]);

            return $identity->user;
        }

        // Normalised, and matched case-insensitively below. `users.email` is a plain
        // case-sensitive btree with no citext and no lowercasing mutator, so an IdP
        // asserting `Root@firma.de` used to miss the existing `root@firma.de` entirely:
        // it skipped the isPrivileged() guard, fell through to the registration branch and
        // created a second, lookalike account carrying the provider's default_role. Not a
        // takeover of the original — no identity link, a different id — but an
        // identity-confusion primitive in every user and activity listing.
        $email = Str::lower(trim((string) ($claims['email'] ?? '')));
        $emailVerified = ($claims['email_verified'] ?? false) === true;

        if ($email !== '' && $emailVerified) {
            // Ordered: with the unique functional index in place at most one row can match,
            // but an instance whose migration had to fall back to a non-unique index (see
            // EmailUniquenessIndex) can still hold a case-variant pair, and an unordered
            // `first()` would then link whichever row the planner happened to return. Oldest
            // wins, deterministically — the account that held the address first.
            $user = User::whereRaw('lower(email) = ?', [$email])->orderBy('created_at')->orderBy('id')->first();
            if ($user !== null) {
                // Do NOT automatically link a privileged account to a federated identity by
                // email: an IdP that sets email_verified freely could otherwise take over that
                // account. Privilege is read from every source (super-admin flag, home-org role,
                // per-organization membership role) — the home-org role column alone would miss
                // a super-admin with role=member and an account that is admin elsewhere. Such
                // accounts must be linked deliberately (while logged in) — password/2FA/passkey
                // remain their login path.
                if ($user->isPrivileged()) {
                    throw new RuntimeException('Automatische SSO-Verknüpfung für privilegierte Konten ist nicht erlaubt.');
                }

                $this->link($provider, $user, $subject);

                return $user;
            }
        }

        if ($provider->allow_registration) {
            if ($provider->default_organization_id === null) {
                throw new RuntimeException('Provider erlaubt Registrierung, hat aber keine Default-Organisation.');
            }
            if ($email === '' || ! $emailVerified) {
                throw new RuntimeException('Registrierung erfordert eine verifizierte E-Mail.');
            }

            $user = User::create([
                'name' => (string) ($claims['name'] ?? $email),
                'email' => $email,
                'password' => bcrypt(Str::random(40)),
                'organization_id' => $provider->default_organization_id,
                'role' => $provider->default_role ?? UserRole::Member,
            ]);
            // email_verified_at is not fillable → set it separately.
            $user->forceFill(['email_verified_at' => now()])->save();

            $this->link($provider, $user, $subject);

            return $user;
        }

        throw new RuntimeException('Kein Konto für diese Identität — Registrierung ist deaktiviert.');
    }

    private function link(OidcProvider $provider, User $user, string $subject): void
    {
        $provider->identities()->create(['user_id' => $user->id, 'subject' => $subject, 'last_login_at' => now()]);
    }
}
