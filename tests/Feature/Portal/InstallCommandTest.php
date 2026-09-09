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
use App\Models\RegistryToken;
use App\Models\User;
use App\Services\Registry\SetupSnippetBuilder;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;

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

it('keeps the scheme and the port of an instance that is not https on :443', function () {
    // F1. The URL used to be re-spelled as `'https://'.host().pathPrefix()`, which hardcoded
    // the scheme and dropped the port (parse_url's PHP_URL_HOST has no port in it). On this
    // very ordinary development instance the ONE response then carried three spellings of one
    // registry: `https://…@localhost/…` here, `http://localhost:8099/…` in the pip.conf line
    // of the setup tab beside it, and `localhost:8099/…` in the Docker command. Two of them
    // point nowhere, and the reader has no way to tell which.
    //
    // Nothing else in this file covers it: beforeEach pins `https://reg.example.test`, where
    // a hardcoded scheme and a dropped :443 are both invisible.
    config(['app.url' => 'http://localhost:8099']);

    expect(app(SetupSnippetBuilder::class)->installCommand($this->group, PackageType::Python, 'kernmodul'))
        ->toBe('pip install --index-url http://token:<token>@localhost:8099/r/acme/intern/simple/ kernmodul');
});

it('spells that url exactly as the setup instructions beside it spell it', function () {
    // The other half of F1, and the reason the two are one method: the pip.conf line and the
    // package page's command have to name the same index. Asserted as whole strings, because
    // the defect was a difference of scheme and port inside a URL that otherwise matched.
    config(['app.url' => 'http://localhost:8099']);

    $builder = app(SetupSnippetBuilder::class);

    expect($builder->for($this->group)['pip'])->toBe(
        "pip install --index-url http://token:<token>@localhost:8099/r/acme/intern/simple/ <paket>\n\n"
        ."# oder dauerhaft — Token in ~/.netrc (chmod 600), nicht in pip.conf:\n"
        ."# ~/.config/pip/pip.conf:\n[global]\nindex-url = http://localhost:8099/r/acme/intern/simple/\n\n"
        ."# ~/.netrc:\nmachine localhost\n  login token\n  password <token>"
    )->and($builder->installCommand($this->group, PackageType::Docker, 'meinapp', '1.0'))
        // The Docker command already kept the port. It is listed here so the three spellings
        // that used to disagree are pinned in one place.
        ->toBe('docker pull localhost:8099/acme/intern/meinapp:1.0');
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

it('pulls the most recently re-pointed tag into the package pages docker command', function () {
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

it('prefers a tag literally named latest over a newer one, on both surfaces', function () {
    // F5. The untagged form this command prints for an EMPTY repository is
    // `docker pull <host>/<repo>`, which Docker resolves as `:latest`. A repository that has
    // a `latest` and is nevertheless advertised as `:1.4.0` makes one page say two different
    // things about the same default, for a reason no reader can see.
    //
    // `1.4.0` is the newer row here, so a pure `updated_at` ordering fails this outright.
    $package = installPackage($this->group, 'docker', 'meinapp');
    pushTag($package, 'latest', now()->subDay()->toDateTimeString());
    pushTag($package, '1.4.0', now()->toDateTimeString());

    $this->actingAs($this->member)
        ->get("/c/acme/registries/{$this->group->id}/packages/{$package->id}")
        ->assertOk()
        ->assertInertia(fn ($p) => $p->component('portal/Package')
            ->where('install', 'docker pull reg.example.test/acme/intern/meinapp:latest')
            ->etc());

    // The list resolves tags for every Docker row in one query, with the ordering mirrored so
    // that last-row-wins lands on the same tag. Two implementations, so two assertions.
    $this->actingAs($this->member)
        ->get("/c/acme/registries/{$this->group->id}")
        ->assertOk()
        ->assertInertia(fn ($p) => $p->component('portal/Registry')
            ->where('packages.0.install', 'docker pull reg.example.test/acme/intern/meinapp:latest')
            ->etc());
});

it('breaks a tie between two tags written in the same second, on both surfaces', function () {
    // F5, the inert half. Every other Docker fixture in this file gives its two tags DIFFERENT
    // `updated_at` values, so `name` never decides anything and flipping its direction left
    // the whole suite green. These two share a timestamp to the second — the ordinary outcome
    // of one `docker push` of a multi-tag build — so `name` is the only clause left to answer,
    // and the answer is pinned.
    $sameSecond = now()->toDateTimeString();
    $package = installPackage($this->group, 'docker', 'meinapp');
    pushTag($package, '1.2.0', $sameSecond);
    pushTag($package, '1.4.0', $sameSecond);

    $this->actingAs($this->member)
        ->get("/c/acme/registries/{$this->group->id}/packages/{$package->id}")
        ->assertOk()
        ->assertInertia(fn ($p) => $p->component('portal/Package')
            ->where('install', 'docker pull reg.example.test/acme/intern/meinapp:1.4.0')
            ->etc());

    $this->actingAs($this->member)
        ->get("/c/acme/registries/{$this->group->id}")
        ->assertOk()
        ->assertInertia(fn ($p) => $p->component('portal/Registry')
            ->where('packages.0.install', 'docker pull reg.example.test/acme/intern/meinapp:1.4.0')
            ->etc());
});

it('sends the package page a docker repository tags, because it has no versions to send', function () {
    // F3. `package_versions` is EMPTY for every Docker repository — the OCI push path writes an
    // `oci_tags` row and never a version row — so the page's list section rendered "Noch keine
    // Versionen verfügbar." under every repository on the instance, however many tags it held.
    // Plate 4 puts the tag table there instead.
    $package = installPackage($this->group, 'docker', 'meinapp');
    pushTag($package, '1.2.0', now()->subDay()->toDateTimeString());
    pushTag($package, '1.4.0', now()->toDateTimeString());

    $this->actingAs($this->member)
        ->get("/c/acme/registries/{$this->group->id}/packages/{$package->id}")
        ->assertOk()
        ->assertInertia(fn ($p) => $p->component('portal/Package')
            ->has('versions', 0)
            // Newest first, so the tag the pull command above the table names is its first row.
            ->has('tags', 2)
            ->where('tags.0.name', '1.4.0')
            ->where('tags.1.name', '1.2.0')
            ->etc());
});

it('names one and the same tag in the pull command and in the table\'s first row', function () {
    // THE TWO SURFACES OF ONE ORDERING, ASSERTED AGAINST EACH OTHER. `showPackage()` builds a
    // pull command from ONE tag and renders a table of ALL of them directly above it, and the
    // page claims (in the payload's own comment) that the tag the command names is the table's
    // first row. Those were two independently written `order by` clauses, and they drifted the
    // moment the `latest` preference was added to only one of them: the command read `:latest`
    // while the first row read `1.4.0`.
    //
    // `latest` here is the OLDER row, so any ordering that reads `updated_at` alone puts
    // `1.4.0` first and fails. Neither value is compared with a literal: the point is that the
    // two surfaces AGREE, so this reddens for any future ordering that stops being shared, in
    // whichever direction it drifts.
    $package = installPackage($this->group, 'docker', 'meinapp');
    pushTag($package, 'latest', now()->subDay()->toDateTimeString());
    pushTag($package, '1.4.0', now()->toDateTimeString());

    $props = null;
    $this->actingAs($this->member)
        ->get("/c/acme/registries/{$this->group->id}/packages/{$package->id}")
        ->assertOk()
        ->assertInertia(function (AssertableInertia $page) use (&$props) {
            $page->component('portal/Package');
            $props = $page->toArray()['props'];
        });

    // The reference is the command's last path segment (`meinapp:latest`); its tag is whatever
    // follows the colon. A command printed in the untagged form has no colon there, which
    // leaves $commandTag null — asserted, so the "agreement" cannot be reached by printing no
    // tag at all on a repository that has two.
    $reference = Str::afterLast($props['install'], '/');
    $commandTag = str_contains($reference, ':') ? Str::afterLast($reference, ':') : null;

    expect($commandTag)->not->toBeNull();
    expect($props['tags'][0]['name'])->toBe($commandTag);
});

it('renders both of the registry pages relative timestamps in German', function () {
    // The two surfaces `Portal\RegistryController` adds — the Docker tag table's `updated_at`
    // and the token list's `last_used_at` — call `diffForHumans()`, and `app.locale` is `en`,
    // so both rendered "3 days ago" and "2 hours ago" inside a German page. The fix is not at
    // these two call sites: Carbon's locale is set to `de` once in `AppServiceProvider`, for
    // the ~18 call sites across the console that were all wrong for the same reason.
    //
    // Pinned as whole values rather than as "contains 'vor'", so a locale reverted to `en`
    // reddens here rather than degrading quietly on a page nobody asserts.
    $package = installPackage($this->group, 'docker', 'meinapp');
    pushTag($package, '1.4.0', now()->subDays(3)->toDateTimeString());

    RegistryToken::factory()->for($this->org)->for($this->group)->create([
        'name' => 'ci-token',
        'user_id' => $this->member->id,
        'last_used_at' => now()->subHours(2),
    ]);

    $this->actingAs($this->member)
        ->get("/c/acme/registries/{$this->group->id}/packages/{$package->id}")
        ->assertOk()
        ->assertInertia(fn ($p) => $p->component('portal/Package')
            ->where('tags.0.updated_at', 'vor 3 Tagen')
            ->etc());

    $this->actingAs($this->member)
        ->get("/c/acme/registries/{$this->group->id}")
        ->assertOk()
        ->assertInertia(fn ($p) => $p
            ->where('tokens.0.name', 'ci-token')
            ->where('tokens.0.last_used_at', 'vor 2 Stunden')
            ->etc());
});

it('sends no tags for a type that cannot have any', function () {
    // Empty rather than absent, so the page's own branch is the only thing deciding which list
    // it renders — and a guaranteed-empty query is not run for the three types that have none.
    $package = installPackage($this->group, 'python', 'kernmodul');

    $this->actingAs($this->member)
        ->get("/c/acme/registries/{$this->group->id}/packages/{$package->id}")
        ->assertOk()
        ->assertInertia(fn ($p) => $p->component('portal/Package')->has('tags', 0)->etc());
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
