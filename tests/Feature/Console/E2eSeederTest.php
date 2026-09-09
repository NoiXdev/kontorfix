<?php

use App\Enums\PackageSourceMode;
use App\Enums\PackageType;
use App\Jobs\SyncPackage;
use App\Models\Domain;
use App\Models\Organization;
use App\Models\User;
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
            'composer_package', 'npm_package', 'python_package', 'python_module',
            'docker_repository', 'docker_host', 'docker_path_host', 'docker_path_repository',
            'version',
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
        ->and($context['docker_repository'])->toBe('kontorfix-e2e-demo')
        ->and($context['docker_host'])->toBe('127.0.0.1:8099')
        // The path-namespaced address of the SAME registry. `localhost`, not `127.0.0.1`:
        // the seeded `domains` row carries the literal `127.0.0.1`, so that host resolves in
        // domain mode and would never exercise the path split at all. Pinned here because
        // the two values looking interchangeable is exactly what would make a future edit
        // collapse them and silently turn the path-mode E2E test back into a second
        // domain-mode one. See E2eSeeder's own comment on this key.
        ->and($context['docker_path_host'])->toBe('localhost:8099')
        ->and($context['docker_path_host'])->not->toBe($context['docker_host'])
        ->and($context['docker_path_repository'])->toBe('e2e-customer/e2e-registry/kontorfix-e2e-demo')
        ->and($context['version'])->toBe('1.0.0');

    $customer = Organization::where('slug', 'e2e-customer')->firstOrFail();

    expect($customer->enabled_registry_types)->toEqualCanonicalizing(['composer', 'npm', 'python', 'docker'])
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

it('seeds a docker repository the docker E2E tests can target, with a matching domain row', function () {
    Artisan::call('db:seed', ['--class' => E2eSeeder::class, '--force' => true]);

    $group = Organization::where('slug', 'e2e-customer')->firstOrFail()
        ->groups()->where('slug', 'e2e-registry')->firstOrFail();

    $package = $group->packages()
        ->where('packages.type', PackageType::Docker)->where('packages.name', 'kontorfix-e2e-demo')->firstOrFail();

    // Publish-based, same as npm/Python: nothing to sync, and `ociWritableRepository()`
    // requires the row to exist before the first `docker push` for the identical reason
    // NpmController/PypiController refuse an unknown package.
    expect($package->repository_url)->toBeNull();

    // `/v2/` is registered only at the domain-access root (routes/registry.php), never
    // under the `/r/{orgSlug}/{groupSlug}` slug prefix — a real Docker client has no way to
    // address a path-prefixed registry — so this group needs its own `domains` row, unlike
    // the other three ecosystems which are already reachable at the slug path.
    //
    // The seeded hostname carries NO port even though the E2E stack publishes the app at
    // `127.0.0.1:8099`: `Request::getHost()` (Symfony) always strips a trailing `:<port>`
    // before ResolveRegistryContext looks the value up, so a hostname seeded WITH the port
    // would never match. tests/Feature/Registry/CustomDomainTest.php pins that stripping
    // behaviour directly against ResolveRegistryContext; this assertion only pins what the
    // seeder itself writes.
    $domain = Domain::where('group_id', $group->id)->firstOrFail();
    expect($domain->hostname)->toBe('127.0.0.1');
});

it('configures one upstream per ecosystem', function () {
    Artisan::call('db:seed', ['--class' => E2eSeeder::class, '--force' => true]);

    $group = Organization::where('slug', 'e2e-customer')->firstOrFail()
        ->groups()->where('slug', 'e2e-registry')->firstOrFail();

    // Created unconditionally, not behind E2E_UPSTREAM: only the TESTS are gated. A seeder
    // that read the flag would make the two runs differ in more than which tests execute.
    expect($group->upstreams()->pluck('url', 'type')->all())->toBe([
        'composer' => 'https://repo.packagist.org',
        'npm' => 'https://registry.npmjs.org',
        'python' => 'https://pypi.org',
    ]);
});

it('refuses to run outside local and testing environments', function () {
    // staging, not production: the guard used to check `=== 'production'` by name, which
    // would also refuse staging by accident only if it happened to equal that literal — it
    // did not. staging is the value that actually distinguishes "refuses only production"
    // from "refuses everything but local/testing"; asserting against production alone would
    // pass under either version of the guard and prove nothing about which one is in place.
    app()['env'] = 'staging';

    expect(fn () => Artisan::call('db:seed', ['--class' => E2eSeeder::class, '--force' => true]))
        ->toThrow(RuntimeException::class, 'E2eSeeder refuses to run outside local/testing environments');

    // All three of the guard's real consequences, not just the customer org: the operator
    // organization is created FIRST, so a regression that moved this check between the two
    // Organization::create() calls would leave a stray operator org and an admin account
    // with a hardcoded password (e2e@example.invalid / e2e-password) on a production-like
    // host, and asserting only e2e-customer's absence would stay green through exactly that.
    expect(Organization::where('slug', 'e2e-customer')->exists())->toBeFalse()
        ->and(Organization::where('slug', 'e2e-operator')->exists())->toBeFalse()
        ->and(User::where('email', 'e2e@example.invalid')->exists())->toBeFalse();
});
