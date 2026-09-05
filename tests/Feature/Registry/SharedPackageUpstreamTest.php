<?php

// Spec §4: the dependency-confusion boundary widens with the namespace. A shared package
// assigned to the addressed registry counts as hosted, so a customer resolving a shared name
// is never sent to Packagist, npmjs or PyPI — that is exactly the confusion the guard exists
// to prevent, and shipping it through the feature meant to serve those customers would be
// the worst way to introduce it. A shared package this registry was never handed is not
// hosted here, and must not suppress a legitimate upstream dependency of the same name.
//
// And, per §4 as amended during execution, the two are not the same thing as an assignment
// that LAPSED: the name was served from here, so it is in the customer's lock file, and
// falling through would resolve it from the public index with no act by anyone. The guard
// fails closed on anything this registry has ever served; detaching, an explicit act, is
// what releases a name back to the upstream.
//
// EVERY test that makes a request in this file configures an upstream, and none of them
// means anything without one: ComposerController::metadata() and
// NpmController::respondPackument() abort 404 on `$upstream === null` before any HTTP call,
// so Http::assertNothingSent() would assert nothing and a disabled guard would stay green.
// PypiController::simpleProject() does the same, which is why the PyPI cases distinguish
// 200 from 302 rather than 404 from anything.

use App\Enums\PackageType;
use App\Enums\UpstreamPolicy;
use App\Http\Controllers\Registry\PypiController;
use App\Http\Controllers\Registry\ResolvesRegistryPackage;
use App\Models\Group;
use App\Models\Organization;
use App\Models\Package;
use App\Models\Upstream;
use App\Services\RegistryAccessService;
use Illuminate\Support\Facades\Http;

/** A shared package, owned by an operator organization, assigned to each given registry. */
function sharedPackageAssignedTo(PackageType $type, string $name, Group ...$groups): Package
{
    $operator = Organization::factory()->create(['is_operator' => true]);
    $package = Package::factory()->for($operator)->create([
        'type' => $type, 'name' => $name, 'shared' => true,
    ]);

    foreach ($groups as $group) {
        // A bare attach(), like the other shared-package tests: this file is about what the
        // guard does with a pivot row, not about how one is written.
        $group->packages()->attach($package);
    }

    return $package;
}

function registryWithUpstream(PackageType $type, string $url): Group
{
    $group = Group::factory()->create(['public' => true]);
    Upstream::factory()->for($group)->create([
        'type' => $type, 'url' => $url, 'policy' => UpstreamPolicy::Proxy,
    ]);

    return $group;
}

// --- The invariant, end to end -------------------------------------------------------
//
// What a customer's client actually experiences. These do not care WHICH half of the chain
// answers — resolution or the guard — only that a shared name this registry serves never
// produces an outbound request, and that a shared name it does not serve still does. The
// unassigned fixtures assign the package to a DIFFERENT registry rather than leaving it
// unassigned everywhere: "shared" alone must not suppress, and neither must "assigned
// somewhere". Only an assignment to THIS registry may.

it('does not send a shared composer name assigned to this registry upstream', function () {
    Http::fake(['*' => Http::response(['minified' => 'composer/2.0', 'packages' => []], 200)]);
    $group = registryWithUpstream(PackageType::Composer, 'https://repo.packagist.org');
    sharedPackageAssignedTo(PackageType::Composer, 'acme/shared', $group);

    $this->get(registryPath($group).'/p2/acme/shared.json')->assertOk();

    Http::assertNothingSent();
});

it('falls through to packagist for a shared composer name assigned to another registry', function () {
    Http::fake(['*' => Http::response(['minified' => 'composer/2.0', 'packages' => []], 200)]);
    $group = registryWithUpstream(PackageType::Composer, 'https://repo.packagist.org');
    sharedPackageAssignedTo(PackageType::Composer, 'acme/shared', Group::factory()->create(['public' => true]));

    $this->get(registryPath($group).'/p2/acme/shared.json');

    // Sharing grants eligibility, not access — and a name this registry was never handed
    // must not blank out the customer's own upstream dependency of that name.
    Http::assertSentCount(1);
});

it('does not send a shared npm name assigned to this registry upstream', function () {
    Http::fake(['*' => Http::response(['name' => 'shared-lib', 'versions' => []], 200)]);
    $group = registryWithUpstream(PackageType::Npm, 'https://registry.npmjs.org');
    sharedPackageAssignedTo(PackageType::Npm, 'shared-lib', $group);

    $this->get(registryPath($group).'/shared-lib')->assertOk();

    Http::assertNothingSent();
});

it('falls through to npmjs for a shared npm name assigned to another registry', function () {
    Http::fake(['*' => Http::response(['name' => 'shared-lib', 'versions' => []], 200)]);
    $group = registryWithUpstream(PackageType::Npm, 'https://registry.npmjs.org');
    sharedPackageAssignedTo(PackageType::Npm, 'shared-lib', Group::factory()->create(['public' => true]));

    $this->get(registryPath($group).'/shared-lib');

    Http::assertSentCount(1);
});

it('does not redirect a shared python project assigned to this registry to pypi', function () {
    $group = registryWithUpstream(PackageType::Python, 'https://pypi.org');
    sharedPackageAssignedTo(PackageType::Python, 'shared-lib', $group);

    // 200, not 302: the upstream is configured, so a fallthrough would be visible as a
    // redirect to pypi.org rather than as a 404.
    $this->get(registryPath($group).'/simple/shared-lib/')->assertOk();
});

it('redirects to pypi for a shared python project assigned to another registry', function () {
    $group = registryWithUpstream(PackageType::Python, 'https://pypi.org');
    sharedPackageAssignedTo(PackageType::Python, 'shared-lib', Group::factory()->create(['public' => true]));

    $this->get(registryPath($group).'/simple/shared-lib/')
        ->assertRedirect('https://pypi.org/simple/shared-lib/');
});

// --- Lapsed versus detached ----------------------------------------------------------
//
// The one state where clause 2 of the guard is reachable end to end: a lapsed assignment
// serves nothing, so resolution declines and the guard alone decides. It must answer "hosted"
// — the customer consumed this name from here, and the alternative is that their next
// `composer update` silently takes it from whoever owns it on Packagist. Detaching is the
// operator's explicit release, and only that opens the fallthrough.

it('does not send a shared composer name whose assignment lapsed upstream', function () {
    Http::fake(['*' => Http::response(['minified' => 'composer/2.0', 'packages' => []], 200)]);
    $group = registryWithUpstream(PackageType::Composer, 'https://repo.packagist.org');
    $shared = sharedPackageAssignedTo(PackageType::Composer, 'acme/shared');
    $group->packages()->attach($shared, ['available_until' => now()->subDay()]);

    // 404 and a loud build failure, not a quiet substitution.
    $this->get(registryPath($group).'/p2/acme/shared.json')->assertNotFound();

    Http::assertNothingSent();
});

it('falls through to packagist once a shared composer assignment is detached', function () {
    Http::fake(['*' => Http::response(['minified' => 'composer/2.0', 'packages' => []], 200)]);
    $group = registryWithUpstream(PackageType::Composer, 'https://repo.packagist.org');
    $shared = sharedPackageAssignedTo(PackageType::Composer, 'acme/shared', $group);
    $group->packages()->detach($shared);

    $this->get(registryPath($group).'/p2/acme/shared.json');

    Http::assertSentCount(1);
});

it('does not send a shared npm name whose assignment lapsed upstream', function () {
    Http::fake(['*' => Http::response(['name' => 'shared-lib', 'versions' => []], 200)]);
    $group = registryWithUpstream(PackageType::Npm, 'https://registry.npmjs.org');
    $shared = sharedPackageAssignedTo(PackageType::Npm, 'shared-lib');
    $group->packages()->attach($shared, ['available_until' => now()->subDay()]);

    $this->get(registryPath($group).'/shared-lib')->assertNotFound();

    Http::assertNothingSent();
});

it('falls through to npmjs once a shared npm assignment is detached', function () {
    Http::fake(['*' => Http::response(['name' => 'shared-lib', 'versions' => []], 200)]);
    $group = registryWithUpstream(PackageType::Npm, 'https://registry.npmjs.org');
    $shared = sharedPackageAssignedTo(PackageType::Npm, 'shared-lib', $group);
    $group->packages()->detach($shared);

    $this->get(registryPath($group).'/shared-lib');

    Http::assertSentCount(1);
});

it('does not redirect a shared python project whose assignment lapsed to pypi', function () {
    $group = registryWithUpstream(PackageType::Python, 'https://pypi.org');
    $shared = sharedPackageAssignedTo(PackageType::Python, 'shared-lib');
    $group->packages()->attach($shared, ['available_until' => now()->subDay()]);

    // 404 rather than the 302 the configured upstream would otherwise produce.
    $this->get(registryPath($group).'/simple/shared-lib/')->assertNotFound();
});

it('redirects to pypi once a shared python assignment is detached', function () {
    $group = registryWithUpstream(PackageType::Python, 'https://pypi.org');
    $shared = sharedPackageAssignedTo(PackageType::Python, 'shared-lib', $group);
    $group->packages()->detach($shared);

    $this->get(registryPath($group).'/simple/shared-lib/')
        ->assertRedirect('https://pypi.org/simple/shared-lib/');
});

// --- The guard itself ----------------------------------------------------------------
//
// The tests above pin what the customer sees. For a LIVE assignment the *resolution* half
// answers, not the guard: since Task 4, every row the shared clause admits with a live
// assignment is a row findLocal() and pythonPackagesOfGroup() already serve, so no request
// can reach the guard in that state. Measured, not assumed — before the guards were widened,
// the first six tests above already passed. The lapsed cases are the exception, and they are
// the only ones that reach the shared clause through HTTP.
//
// That leaves the clause's positive direction for a live assignment covered by nothing at
// all end to end: deleting it would not turn one of those six red, and it would be one
// refactor of the resolution path away from being silently gone — at which point a shared
// name would be answered by Packagist instead of by a 404, and the guard would be returning
// a wrong answer to its own question rather than a redundant one. So the predicate is also
// exercised where it decides. No upstream is configured for these, because no request is
// made: the trap the header describes is about assertions on outbound HTTP.

/** `ResolvesRegistryPackage::packageExistsLocally()`, which is protected on the trait. */
function hostsLocally(PackageType $type, string $fullName, Group $group): bool
{
    $probe = new class
    {
        use ResolvesRegistryPackage;

        public function hosts(PackageType $type, string $fullName, Group $group): bool
        {
            return $this->packageExistsLocally($type, $fullName, $group);
        }

        protected function access(): RegistryAccessService
        {
            return app(RegistryAccessService::class);
        }
    };

    return $probe->hosts($type, $fullName, $group);
}

/** `PypiController::pythonExistsLocally()`, which is private on the controller. */
function pythonHostsLocally(string $normalized, Group $group): bool
{
    $method = new ReflectionMethod(PypiController::class, 'pythonExistsLocally');

    return (bool) $method->invoke(app(PypiController::class), $normalized, $group);
}

it('counts a shared composer package assigned to this registry as hosted', function () {
    $group = Group::factory()->create(['public' => true]);
    sharedPackageAssignedTo(PackageType::Composer, 'acme/shared', $group);

    expect(hostsLocally(PackageType::Composer, 'acme/shared', $group))->toBeTrue();
});

it('counts a shared npm package assigned to this registry as hosted', function () {
    $group = Group::factory()->create(['public' => true]);
    sharedPackageAssignedTo(PackageType::Npm, 'shared-lib', $group);

    expect(hostsLocally(PackageType::Npm, 'shared-lib', $group))->toBeTrue();
});

it('counts a shared python project assigned to this registry as hosted', function () {
    $group = Group::factory()->create(['public' => true]);
    // Stored unnormalised on purpose: the guard compares PEP 503 names, and widening its
    // query must not have cost it that.
    sharedPackageAssignedTo(PackageType::Python, 'Shared_Lib', $group);

    expect(pythonHostsLocally('shared-lib', $group))->toBeTrue();
});

it('does not count a shared package assigned to another registry as hosted', function () {
    $group = Group::factory()->create(['public' => true]);
    $elsewhere = Group::factory()->create(['public' => true]);
    sharedPackageAssignedTo(PackageType::Composer, 'acme/shared', $elsewhere);
    sharedPackageAssignedTo(PackageType::Npm, 'shared-lib', $elsewhere);
    sharedPackageAssignedTo(PackageType::Python, 'shared-lib', $elsewhere);

    expect(hostsLocally(PackageType::Composer, 'acme/shared', $group))->toBeFalse()
        ->and(hostsLocally(PackageType::Npm, 'shared-lib', $group))->toBeFalse()
        ->and(pythonHostsLocally('shared-lib', $group))->toBeFalse();
});

it('still counts a shared package whose assignment here has lapsed as hosted', function () {
    $group = Group::factory()->create(['public' => true]);
    $composer = sharedPackageAssignedTo(PackageType::Composer, 'acme/shared');
    $npm = sharedPackageAssignedTo(PackageType::Npm, 'shared-lib');
    $python = sharedPackageAssignedTo(PackageType::Python, 'shared-lib');
    $group->packages()->attach($composer, ['available_until' => now()->subDay()]);
    $group->packages()->attach($npm, ['available_until' => now()->subDay()]);
    $group->packages()->attach($python, ['available_until' => now()->subDay()]);

    // packages(), not assignedPackages(): a lapsed assignment stops the registry SERVING the
    // name and must not stop it CLAIMING it. The customer resolved this name from here, so
    // the alternative to a 404 is their next build taking it from the public index, from
    // whoever registered it there — dependency confusion arriving by the passage of time.
    expect(hostsLocally(PackageType::Composer, 'acme/shared', $group))->toBeTrue()
        ->and(hostsLocally(PackageType::Npm, 'shared-lib', $group))->toBeTrue()
        ->and(pythonHostsLocally('shared-lib', $group))->toBeTrue();
});

it('does not count a shared package detached from this registry as hosted', function () {
    $group = Group::factory()->create(['public' => true]);
    $composer = sharedPackageAssignedTo(PackageType::Composer, 'acme/shared', $group);
    $npm = sharedPackageAssignedTo(PackageType::Npm, 'shared-lib', $group);
    $python = sharedPackageAssignedTo(PackageType::Python, 'shared-lib', $group);
    $group->packages()->detach([$composer->id, $npm->id, $python->id]);

    // The other side of the same rule: an explicit act releases the name, and only it does.
    expect(hostsLocally(PackageType::Composer, 'acme/shared', $group))->toBeFalse()
        ->and(hostsLocally(PackageType::Npm, 'shared-lib', $group))->toBeFalse()
        ->and(pythonHostsLocally('shared-lib', $group))->toBeFalse();
});

it('still counts an unassigned package of this organization as hosted', function () {
    $group = Group::factory()->create(['public' => true]);
    Package::factory()->inOrgOf($group)->create(['type' => PackageType::Composer, 'name' => 'acme/tools']);
    Package::factory()->inOrgOf($group)->create(['type' => PackageType::Python, 'name' => 'internal-lib']);

    // The half that must NOT become registry-scoped. Assignment is the shared clause's
    // condition alone: a private package attached to no registry still must never have its
    // name asked about upstream, and collapsing the two halves into one assignment-filtered
    // query would leak exactly those names.
    expect(hostsLocally(PackageType::Composer, 'acme/tools', $group))->toBeTrue()
        ->and(pythonHostsLocally('internal-lib', $group))->toBeTrue();
});
