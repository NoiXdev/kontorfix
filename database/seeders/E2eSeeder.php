<?php

namespace Database\Seeders;

use App\Enums\PackageType;
use App\Enums\TokenAbility;
use App\Enums\UserRole;
use App\Models\Group;
use App\Models\Organization;
use App\Models\Package;
use App\Models\RegistryToken;
use App\Models\User;
use Illuminate\Database\Seeder;
use RuntimeException;

/**
 * The fixture world for the end-to-end run (docker/compose.e2e.yaml).
 *
 * It ships inside the production image — seeders are not excluded from the build — so the
 * environment guard below is what stands between a stray `db:seed` on a production host and
 * an organization holding a live publish token.
 *
 * The Composer package's repository_url is written straight to the model, as the test
 * factories do, because RepositoryUrlRules::shape() accepts only https and ssh. The clone
 * itself is NOT bypassed: GitUrlSafety still runs at sync time, and the stack satisfies it
 * through KONTORFIX_VCS_ALLOWED_SCHEMES / KONTORFIX_VCS_ALLOWED_HOSTS — the same escape
 * hatches an operator with an internal git server uses.
 */
class E2eSeeder extends Seeder
{
    public function run(): void
    {
        if (app()->environment('production')) {
            throw new RuntimeException('E2eSeeder refuses to run in production.');
        }

        $operator = Organization::create([
            'name' => 'E2E Operator',
            'slug' => 'e2e-operator',
            'is_operator' => true,
            'enabled_registry_types' => ['composer', 'npm', 'python'],
        ]);

        $customer = Organization::create([
            'name' => 'E2E Customer',
            'slug' => 'e2e-customer',
            'is_operator' => false,
            'enabled_registry_types' => ['composer', 'npm', 'python'],
        ]);

        // RequireSetup makes the wizard the only reachable part of the web group while no
        // user exists. The registry routes sit outside that group and would work without
        // this, but a stack no human can log into is not worth the seconds it saves.
        //
        // `email_verified_at` is not mass-assignable (User::$fillable omits it, since the
        // normal signup flow verifies asynchronously), so it is stamped separately via
        // forceFill rather than folded into create() — silently dropped there, it would
        // still create a working password-protected account, but not one already verified.
        $operatorUser = User::create([
            'name' => 'E2E Operator',
            'email' => 'e2e@example.invalid',
            'password' => 'e2e-password',
            'role' => UserRole::Admin,
            'organization_id' => $operator->id,
        ]);
        $operatorUser->forceFill(['email_verified_at' => now()])->save();

        // ONE registry, serving all three ecosystems. enabled_registry_types lives on the
        // organization, so a registry per ecosystem is not a thing this schema can express —
        // and one registry carrying both Composer and npm is what exercises the route
        // ordering in routes/registry.php that keeps npm's root-level packument catch-all
        // from swallowing packages.json.
        $group = Group::create([
            'organization_id' => $customer->id,
            'name' => 'E2E Registry',
            'slug' => 'e2e-registry',
            'public' => false,
            'portal_enabled' => true,
        ]);

        $composerPackage = Package::create([
            'organization_id' => $customer->id,
            'type' => PackageType::Composer,
            'name' => 'kontorfix-e2e/demo',
            'description' => 'Fixture package for the end-to-end suite.',
            'repository_url' => 'git://gitserver/demo.git',
        ]);

        $group->packages()->attach($composerPackage);

        [, $readToken] = RegistryToken::issue($customer, 'e2e-read', $group, TokenAbility::Read);
        [, $publishToken] = RegistryToken::issue($customer, 'e2e-publish', $group, TokenAbility::Publish);

        $base = '/r/e2e-customer/e2e-registry';

        // A single line the runner greps out of `artisan db:seed`'s own chatter. The tokens
        // never touch the repository: bin/e2e writes this to tests/E2E/.context.json, which
        // is git-ignored and deleted on teardown.
        $this->command->getOutput()->writeln('E2E_CONTEXT='.json_encode([
            'base_url' => 'http://app:8080'.$base,
            'host_base_url' => 'http://127.0.0.1:8099'.$base,
            'read_token' => $readToken,
            'publish_token' => $publishToken,
            'composer_package' => 'kontorfix-e2e/demo',
            'npm_package' => 'kontorfix-e2e-demo',
            'python_package' => 'kontorfix-e2e-demo',
            'python_module' => 'kontorfix_e2e_demo',
            'version' => '1.0.0',
        ], JSON_THROW_ON_ERROR));
    }
}
