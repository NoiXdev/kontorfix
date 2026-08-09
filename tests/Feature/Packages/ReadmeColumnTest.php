<?php

use App\Models\Package;
use Illuminate\Support\Facades\Schema;

it('stores rendered readme html on the package', function () {
    expect(Schema::hasColumn('packages', 'readme_html'))->toBeTrue();

    $package = Package::factory()->create(['readme_html' => '<h1>Hallo</h1>']);

    expect($package->fresh()->readme_html)->toBe('<h1>Hallo</h1>');
});

it('defaults readme_html to null', function () {
    expect(Package::factory()->create()->fresh()->readme_html)->toBeNull();
});
