<?php

/*
 * One source for every install command the portal prints.
 *
 * THIS FILE EXISTS FOR ONE COMMAND IN PARTICULAR. `PackageType::installHint()` built a
 * registry-less command for every ecosystem and both portal surfaces rendered it. For
 * Composer and npm that is unhelpful: the command fails in an unconfigured project, visibly
 * and immediately. For Python it is not a usability defect at all — `pip install kernmodul`
 * without `--index-url` resolves against PyPI, so if a package of that name exists on the
 * public index, pip installs THAT, into a customer's build, with no error anywhere. The
 * portal was printing an instruction to fetch a stranger's code.
 *
 * EVERY EXPECTATION HERE IS A WHOLE STRING. A `toContain('--index-url')` passes for a command
 * whose index URL points at the wrong registry — or at PyPI with a flag bolted on — which is
 * precisely the bug being fixed. The same rule applies to the Docker cases: the namespace is
 * the difference between pulling the customer's image and pulling nothing.
 *
 * `config('app.url')` is pinned in beforeEach because half of these assertions are about the
 * instance host appearing inside the command.
 */

use App\Enums\PackageType;
use App\Enums\UserRole;
use App\Models\Domain;
use App\Models\Group;
use App\Models\OciTag;
use App\Models\Organization;
use App\Models\Package;
use App\Models\User;
use App\Services\Registry\SetupSnippetBuilder;

beforeEach(function () {
    config(['app.url' => 'https://reg.example.test']);
    $this->org = Organization::factory()->create(['slug' => 'acme']);
    $this->member = User::factory()->for($this->org)->create(['role' => UserRole::Member]);
    $this->group = Group::factory()->for($this->org)->create(['slug' => 'intern', 'name' => 'Intern']);
});

/** A package of the given type, assigned to the given registry. */
function installPackage(Group $group, string $type, string $name, ?string $until = null): Package
{
    /** @var Package $package */
    $package = Package::factory()->inOrgOf($group)->create(['type' => $type, 'name' => $name]);
    $group->packages()->attach($package, ['available_until' => $until]);

    return $package;
}

/**
 * One tag on one repository, written the way a push writes it: an `oci_tags` row pointing at
 * an `oci_manifests` row. `updated_at` is set explicitly because it is what "newest tag"
 * means, and Eloquent leaves an explicitly dirty timestamp alone.
 */
function pushTag(Package $package, string $name, string $updatedAt): void
{
    $manifest = $package->ociManifests()->firstOrCreate(
        ['digest' => 'sha256:'.hash('sha256', $package->id)],
        ['media_type' => 'application/vnd.oci.image.manifest.v1+json', 'payload' => '{}', 'size' => 2],
    );

    $tag = new OciTag(['package_id' => $package->id, 'name' => $name, 'manifest_id' => $manifest->id]);
    $tag->updated_at = $updatedAt;
    $tag->save();
}

// --- The builder itself: one method, four ecosystems ---

it('points the python command at this registry rather than at PyPI', function () {
    // THE DEFECT, stated as one string. The old answer was `pip install kernmodul`, which is
    // a working command — it just works against the wrong index. Nothing short of the whole
    // string distinguishes the two.
    $command = app(SetupSnippetBuilder::class)
        ->installCommand($this->group, PackageType::Python, 'kernmodul');

    expect($command)->toBe(
        'pip install --index-url https://token:<token>@reg.example.test/r/acme/intern/simple/ kernmodul'
    );
});

it('follows a custom domain into the python command', function () {
    Domain::factory()->for($this->group)->create(['hostname' => 'pakete.acme.test']);
    $this->group->load('domains');

    // The path prefix is GONE on a custom domain — the registry is the whole host there.
    // A command that kept `/r/acme/intern` would 404 against that host.
    expect(app(SetupSnippetBuilder::class)->installCommand($this->group, PackageType::Python, 'kernmodul'))
        ->toBe('pip install --index-url https://token:<token>@pakete.acme.test/simple/ kernmodul');
});

it('leaves composer and npm registry-less, because neither client takes a registry argument', function () {
    // Deliberate, not an omission: `composer require` and `npm install` are configured once
    // in composer.json/.npmrc, which is what the setup tab and the page's prerequisite line
    // are for. A flag these two clients do not have would be a command that cannot be run.
    $builder = app(SetupSnippetBuilder::class);

    expect($builder->installCommand($this->group, PackageType::Composer, 'acme/kernmodul'))
        ->toBe('composer require acme/kernmodul')
        ->and($builder->installCommand($this->group, PackageType::Npm, '@acme/ui-kit'))
        ->toBe('npm install @acme/ui-kit');
});

it('writes the path namespace into a docker pull on the instance host', function () {
    // The two leading segments ResolveOciContext strips back off. Without them the pull
    // addresses a repository that does not exist, and 404 is the only symptom.
    expect(app(SetupSnippetBuilder::class)->installCommand($this->group, PackageType::Docker, 'meinapp', '1.4.0'))
        ->toBe('docker pull reg.example.test/acme/intern/meinapp:1.4.0');
});

it('drops the namespace from a docker pull once the registry has its own host', function () {
    Domain::factory()->for($this->group)->create(['hostname' => 'images.acme.test']);
    $this->group->load('domains');

    expect(app(SetupSnippetBuilder::class)->installCommand($this->group, PackageType::Docker, 'meinapp', '1.4.0'))
        ->toBe('docker pull images.acme.test/meinapp:1.4.0');
});

it('omits the tag rather than inventing one for a repository nothing has been pushed to', function () {
    // `docker pull host/repo` is a real command — Docker reads it as `:latest`. A `<tag>`
    // placeholder would be neither runnable nor true.
    expect(app(SetupSnippetBuilder::class)->installCommand($this->group, PackageType::Docker, 'meinapp'))
        ->toBe('docker pull reg.example.test/acme/intern/meinapp');
});

// --- The package page (plate 4) ---

it('sends the package page a python command carrying this registry index url', function () {
    $package = installPackage($this->group, 'python', 'kernmodul');

    $this->actingAs($this->member)
        ->get("/c/acme/registries/{$this->group->id}/packages/{$package->id}")
        ->assertOk()
        ->assertInertia(fn ($p) => $p->component('portal/Package')
            ->where('install', 'pip install --index-url https://token:<token>@reg.example.test/r/acme/intern/simple/ kernmodul')
            ->etc());
});

it('names the registry host the package page will tell a reader to log in to', function () {
    $package = installPackage($this->group, 'docker', 'meinapp');

    // The prerequisite line above a Docker pull says `docker login <this>`. It is the host
    // WITHOUT the namespace and without a scheme — `registry.url` is neither.
    $this->actingAs($this->member)
        ->get("/c/acme/registries/{$this->group->id}/packages/{$package->id}")
        ->assertOk()
        ->assertInertia(fn ($p) => $p->component('portal/Package')
            ->where('registry.docker_host', 'reg.example.test')
            ->where('registry.url', 'https://reg.example.test/r/acme/intern')
            ->etc());
});

it('pulls the newest pushed tag into the package pages docker command', function () {
    $package = installPackage($this->group, 'docker', 'meinapp');
    // Tags are `oci_tags` rows, never `package_versions` — the OCI push path writes no
    // version row at all, so a command derived from versions would print the untagged form
    // for a repository that plainly has tags.
    pushTag($package, '1.2.0', now()->subDay()->toDateTimeString());
    pushTag($package, '1.4.0', now()->toDateTimeString());

    $this->actingAs($this->member)
        ->get("/c/acme/registries/{$this->group->id}/packages/{$package->id}")
        ->assertOk()
        ->assertInertia(fn ($p) => $p->component('portal/Package')
            ->where('install', 'docker pull reg.example.test/acme/intern/meinapp:1.4.0')
            ->etc());
});

it('gives the package page of a lapsed assignment no command at all', function () {
    $package = installPackage($this->group, 'python', 'kernmodul', now()->subDay()->toDateTimeString());

    // Not merely hidden in the template: the prop is null, so there is no command in the
    // payload for a stray `v-if` to render. A snippet that answers 404 is worse than none.
    $this->actingAs($this->member)
        ->get("/c/acme/registries/{$this->group->id}/packages/{$package->id}")
        ->assertOk()
        ->assertInertia(fn ($p) => $p->component('portal/Package')
            ->where('in_force', false)
            ->where('install', null)
            ->etc());
});

// --- The registry's package list (plate 5) ---

it('gives every row of the package list the command for this registry', function () {
    installPackage($this->group, 'composer', 'acme/kernmodul');
    installPackage($this->group, 'python', 'zzz-analytics');

    // Ordered by name: acme/kernmodul, then zzz-analytics. Two types in one list, so no
    // single constant could satisfy both rows.
    $this->actingAs($this->member)
        ->get("/c/acme/registries/{$this->group->id}")
        ->assertOk()
        ->assertInertia(fn ($p) => $p->component('portal/Registry')
            ->has('packages', 2)
            ->where('packages.0.install', 'composer require acme/kernmodul')
            ->where('packages.1.install', 'pip install --index-url https://token:<token>@reg.example.test/r/acme/intern/simple/ zzz-analytics')
            ->etc());
});

it('carries no command at all on a lapsed row of the package list', function () {
    installPackage($this->group, 'python', 'aaa-live');
    installPackage($this->group, 'python', 'zzz-lapsed', now()->subDay()->toDateTimeString());

    // BOTH directions in one assertion. A page that withheld every command, or none, would
    // satisfy half of this on its own.
    $this->actingAs($this->member)
        ->get("/c/acme/registries/{$this->group->id}")
        ->assertOk()
        ->assertInertia(fn ($p) => $p->component('portal/Registry')
            ->has('packages', 2)
            ->where('packages.0.name', 'aaa-live')
            ->where('packages.0.install', 'pip install --index-url https://token:<token>@reg.example.test/r/acme/intern/simple/ aaa-live')
            ->where('packages.1.name', 'zzz-lapsed')
            ->where('packages.1.in_force', false)
            ->where('packages.1.install', null)
            ->etc());
});

it('tags the docker rows of a package list from one query, newest tag per repository', function () {
    $first = installPackage($this->group, 'docker', 'aaa-app');
    $second = installPackage($this->group, 'docker', 'zzz-app');

    foreach ([[$first, '2.0.0'], [$second, '3.1.0']] as [$package, $tag]) {
        pushTag($package, '0.9.0', now()->subDay()->toDateTimeString());
        pushTag($package, $tag, now()->toDateTimeString());
    }

    // Two repositories, different newest tags: a lookup that leaked one repository's tag
    // into the other's row would show up here and nowhere else.
    $this->actingAs($this->member)
        ->get("/c/acme/registries/{$this->group->id}")
        ->assertOk()
        ->assertInertia(fn ($p) => $p->component('portal/Registry')
            ->where('packages.0.install', 'docker pull reg.example.test/acme/intern/aaa-app:2.0.0')
            ->where('packages.1.install', 'docker pull reg.example.test/acme/intern/zzz-app:3.1.0')
            ->etc());
});
