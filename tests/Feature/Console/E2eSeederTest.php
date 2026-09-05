<?php

use App\Enums\PackageType;
use App\Models\Organization;
use Database\Seeders\E2eSeeder;
use Illuminate\Support\Facades\Artisan;

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

it('seeds the composer package against the git daemon and leaves it unsynced', function () {
    Artisan::call('db:seed', ['--class' => E2eSeeder::class, '--force' => true]);

    $package = Organization::where('slug', 'e2e-customer')->firstOrFail()
        ->packages()->where('type', PackageType::Composer)->where('name', 'kontorfix-e2e/demo')->firstOrFail();

    // The worker syncs it; the seeder must not, or the E2E run would prove nothing about
    // the queued path it exists to cover.
    expect($package->repository_url)->toBe('git://gitserver/demo.git')
        ->and($package->versions()->count())->toBe(0)
        ->and($package->groups()->where('groups.slug', 'e2e-registry')->exists())->toBeTrue();
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
