<?php

namespace Database\Factories;

use App\Models\Group;
use App\Models\Organization;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Group>
 */
class GroupFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'organization_id' => Organization::factory(),
            'name' => $n = fake()->company(),
            'slug' => Str::slug($n).'-'.fake()->unique()->numberBetween(1, 9999),
            'public' => false,
            // No `legacy_slug`: the application never sets it, so a plain factory() models
            // a registry created *after* the organization-scoped-slug upgrade — one with no
            // pre-upgrade address at all. See preUpgrade() for the other case.
        ];
    }

    /**
     * A registry that already existed when the instance was upgraded to organization-scoped
     * slugs: 2026_09_03_100000 froze its one-segment address into `legacy_slug`, so old
     * /r/{slug}/… URLs still 301 to its canonical address.
     *
     * afterMaking rather than a state closure, deliberately: the frozen address has to be
     * the slug the *caller* passed to create(), and Factory::create() applies its own
     * attributes as the last state — after every state added here — so a closure would only
     * ever see the factory's generated default.
     */
    public function preUpgrade(): static
    {
        return $this->afterMaking(function (Group $group): void {
            $group->legacy_slug = $group->slug;
        });
    }
}
