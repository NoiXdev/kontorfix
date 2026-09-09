<?php

namespace Database\Factories;

use App\Models\RetentionPolicy;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<RetentionPolicy>
 */
class RetentionPolicyFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * The default rule set carries exactly one KEEP-rule, not a shield: a policy of shields
     * alone removes nothing by design, so a shield-only default would make every test that
     * expects a deletion pass or fail for a reason that has nothing to do with what it is
     * testing.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => ucfirst(fake()->unique()->words(2, true)),
            'rules' => [['type' => 'keep_last', 'count' => 10]],
        ];
    }
}
