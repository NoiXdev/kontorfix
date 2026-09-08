<?php

use App\Models\OciManifest;
use App\Models\OciTag;
use App\Models\Package;
use App\Models\RetentionPolicy;
use App\Models\SystemSetting;
use App\Services\Oci\Retention\RetentionRunner;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Spatie\Activitylog\Models\Activity;

/**
 * A Docker repository with one tag per entry, each pointing at its own manifest —
 * name => pushed_at. Own manifests, because ManifestStore's shape is one manifest per
 * pushed image; a fixture with N tags on one manifest would be the multi-tag build case,
 * which retention treats identically but which would hide a wrongly package-scoped query.
 *
 * @param  array<string, string>  $tags
 */
function retentionTaggedPackage(array $tags): Package
{
    $package = Package::factory()->docker()->create();

    foreach ($tags as $name => $pushedAt) {
        OciTag::factory()->create([
            'package_id' => $package->id,
            'name' => $name,
            'manifest_id' => OciManifest::factory()->for($package)->create()->id,
            'pushed_at' => $pushedAt,
        ]);
    }

    return $package;
}

it('touches nothing when no policy resolves', function () {
    $package = retentionTaggedPackage(['alt' => '2020-01-01 00:00:00']);

    expect(app(RetentionRunner::class)->apply($package))->toBeNull()
        ->and($package->ociTags()->count())->toBe(1)
        ->and(Activity::where('log_name', 'retention')->count())->toBe(0);
});

it('reports without removing on a dry run', function () {
    $policy = RetentionPolicy::factory()->create(['rules' => [['type' => 'keep_last', 'count' => 1]]]);
    $package = retentionTaggedPackage(['neu' => '2026-09-07 00:00:00', 'alt' => '2020-01-01 00:00:00']);
    $package->update(['retention_policy_id' => $policy->id]);

    $report = app(RetentionRunner::class)->dryRun($package);

    expect($report?->removedTagNames())->toBe(['alt'])
        // 2, not 1: a dry run that removed a row would still pass every assertion on the
        // report alone.
        ->and($package->ociTags()->count())->toBe(2)
        ->and(Activity::where('log_name', 'retention')->count())->toBe(0);
});

it('removes exactly the reported tags and nothing else on a real run', function () {
    $policy = RetentionPolicy::factory()->create(['rules' => [['type' => 'keep_last', 'count' => 1]]]);
    $package = retentionTaggedPackage(['neu' => '2026-09-07 00:00:00', 'alt' => '2020-01-01 00:00:00']);
    $package->update(['retention_policy_id' => $policy->id]);

    $manifestsBefore = $package->ociManifests()->count();

    $report = app(RetentionRunner::class)->apply($package);

    expect($package->ociTags()->pluck('name')->all())->toBe(['neu'])
        // The whole separation from the sweeper, asserted: the policy layer's write
        // surface is `delete from oci_tags`. If this drops below $manifestsBefore,
        // retention has started reaching into storage.
        ->and($package->ociManifests()->count())->toBe($manifestsBefore)
        ->and($report?->removedTagNames())->toBe(['alt']);
});

it('leaves another package alone', function () {
    $policy = RetentionPolicy::factory()->create(['rules' => [['type' => 'keep_last', 'count' => 1]]]);
    $mine = retentionTaggedPackage(['neu' => '2026-09-07 00:00:00', 'alt' => '2020-01-01 00:00:00']);
    $mine->update(['retention_policy_id' => $policy->id]);
    $theirs = retentionTaggedPackage(['auch-alt' => '2020-01-01 00:00:00']);

    app(RetentionRunner::class)->apply($mine);

    expect($theirs->ociTags()->count())->toBe(1);
});

it('writes which package, which policy and which tags to the activity log', function () {
    $policy = RetentionPolicy::factory()->create(['name' => 'Standard', 'rules' => [['type' => 'keep_last', 'count' => 1]]]);
    $package = retentionTaggedPackage(['neu' => '2026-09-07 00:00:00', 'alt' => '2020-01-01 00:00:00']);
    $package->update(['retention_policy_id' => $policy->id]);

    app(RetentionRunner::class)->apply($package);

    $entry = Activity::where('log_name', 'retention')->sole();

    expect($entry->event)->toBe('retention_applied')
        ->and($entry->subject_id)->toBe($package->id)
        ->and($entry->properties['policy'])->toBe('Standard')
        // The names, not only the count: the description is what a reader skims, the
        // properties are what a reader who needs to know WHICH tag vanished opens.
        ->and($entry->properties['tags'])->toBe(['alt'])
        ->and($entry->description)->toContain('Standard');
});

it('logs nothing when a run removes nothing', function () {
    $policy = RetentionPolicy::factory()->create(['rules' => [['type' => 'keep_last', 'count' => 10]]]);
    $package = retentionTaggedPackage(['neu' => '2026-09-07 00:00:00']);
    $package->update(['retention_policy_id' => $policy->id]);

    app(RetentionRunner::class)->apply($package);

    // An audit trail of no-ops buries the entries that matter under a daily run per package.
    expect(Activity::where('log_name', 'retention')->count())->toBe(0);
});

it('previews unsaved rules without saving them', function () {
    $package = retentionTaggedPackage(['neu' => '2026-09-07 00:00:00', 'alt' => '2020-01-01 00:00:00']);

    $decisions = app(RetentionRunner::class)->previewWithRules($package, [['type' => 'keep_last', 'count' => 1]]);

    expect(collect($decisions)->where('keep', false)->pluck('tag.name')->all())->toBe(['alt'])
        ->and($package->ociTags()->count())->toBe(2)
        ->and(RetentionPolicy::count())->toBe(0);
});

it('counts the packages the instance default governs, not only the ones naming it', function () {
    $default = RetentionPolicy::factory()->create();
    $own = RetentionPolicy::factory()->create();
    SystemSetting::current()->update(['retention_policy_id' => $default->id]);

    $inheriting = Package::factory()->docker()->create();
    $naming = Package::factory()->docker()->create(['retention_policy_id' => $default->id]);
    $opted = Package::factory()->docker()->create(['retention_policy_id' => $own->id]);
    Package::factory()->create(); // composer — never governed

    $governed = app(RetentionRunner::class)->packagesFor($default)->pluck('id');

    expect($governed)->toHaveCount(2)
        ->and($governed)->toContain($inheriting->id)
        ->and($governed)->toContain($naming->id)
        ->and($governed)->not->toContain($opted->id);

    // A policy that is NOT the default reaches only the packages naming it.
    expect(app(RetentionRunner::class)->packagesFor($own)->pluck('id')->all())->toBe([$opted->id]);
});

it('applies through the command, and only reports on --dry-run', function () {
    $policy = RetentionPolicy::factory()->create(['name' => 'Standard', 'rules' => [['type' => 'keep_last', 'count' => 1]]]);
    $package = retentionTaggedPackage(['neu' => '2026-09-07 00:00:00', 'alt' => '2020-01-01 00:00:00']);
    $package->update(['retention_policy_id' => $policy->id]);

    $this->artisan('oci:retention --dry-run')
        ->expectsOutputToContain('würden entfernt')
        // Said on every run: an operator who sees tags disappear and used storage
        // unchanged would otherwise report the sweeper as broken.
        ->expectsOutputToContain('Schonfrist')
        ->assertSuccessful();

    expect($package->ociTags()->count())->toBe(2);

    $this->artisan('oci:retention')->assertSuccessful();

    expect($package->ociTags()->pluck('name')->all())->toBe(['neu']);
});

it('scopes the command to one repository with --package', function () {
    $policy = RetentionPolicy::factory()->create(['rules' => [['type' => 'keep_last', 'count' => 1]]]);
    $mine = retentionTaggedPackage(['neu' => '2026-09-07 00:00:00', 'alt' => '2020-01-01 00:00:00']);
    $other = retentionTaggedPackage(['neu2' => '2026-09-07 00:00:00', 'alt2' => '2020-01-01 00:00:00']);
    $mine->update(['retention_policy_id' => $policy->id]);
    $other->update(['retention_policy_id' => $policy->id]);

    $this->artisan("oci:retention --package={$mine->id}")->assertSuccessful();

    expect($mine->ociTags()->count())->toBe(1)
        ->and($other->ociTags()->count())->toBe(2);
});

it('spares a tag re-pushed between evaluation and deletion', function () {
    // The race a real `docker push` can produce: apply() evaluates, and before its DELETE
    // lands, a push re-points one of the doomed tags. Deleting by name alone would remove
    // the tag the client just pushed — worse than a failed push, because the client
    // believes it succeeded. Same query-listener technique as AutoCreateRepositoryTest's
    // plant: one thread, and the competitor fires from the last read before the write.
    $this->travelTo('2026-09-08 12:00:00');

    $policy = RetentionPolicy::factory()->create(['rules' => [['type' => 'keep_matching', 'pattern' => 'neu']]]);
    $package = retentionTaggedPackage(['neu' => '2026-09-07 00:00:00', 'alt' => '2020-01-01 00:00:00']);
    $package->update(['retention_policy_id' => $policy->id]);

    $raced = false;

    DB::listen(function (QueryExecuted $query) use (&$raced, $package): void {
        // The tag SELECT dryRun() runs — the last read before apply()'s delete.
        $isTheGap = str_starts_with($query->sql, 'select') && str_contains($query->sql, 'oci_tags');

        if ($raced || ! $isTheGap) {
            return;
        }

        // Set BEFORE the write, so the plant's own UPDATE cannot re-enter.
        $raced = true;

        // The re-push: ManifestStore::put() would stamp pushed_at = now(); a strictly later
        // instant stands in for "after the evaluation read".
        DB::table('oci_tags')
            ->where('package_id', $package->id)
            ->where('name', 'alt')
            ->update(['pushed_at' => now()->addSecond()]);
    });

    app(RetentionRunner::class)->apply($package);

    expect($raced)->toBeTrue()
        // The re-pushed tag survived; without the pushed_at guard on the delete it is gone.
        ->and($package->ociTags()->pluck('name')->sort()->values()->all())->toBe(['alt', 'neu']);
});

it('skips a package with corrupt inline rules, reports it, exits non-zero, and touches nothing else', function () {
    $policy = RetentionPolicy::factory()->create(['rules' => [['type' => 'keep_last', 'count' => 1]]]);
    $healthy = retentionTaggedPackage(['neu' => '2026-09-07 00:00:00', 'alt' => '2020-01-01 00:00:00']);
    $healthy->update(['retention_policy_id' => $policy->id]);

    // Bypasses RetentionRuleSetValidator the same way a hand-edited jsonb value or a
    // pre-validation row would: the model itself only casts the column, it does not
    // validate it — see RetentionRule::fromArray()'s own docblock on why validation lives
    // there instead.
    $corrupt = retentionTaggedPackage(['bleibt' => '2020-01-01 00:00:00']);
    $corrupt->update(['retention_rules' => [['type' => 'not_a_real_rule_type']]]);

    $this->artisan('oci:retention')
        // Named, not just counted: an operator has to know WHICH repository to fix.
        ->expectsOutputToContain($corrupt->name)
        ->assertFailed();

    // The healthy package ran to completion — one corrupt package must not abort the batch.
    expect($healthy->ociTags()->pluck('name')->all())->toBe(['neu'])
        // The corrupt package: dryRun()/apply() never ran for it, so nothing was deleted.
        ->and($corrupt->ociTags()->count())->toBe(1);
});

it('excludes inline-ruled packages from a policy\'s governed set', function () {
    $default = RetentionPolicy::factory()->create();
    SystemSetting::current()->update(['retention_policy_id' => $default->id]);

    $governedByDefault = Package::factory()->docker()->create();
    // Inline rules are tier 0: this package is governed by THEM, and the policy's dry run
    // must not list a package whose report would be computed from someone else's rules.
    $inlineRuled = Package::factory()->docker()->create([
        'retention_rules' => [['type' => 'keep_last', 'count' => 1]],
    ]);

    $governed = app(RetentionRunner::class)->packagesFor($default)->pluck('id');

    expect($governed)->toContain($governedByDefault->id)
        ->and($governed)->not->toContain($inlineRuled->id);
});
