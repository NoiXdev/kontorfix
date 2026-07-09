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

        $email = (string) ($claims['email'] ?? '');
        $emailVerified = ($claims['email_verified'] ?? false) === true;

        if ($email !== '' && $emailVerified) {
            $user = User::where('email', $email)->first();
            if ($user !== null) {
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
            // email_verified_at ist nicht fillable → separat setzen.
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
