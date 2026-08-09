<?php

use App\Models\PackageVersion;
use App\Support\VersionOrder;
use Illuminate\Support\Collection;

function v(string $version, ?string $released = null): PackageVersion
{
    return new PackageVersion([
        'version' => $version,
        'version_pretty' => $version,
        'released_at' => $released,
    ]);
}

it('sorts semantically, not lexically', function () {
    $sorted = VersionOrder::sort(new Collection([v('1.9.0'), v('1.10.0'), v('1.2.0')]));

    expect($sorted->pluck('version')->all())->toBe(['1.10.0', '1.9.0', '1.2.0']);
});

it('sorts a pre-release below its release', function () {
    $sorted = VersionOrder::sort(new Collection([v('2.0.0-beta.1'), v('2.0.0')]));

    expect($sorted->pluck('version')->all())->toBe(['2.0.0', '2.0.0-beta.1']);
});

it('puts versions the comparator cannot parse last, without throwing', function () {
    $sorted = VersionOrder::sort(new Collection([v('nightly'), v('1.0.0'), v('trunk')]));

    expect($sorted->first()->version)->toBe('1.0.0')
        ->and($sorted->pluck('version')->slice(1)->sort()->values()->all())->toBe(['nightly', 'trunk']);
});

it('orders unparseable versions among themselves by release date, newest first', function () {
    $sorted = VersionOrder::sort(new Collection([
        v('trunk', '2024-01-01 00:00:00'),
        v('nightly', '2025-01-01 00:00:00'),
    ]));

    expect($sorted->pluck('version')->all())->toBe(['nightly', 'trunk']);
});

it('gives a defined order when every released_at is null', function () {
    $sorted = VersionOrder::sort(new Collection([v('1.0.0'), v('3.0.0'), v('2.0.0')]));

    expect($sorted->pluck('version')->all())->toBe(['3.0.0', '2.0.0', '1.0.0']);
});

it('prefers version_pretty when it is set', function () {
    $a = new PackageVersion(['version' => '1.0.0.0', 'version_pretty' => 'v1.0.0']);
    $b = new PackageVersion(['version' => '2.0.0.0', 'version_pretty' => 'v2.0.0']);

    expect(VersionOrder::sort(new Collection([$a, $b]))->first()->version_pretty)->toBe('v2.0.0');
});
