<?php

use App\Support\Licence\Pep440Version;

/**
 * Asserts `$lower` sorts strictly before `$higher` — in BOTH directions, so a sign error in
 * `compareTo()` cannot slip through by only ever checking one side.
 */
function expectOrdered(string $lower, string $higher): void
{
    $a = Pep440Version::parse($lower);
    $b = Pep440Version::parse($higher);

    expect($a)->not->toBeNull("failed to parse '{$lower}'");
    expect($b)->not->toBeNull("failed to parse '{$higher}'");

    expect($a->compareTo($b))->toBeLessThan(0, "expected '{$lower}' < '{$higher}'");
    expect($b->compareTo($a))->toBeGreaterThan(0, "expected '{$higher}' > '{$lower}'");
}

/** Asserts two version strings parse to versions that compare equal in both directions. */
function expectEqual(string $left, string $right): void
{
    $a = Pep440Version::parse($left);
    $b = Pep440Version::parse($right);

    expect($a)->not->toBeNull("failed to parse '{$left}'");
    expect($b)->not->toBeNull("failed to parse '{$right}'");

    expect($a->compareTo($b))->toBe(0, "expected '{$left}' == '{$right}'");
    expect($b->compareTo($a))->toBe(0, "expected '{$right}' == '{$left}'");
}

it('orders release segments numerically, not lexically', function () {
    expectOrdered('1.0', '2.0');
    expectOrdered('1.9', '1.10');
});

it('treats a longer release with trailing zeros as equal, and without as greater', function () {
    expectOrdered('1.0', '1.0.1');
    expectEqual('1.0', '1.0.0');
});

it('sorts pre-releases before the final release, ranked a < b < rc', function () {
    expectOrdered('1.0a1', '1.0b1');
    expectOrdered('1.0b1', '1.0rc1');
    expectOrdered('1.0rc1', '1.0');
});

it('sorts post-releases after the final release', function () {
    expectOrdered('1.0', '1.0.post1');
});

it('sorts dev releases before everything of the same release, including pre-releases', function () {
    expectOrdered('1.0.dev1', '1.0a1');
    expectOrdered('1.0a1', '1.0');
});

it('lets the epoch dominate every other segment', function () {
    expectOrdered('2.0', '1!1.0');
});

it('normalizes case so 1.0.RC1 parses equal to 1.0rc1', function () {
    expectEqual('1.0.RC1', '1.0rc1');
});

it('normalizes a leading v so v1.0 parses equal to 1.0', function () {
    expectEqual('v1.0', '1.0');
});

it('treats a bare pre/post/dev marker with no digits as digit 0', function () {
    expectEqual('1.0.dev', '1.0.dev0');
    expectEqual('1.0rc', '1.0rc0');
    expectEqual('1.0.post', '1.0.post0');
    expectOrdered('1.0.dev', '1.0a1');
});

it('returns null, not a guess, for a string that is not a PEP 440 version', function () {
    expect(Pep440Version::parse('nicht-eine-version'))->toBeNull();
});

it('returns null for an empty string', function () {
    expect(Pep440Version::parse(''))->toBeNull();
});
