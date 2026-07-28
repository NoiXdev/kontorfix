<?php

namespace Database\Factories;

use App\Enums\ApiKeyPermission;
use App\Models\ApiKey;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<ApiKey>
 */
class ApiKeyFactory extends Factory
{
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'name' => fake()->words(2, true),
            'key_hash' => hash('sha256', 'kfxapi_'.Str::random(40)),
            'permission' => ApiKeyPermission::Read,
        ];
    }

    public function write(): static
    {
        return $this->state(fn () => ['permission' => ApiKeyPermission::Write]);
    }
}
