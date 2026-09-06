<?php

namespace Database\Factories;

use App\Models\OciManifest;
use App\Models\OciTag;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<OciTag>
 */
class OciTagFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * A tag's `package_id` is derived from its manifest, not minted independently: a tag
     * pointing at a manifest in a different repository would be a nonsensical row, and
     * nothing but this factory's own bookkeeping would keep the two in sync otherwise. The
     * manifest is created eagerly, here, rather than left as a lazy nested factory, so both
     * columns can be read off the one row that owns them.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $manifest = OciManifest::factory()->create();

        return [
            'package_id' => $manifest->package_id,
            'name' => fake()->word(),
            'manifest_id' => $manifest->id,
        ];
    }
}
