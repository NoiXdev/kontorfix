<?php

namespace Database\Factories;

use App\Models\OidcIdentity;
use App\Models\OidcProvider;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<OidcIdentity>
 */
class OidcIdentityFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'oidc_provider_id' => OidcProvider::factory(),
            'user_id' => User::factory(),
            'subject' => fake()->uuid(),
            'last_login_at' => null,
        ];
    }
}
