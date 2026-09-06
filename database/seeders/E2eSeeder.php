<?php

namespace Database\Seeders;

use App\Enums\PackageSourceMode;
use App\Enums\PackageType;
use App\Enums\TokenAbility;
use App\Enums\UpstreamPolicy;
use App\Enums\UserRole;
use App\Jobs\SyncPackage;
use App\Models\Domain;
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
 * environment guard below is what stands between a stray `db:seed` on a host this fixture
 * was never meant for and an organization holding a live publish token, plus an admin
 * account (`e2e@example.invalid` / `e2e-password`) that also satisfies RequireSetup. The
 * guard allows only `local` and `testing` rather than refusing only `production` by name:
 * on a `staging` host — or any other environment nobody has named yet — a manual
 * `db:seed --class=E2eSeeder --force` would otherwise sail straight through.
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
        if (! app()->environment(['local', 'testing'])) {
            throw new RuntimeException('E2eSeeder refuses to run outside local/testing environments.');
        }

        $operator = Organization::create([
            'name' => 'E2E Operator',
            'slug' => 'e2e-operator',
            'is_operator' => true,
            'enabled_registry_types' => ['composer', 'npm', 'python', 'docker'],
        ]);

        $customer = Organization::create([
            'name' => 'E2E Customer',
            'slug' => 'e2e-customer',
            'is_operator' => false,
            'enabled_registry_types' => ['composer', 'npm', 'python', 'docker'],
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

        // One upstream per ecosystem, created unconditionally. Only the tests that USE them
        // are gated on E2E_UPSTREAM — a seeder that read the flag would make the flagged and
        // unflagged runs differ in more than which tests execute, and then a green default
        // run would say nothing about the configuration the flagged run exercises.
        //
        // `proxy` rather than `strict`: strict is the dependency-confusion allowlist, and an
        // allowlist with nothing on it would refuse the very fallthrough these tests measure.
        foreach ([
            'composer' => 'https://repo.packagist.org',
            'npm' => 'https://registry.npmjs.org',
            'python' => 'https://pypi.org',
        ] as $type => $url) {
            $group->upstreams()->create([
                'type' => $type,
                'url' => $url,
                'policy' => UpstreamPolicy::Proxy,
                'priority' => 1,
                'enabled' => true,
            ]);
        }

        // `source_mode` is NOT optional here, and its absence fails silently: the column
        // defaults to `publish` (see the migration that introduced it), and
        // Package::isGitSourced() reads this column rather than inferring anything from
        // `repository_url` being set. A Composer row created without it looks correctly
        // seeded in every way a quick read would check — repository_url is there, the type
        // is right — but `packages:resync` skips it forever, because it filters on
        // isGitSourced(), and no error, log line, or failed job ever appears to say why. The
        // real creation paths (Admin\PackageController, Api\V1\PackageController,
        // PackageFactory) all set this explicitly for exactly this reason; this seeder must
        // match them rather than rely on the column default meant for npm/Python.
        $composerPackage = Package::create([
            'organization_id' => $customer->id,
            'type' => PackageType::Composer,
            'name' => 'kontorfix-e2e/demo',
            'description' => 'Fixture package for the end-to-end suite.',
            'repository_url' => 'git://gitserver/demo.git',
            'source_mode' => PackageSourceMode::Git,
        ]);

        // Unlike Composer, npm and Python packages are publish-based (PackageSourceMode::
        // Publish, the `source_mode` column's own default): there is no git tag for
        // SyncPackage to import versions from, so each row must exist before the first
        // `npm publish` / `twine upload` — NpmController and PypiController both resolve
        // their target package by (type, name, organization) before accepting a write, and
        // 404 otherwise, exactly as they would for a foreign package. That refusal is
        // deliberate, not an obstacle to route around: it is what stops a token from
        // inventing package names on the fly, and this seeder models the sequence a real
        // deployment goes through — the operator registers the package first, then CI
        // publishes versions into it. No `repository_url` on either: publish-based packages
        // have nothing to sync from.
        //
        // npm and Python share the name `kontorfix-e2e-demo` on purpose — the uniqueness
        // constraint on `packages` is (organization_id, type, name), scoped by type, so the
        // two coexist and the fixture world can use one recognisable name across ecosystems.
        $npmPackage = Package::create([
            'organization_id' => $customer->id,
            'type' => PackageType::Npm,
            'name' => 'kontorfix-e2e-demo',
            'description' => 'Fixture package for the end-to-end suite.',
        ]);

        $pythonPackage = Package::create([
            'organization_id' => $customer->id,
            'type' => PackageType::Python,
            'name' => 'kontorfix-e2e-demo',
            'description' => 'Fixture package for the end-to-end suite.',
        ]);

        // Docker repositories are publish-based too — same reasoning as npm/Python above,
        // and the same reason `ociWritableRepository()` refuses an unregistered name: a
        // publish token must not be able to invent a repository on push.
        //
        // The name reuses the lowercase-with-dashes form the other two publish-based types
        // already share (`kontorfix-e2e-demo`) — legal under PackageType::Docker's own name
        // grammar (a single lowercase-alphanumeric-and-dash component), and the uniqueness
        // constraint is scoped by `type`, so all three coexist under one name.
        $dockerPackage = Package::create([
            'organization_id' => $customer->id,
            'type' => PackageType::Docker,
            'name' => 'kontorfix-e2e-demo',
            'description' => 'Fixture package for the end-to-end suite.',
        ]);

        $group->packages()->attach([$composerPackage->id, $npmPackage->id, $pythonPackage->id, $dockerPackage->id]);

        // The `/v2/` OCI routes exist ONLY at the domain-access root (routes/registry.php
        // registers them outside the `/r/{orgSlug}/{groupSlug}` prefix group — a real Docker
        // client cannot address a path-prefixed registry), so this group needs a `domains`
        // row, unlike Composer/npm/Python which are reachable at the slug path already.
        //
        // The hostname is bare `127.0.0.1`, with NO port, even though the Docker client
        // reaches this stack at `127.0.0.1:8099` (docker/compose.e2e.yaml's published
        // loopback port — the one plaintext-HTTP exception the Docker daemon honours for a
        // registry, see tests/E2E/DockerTest.php). `ResolveRegistryContext` looks the
        // request up by `Request::getHost()`, and Symfony's implementation of that method
        // always strips a trailing `:<port>` before returning (confirmed in
        // vendor/symfony/http-foundation/Request.php) — the same way a browser's Host
        // header is parsed. A `domains` row seeded WITH the port would simply never match
        // and this whole suite would 404 on every request. No change to
        // ResolveRegistryContext was needed for this; tests/Feature/Registry/
        // CustomDomainTest.php now has a dedicated case pinning that stripping behaviour so
        // a future change to that lookup cannot silently reintroduce the port into it.
        Domain::create(['group_id' => $group->id, 'hostname' => '127.0.0.1']);

        // Every real creation path dispatches this itself right after creating a
        // git-sourced package (Admin\PackageController, Api\V1\PackageController) — nothing
        // else in the E2E stack will. There is deliberately no `scheduler` service in
        // docker/compose.e2e.yaml, so the hourly `packages:resync` schedule that would
        // otherwise catch a package like this never runs there. This dispatch does NOT make
        // the seeder perform the sync itself: it only pushes the job onto Redis, and the
        // `worker` container is what actually clones the repository, scans its tags, and
        // builds the dist — which is precisely the queued path the Composer E2E run exists
        // to exercise.
        SyncPackage::dispatch($composerPackage);

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
            'docker_repository' => 'kontorfix-e2e-demo',
            'docker_host' => '127.0.0.1:8099',
            'version' => '1.0.0',
        ], JSON_THROW_ON_ERROR));
    }
}
