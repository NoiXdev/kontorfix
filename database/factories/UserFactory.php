<?php

namespace Database\Factories;

use App\Enums\AccountType;
use App\Enums\UserRole;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    /**
     * The current password being used by the factory.
     */
    protected static ?string $password;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'email_verified_at' => now(),
            'password' => static::$password ??= Hash::make('password'),
            'remember_token' => Str::random(10),
            'organization_id' => Organization::factory(),
            'role' => UserRole::Member,
        ];
    }

    /**
     * Indicate that the model's email address should be unverified.
     */
    public function unverified(): static
    {
        return $this->state(fn (array $attributes) => [
            'email_verified_at' => null,
        ]);
    }

    /**
     * Indicate that the user belongs to the operator organization.
     */
    public function operator(): static
    {
        return $this->state(fn () => [
            'organization_id' => Organization::factory()->state(['is_operator' => true]),
        ]);
    }

    /**
     * Indicate that the user is a robot (service) account without a password.
     */
    public function robot(): static
    {
        return $this->state(fn () => [
            'account_type' => AccountType::Robot,
            'password' => null,
        ]);
    }
}
