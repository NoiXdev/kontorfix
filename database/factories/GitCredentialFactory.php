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
            'username' => null,
            'token' => 'ghp_'.fake()->regexify('[A-Za-z0-9]{20}'),
        ];
    }
}
