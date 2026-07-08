<?php

use App\Enums\PackageType;
use App\Models\Package;
use App\Models\PackageVersion;

it('stores npm dist-tags on a package and npm dist fields on a version', function () {
    $pkg = Package::factory()->create(['type' => PackageType::Npm, 'name' => '@noixdev/ui-kit']);
    $pkg->update(['dist_tags' => ['latest' => '1.2.0']]);

    $v = PackageVersion::factory()->for($pkg)->create([
        'version' => '1.2.0',
        'version_pretty' => '1.2.0',
        'source_reference' => null,
        'dist_shasum' => str_repeat('a', 40),
        'dist_integrity' => 'sha512-'.base64_encode(str_repeat('x', 64)),
        'dist_tarball_name' => 'ui-kit-1.2.0.tgz',
    ]);

    expect($pkg->fresh()->dist_tags)->toBe(['latest' => '1.2.0'])
        ->and($v->source_reference)->toBeNull()
        ->and($v->dist_shasum)->toHaveLength(40);
});
