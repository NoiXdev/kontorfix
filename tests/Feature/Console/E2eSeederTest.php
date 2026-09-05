<?php

use App\Enums\PackageSourceMode;
use App\Enums\PackageType;
use App\Jobs\SyncPackage;
use App\Models\Organization;
use Database\Seeders\E2eSeeder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Queue;

// The seeder now unconditionally dispatches SyncPackage for the Composer row (see
// E2eSeeder), and phpunit.xml sets QUEUE_CONNECTION=sync — so every test in this file that
// runs the seeder would otherwise execute that job inline. GitRepository::sync() rejects
// `git://` under the default (non-E2E) allowed schemes and — deliberately, so the real
// queue retries transient failures — SyncPackage::handle() rethrows after recording the
// failure, which would bubble straight out of Artisan::call() and fail the test for a
// reason that has nothing to do with what it is checking. Faking the queue for every test
// here keeps the dispatch a plain, inert fact instead of a job that actually runs.
beforeEach(fn () => Queue::fake());

it('creates the fixture world and prints a parsable context line', function () {
    Artisan::call('db:seed', ['--class' => E2eSeeder::class, '--force' => true]);
    $output = Artisan::output();

    expect($output)->toContain('E2E_CONTEXT=');

    preg_match('/^E2E_CONTEXT=(.*)$/m', $output, $matches);
    $context = json_decode(trim($matches[1]), true);

    expect($context)->toBeArray()
        ->and(array_keys($context))->toEqualCanonicalizing([
            'base_url', 'host_base_url', 'read_token', 'publish_token',
            'composer_package', 'npm_package', 'python_package', 'python_module', 'version',
        ])
        ->and($context['base_url'])->toBe('http://app:8080/r/e2e-customer/e2e-registry')
        ->and($context['host_base_url'])->toBe('http://127.0.0.1:8099/r/e2e-customer/e2e-registry')
        ->and($context['read_token'])->toStartWith('kfx_')
        ->and($context['publish_token'])->toStartWith('kfx_')
        ->and($context['read_token'])->not->toBe($context['publish_token'])
        ->and($context['composer_package'])->toBe('kontorfix-e2e/demo')
        ->and($context['npm_package'])->toBe('kontorfix-e2e-demo')
        ->and($context['python_package'])->toBe('kontorfix-e2e-demo')
        ->and($context['python_module'])->toBe('kontorfix_e2e_demo')
        ->and($context['version'])->toBe('1.0.0');

    $customer = Organization::where('slug', 'e2e-customer')->firstOrFail();

    expect($customer->enabled_registry_types)->toEqualCanonicalizing(['composer', 'npm', 'python'])
        ->and($customer->groups()->where('slug', 'e2e-registry')->exists())->toBeTrue()
        ->and(Organization::where('slug', 'e2e-operator')->value('is_operator'))->toBeTrue();
});

it('seeds the composer package as git-sourced and queues its sync', function () {
    Artisan::call('db:seed', ['--class' => E2eSeeder::class, '--force' => true]);

    $package = Organization::where('slug', 'e2e-customer')->firstOrFail()
        ->packages()->where('type', PackageType::Composer)->where('name', 'kontorfix-e2e/demo')->firstOrFail();

    // Not "the package has zero versions": under a faked queue that is true no matter what
    // the seeder does, since nothing ever runs the job — it would pass even with the
    // `source_mode` bug this test exists to catch. The two assertions below are the ones
    // that can actually fail: `source_mode` has to be `git` (isGitSourced() reads exactly
    // this column, and its default is `publish` — see the seeder's own comment on this),
    // and SyncPackage has to have been queued for THIS package, not merely pushed at all.
    // Both were true before this test existed and both are what the E2E worker actually
    // depends on to pick the package up.
    expect($package->repository_url)->toBe('git://gitserver/demo.git')
        ->and($package->source_mode)->toBe(PackageSourceMode::Git)
        ->and($package->groups()->where('groups.slug', 'e2e-registry')->exists())->toBeTrue();

    Queue::assertPushed(
        SyncPackage::class,
        fn (SyncPackage $job): bool => $job->package->is($package),
    );
});

it('seeds an npm package the publish tests can target, with no repository to sync from', function () {
    Artisan::call('db:seed', ['--class' => E2eSeeder::class, '--force' => true]);

    $package = Organization::where('slug', 'e2e-customer')->firstOrFail()
        ->packages()->where('type', PackageType::Npm)->where('name', 'kontorfix-e2e-demo')->firstOrFail();

    // Publish-based: nothing to sync, so a repository_url here would mean the app tries to
    // queue a sync that a publish-based package cannot satisfy.
    expect($package->repository_url)->toBeNull()
        ->and($package->groups()->where('groups.slug', 'e2e-registry')->exists())->toBeTrue();
});

it('seeds a python package the twine tests can target, with no repository to sync from', function () {
    Artisan::call('db:seed', ['--class' => E2eSeeder::class, '--force' => true]);

    $package = Organization::where('slug', 'e2e-customer')->firstOrFail()
        ->packages()->where('type', PackageType::Python)->where('name', 'kontorfix-e2e-demo')->firstOrFail();

    expect($package->repository_url)->toBeNull()
        ->and($package->groups()->where('groups.slug', 'e2e-registry')->exists())->toBeTrue();
});

it('refuses to run in production', function () {
    app()['env'] = 'production';

    expect(fn () => Artisan::call('db:seed', ['--class' => E2eSeeder::class, '--force' => true]))
        ->toThrow(RuntimeException::class, 'E2eSeeder refuses to run in production');

    expect(Organization::where('slug', 'e2e-customer')->exists())->toBeFalse();
});
