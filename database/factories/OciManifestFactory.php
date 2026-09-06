<?php

namespace Database\Factories;

use App\Enums\PackageType;
use App\Models\OciManifest;
use App\Models\Package;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<OciManifest>
 */
class OciManifestFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $payload = '{"schemaVersion":2,"mediaType":"application/vnd.oci.image.manifest.v1+json"}';

        return [
            'package_id' => Package::factory()->state(['type' => PackageType::Docker]),
            'digest' => 'sha256:'.hash('sha256', Str::random()),
            'media_type' => 'application/vnd.oci.image.manifest.v1+json',
            'payload' => $payload,
            'size' => strlen($payload),
        ];
    }
}
