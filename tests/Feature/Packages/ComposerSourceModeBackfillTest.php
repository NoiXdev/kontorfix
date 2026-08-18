<?php

use App\Models\Package;

function runComposerSourceModeBackfill(): void
{
    $path = database_path('migrations/2026_08_10_090000_backfill_composer_source_mode.php');
    (require $path)->up();
}

// Package::isGitSourced() reads the column and nothing else, so a Composer row left on the
// 'publish' DB default — what Api\V1\PackageController::store() produced before this branch
// — would stop being treated as a git mirror. The backfill is what makes dropping the
// per-type fallback safe.
it('backfills a composer row left on the publish default', function () {
    $package = Package::factory()->create(['type' => 'composer', 'source_mode' => 'publish']);

    runComposerSourceModeBackfill();

    expect($package->fresh()->source_mode->value)->toBe('git')
        ->and($package->fresh()->isGitSourced())->toBeTrue();
});

// The type predicate is load-bearing: npm is publish-only, so a backfill that forgot it
// would recreate exactly the broken mirrors the sibling migration just removed.
it('leaves an npm publish row alone', function () {
    $package = Package::factory()->create(['type' => 'npm', 'source_mode' => 'publish']);

    runComposerSourceModeBackfill();

    expect($package->fresh()->source_mode->value)->toBe('publish');
});
