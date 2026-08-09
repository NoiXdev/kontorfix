<?php

namespace Database\Factories;

use App\Enums\GitProvider;
use App\Models\GitCredential;
use App\Models\Organization;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<GitCredential>
 */
class GitCredentialFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'organization_id' => Organization::factory(),
            'name' => fake()->words(2, true).' token',
            'provider' => GitProvider::GitHub,
            // The console always writes a host — `GitCredentialController::resolveHost()`
            // materialises the provider default when the operator names none — so a factory
            // row without one is a shape the application never produces, and an unbound
            // credential is now genuinely unusable rather than aimed at the public provider.
            // Derived from the provider, so overriding the provider keeps the row coherent;
            // tests that want the unbound state pass `'host' => null` explicitly.
            'host' => fn (array $attributes): ?string => ($attributes['provider'] instanceof GitProvider
                ? $attributes['provider']
                : GitProvider::from((string) $attributes['provider']))->defaultHost(),
            'username' => null,
            'token' => 'ghp_'.fake()->regexify('[A-Za-z0-9]{20}'),
        ];
    }
}
