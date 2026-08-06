<?php

namespace Database\Factories;

use App\Models\Package;
use App\Models\PythonDist;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PythonDist>
 */
class PythonDistFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $version = fake()->numerify('#.#.#');
        $filename = 'demo-'.$version.'.tar.gz';

        return [
            'package_id' => Package::factory(),
            'version' => $version,
            'filename' => $filename,
            'filetype' => 'sdist',
            'path' => 'pypi/'.fake()->uuid().'/'.$filename,
            'sha256' => hash('sha256', $filename),
            'size' => fake()->numberBetween(1000, 500000),
            'requires_python' => '>=3.8',
            'yanked' => false,
            'download_count' => 0,
            'uploaded_at' => now(),
        ];
    }
}
