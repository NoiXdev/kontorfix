<?php

namespace Database\Factories;

use App\Enums\UserRole;
use App\Models\OidcProvider;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<OidcProvider>
 */
class OidcProviderFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => $name = fake()->company(),
            'slug' => Str::slug($name).'-'.fake()->unique()->numberBetween(1, 99999),
            'client_id' => Str::random(24),
            'client_secret' => Str::random(40),
            'issuer' => 'https://idp.test',
            'authorization_endpoint' => 'https://idp.test/authorize',
            'token_endpoint' => 'https://idp.test/token',
            'userinfo_endpoint' => 'https://idp.test/userinfo',
            'jwks_uri' => 'https://idp.test/jwks',
            'scopes' => 'openid email profile',
            'enabled' => false,
            'allow_registration' => false,
            // Mirrors the migration's backfill: a factory-made provider models an already
            // established, working configuration (like every row that existed before the
            // `trusts_email_claim` column shipped), not the untrusted state a brand-new row
            // gets from the column default. Tests that specifically exercise the untrusted
            // path override this explicitly.
            'trusts_email_claim' => true,
            'default_organization_id' => null,
            'default_role' => UserRole::Member,
        ];
    }
}
