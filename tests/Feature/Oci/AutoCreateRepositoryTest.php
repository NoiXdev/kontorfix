<?php

use App\Enums\PackageSourceMode;
use App\Enums\PackageType;
use App\Enums\TokenAbility;
use App\Models\Domain;
use App\Models\Group;
use App\Models\Organization;
use App\Models\Package;
use App\Models\SystemSetting;
use App\Services\Oci\Digest;
use App\Services\Registry\OciSettings;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;

/**
 * Push-time repository creation: off by default, switched on instance-wide, narrowable per
 * organization — the same ceiling/narrowing relationship `enabled_registry_types` has.
 *
 * The push cases below address the registry by CUSTOM DOMAIN, the mode most of
 * tests/Feature/Oci uses; the last case repeats one of them through Task 1's path
 * addressing, because the setting is decided from `$group->organization` and must not turn
 * out to depend on how the caller found that group.
 */

/**
 * A Basic-auth Authorization header as $server vars, for the same reason
 * basicAuthServerVars() in BlobUploadTest.php is: the raw call() a push needs never reads
 * withHeaders()'s defaults. Named differently from that one and from PathAddressingTest's
 * pathAuth() because a Pest test file's top-level functions are global and would collide.
 *
 * @return array<string, string>
 */
function autoCreateAuth(Group $group, TokenAbility $ability = TokenAbility::Read): array
{
    return ['HTTP_AUTHORIZATION' => 'Basic '.base64_encode('x:'.tokenPlainTextFor($group, $ability))];
}

beforeEach(function () {
    Storage::fake('artifacts');

    $this->org = Organization::factory()->create([
        'slug' => 'kunde',
        'enabled_registry_types' => ['composer', 'npm', 'python', 'docker'],
    ]);
    $this->group = Group::factory()->for($this->org)->create(['slug' => 'intern']);
    Domain::create(['group_id' => $this->group->id, 'hostname' => 'push.test']);

    $this->publish = autoCreateAuth($this->group, TokenAbility::Publish);
    $this->read = autoCreateAuth($this->group);

    $this->bytes = 'ein-layer';
    $this->blobDigest = Digest::of($this->bytes);
});

/**
 * The monolithic blob upload a `docker push` starts with, aimed at the repository name
 * `neu`, which no case below registers up front — so this is the request the setting
 * decides. Domain mode; the path-mode case at the bottom writes its own URL out in full.
 *
 * Everything it needs is passed in rather than read back off `test()`, which static
 * analysis only sees as Pest's proxy and cannot type.
 *
 * @param  array<string, string>  $publish
 * @return TestResponse<Response>
 */
function pushBlobIntoNeu(array $publish, string $bytes): TestResponse
{
    return pushBlobIntoRepository($publish, 'neu', $bytes);
}

/**
 * The same request aimed at an arbitrary repository name, for the cases that have to compare
 * two names' answers against each other.
 *
 * @param  array<string, string>  $publish
 * @return TestResponse<Response>
 */
function pushBlobIntoRepository(array $publish, string $name, string $bytes): TestResponse
{
    return test()->withServerVariables($publish)
        ->call('POST', "http://push.test/v2/{$name}/blobs/uploads/?digest=".Digest::of($bytes), content: $bytes);
}

it('intersects the global setting with the organization rather than coalescing', function (bool $global, ?bool $org, bool $effective) {
    // The truth table. Row two is the one `$org->value ?? $global` gets wrong: an
    // organization may narrow the instance ceiling, never widen past it.
    SystemSetting::current()->update(['oci_auto_create_repositories' => $global]);
    $organization = Organization::factory()->create(['oci_auto_create_repositories' => $org]);

    expect(app(OciSettings::class)->autoCreateEnabledFor($organization))->toBe($effective);
})->with([
    'global off, org inherits' => [false, null, false],
    'global off, org on — may never widen past the ceiling' => [false, true, false],
    'global on, org inherits' => [true, null, true],
    'global on, org off' => [true, false, false],
    'global on, org on' => [true, true, true],
]);

it('falls back to the global setting when there is no organization to narrow it', function () {
    expect(app(OciSettings::class)->autoCreateEnabledFor(null))->toBeFalse();

    SystemSetting::current()->update(['oci_auto_create_repositories' => true]);

    expect(app(OciSettings::class)->autoCreateEnabledFor(null))->toBeTrue();
});

it('is off after a migration, so a push to an unknown name is still refused', function () {
    expect(SystemSetting::current()->oci_auto_create_repositories)->toBeFalse();

    $before = Package::count();

    $response = pushBlobIntoNeu($this->publish, $this->bytes)
        ->assertStatus(404)
        ->assertJsonPath('errors.0.code', 'NAME_UNKNOWN');

    // Both halves: the status AND that nothing was written behind it.
    expect(Package::count())->toBe($before)
        // The message names the setting. A bare "gibt es nicht" sends the one person who
        // can fix this in a checkbox to the logs instead.
        ->and($response->json('errors.0.message'))->toContain('Repositories beim Push anlegen');
});

it('refuses a repository name longer than the column that would store it, with an OCI error body', function () {
    // `$ociName` in routes/registry.php bounds the SHAPE of a repository name and not its
    // LENGTH, while `packages.name` is varchar(255). With push-time creation on, a name
    // above that reached Package::create() and raised SQLSTATE[22001] — an unrendered
    // QueryException, so an HTML 500 instead of the `errors[]` envelope every other refusal
    // on this path answers with, plus a stack trace per request in the log. That is exactly
    // the failure class routes/registry.php's own header says the `$uuid` constraint exists
    // to prevent, arriving through the one parameter that has no length bound.
    SystemSetting::current()->update(['oci_auto_create_repositories' => true]);

    $before = Package::count();

    $response = pushBlobIntoRepository($this->publish, str_repeat('a', 300), $this->bytes)
        ->assertStatus(400)
        ->assertJsonPath('errors.0.code', 'NAME_INVALID');

    expect(Package::count())->toBe($before)
        ->and($response->headers->get('Content-Type'))->toStartWith('application/json');
});

it('still creates a repository whose name exactly fills the column', function () {
    // The boundary from the other side, so the refusal above cannot drift into refusing
    // legitimate names: 255 characters is storable and must still be created.
    SystemSetting::current()->update(['oci_auto_create_repositories' => true]);

    $name = str_repeat('a', 255);

    pushBlobIntoRepository($this->publish, $name, $this->bytes)->assertStatus(201);

    expect(Package::where('name', $name)->where('type', PackageType::Docker)->exists())->toBeTrue();
});

it('refuses when the organization narrows a globally enabled setting', function () {
    SystemSetting::current()->update(['oci_auto_create_repositories' => true]);
    $this->org->update(['oci_auto_create_repositories' => false]);

    $before = Package::count();

    pushBlobIntoNeu($this->publish, $this->bytes)->assertStatus(404)->assertJsonPath('errors.0.code', 'NAME_UNKNOWN');

    expect(Package::count())->toBe($before);
});

it('refuses even when the organization switches on what the instance has switched off', function () {
    // Row two of the truth table, at the protocol rather than at the service: an
    // organization's own `true` under a globally disabled setting is inert.
    $this->org->update(['oci_auto_create_repositories' => true]);

    $before = Package::count();

    pushBlobIntoNeu($this->publish, $this->bytes)->assertStatus(404)->assertJsonPath('errors.0.code', 'NAME_UNKNOWN');

    expect(Package::count())->toBe($before);
});

it('creates the repository in the addressed organization and registry when allowed', function () {
    SystemSetting::current()->update(['oci_auto_create_repositories' => true]);

    pushBlobIntoNeu($this->publish, $this->bytes)
        ->assertStatus(201)
        ->assertHeader('Docker-Content-Digest', $this->blobDigest);

    $created = Package::where('name', 'neu')->sole();

    expect($created->type)->toBe(PackageType::Docker)
        // Load-bearing and easy to omit: a Docker row that ends up git-sourced is never
        // synced and says so only by staying empty.
        ->and($created->source_mode)->toBe(PackageSourceMode::Publish)
        ->and($created->organization_id)->toBe($this->org->id)
        // Attached to the registry the push addressed, or the very next request 404s.
        ->and($this->group->packages()->whereKey($created->id)->exists())->toBeTrue();
});

it('makes the created repository pullable by the very next request', function () {
    SystemSetting::current()->update(['oci_auto_create_repositories' => true]);
    $payload = '{"schemaVersion":2,"mediaType":"application/vnd.oci.image.manifest.v1+json","layers":[]}';
    $manifestDigest = Digest::of($payload);

    pushBlobIntoNeu($this->publish, $this->bytes)->assertStatus(201);

    $this->withServerVariables($this->publish + ['CONTENT_TYPE' => 'application/vnd.oci.image.manifest.v1+json'])
        ->call('PUT', 'http://push.test/v2/neu/manifests/1.0', content: $payload)
        ->assertStatus(201);

    $this->withServerVariables($this->read)
        ->get('http://push.test/v2/neu/manifests/1.0')
        ->assertOk()
        ->assertHeader('Docker-Content-Digest', $manifestDigest);
});

it('never creates a name another organization already holds, even when allowed', function () {
    SystemSetting::current()->update(['oci_auto_create_repositories' => true]);

    // The same name, owned elsewhere. Answering anything but NAME_UNKNOWN would let a
    // publish token discover foreign repository names by response code.
    $foreign = Organization::factory()->create(['enabled_registry_types' => ['docker']]);
    $foreignGroup = Group::factory()->for($foreign)->create();
    $held = Package::factory()->inOrgOf($foreignGroup)->create(['type' => PackageType::Docker, 'name' => 'neu']);
    $foreignGroup->packages()->attach($held);

    $before = Package::count();

    pushBlobIntoNeu($this->publish, $this->bytes)->assertStatus(404)->assertJsonPath('errors.0.code', 'NAME_UNKNOWN');

    expect(Package::count())->toBe($before);
});

it('creates the repository through the path address too', function () {
    // Task 1's second addressing mode. The setting hangs off the resolved group's
    // organization, so both modes have to reach the same decision.
    SystemSetting::current()->update(['oci_auto_create_repositories' => true]);

    $host = rtrim((string) config('app.url'), '/');

    $this->withServerVariables($this->publish)
        ->call('POST', "{$host}/v2/kunde/intern/neu/blobs/uploads/?digest={$this->blobDigest}", content: $this->bytes)
        ->assertStatus(201)
        // In the caller's own address space, or the client follows the Location into a 404.
        ->assertHeader('Location', "/v2/kunde/intern/neu/blobs/{$this->blobDigest}");

    $created = Package::where('name', 'neu')->sole();

    expect($created->organization_id)->toBe($this->org->id)
        // …and attached to the registry the path named, which domain mode proves separately
        // but this mode resolves the group through ResolveOciContext rather than a Domain row.
        ->and($this->group->packages()->whereKey($created->id)->exists())->toBeTrue();
});

/**
 * Plants a competing repository row exactly where a concurrent request's would land: after
 * this request's own "does anybody hold this name" lookups, before its INSERT.
 *
 * That gap is the concurrency the protocol produces on its own. A `docker push` uploads
 * layers CONCURRENTLY — `--max-concurrent-uploads`, 5 by default — so on a FIRST push
 * several `POST /v2/{name}/blobs/uploads/` requests run this resolution at the same time,
 * and `packages`' unique `(organization_id, type, name)` is what turns the loser into an
 * error rather than a duplicate row.
 *
 * Neither a second thread nor a second connection can stand in for that here: Pest runs one
 * thread, and RefreshDatabase keeps every fixture inside an uncommitted transaction, so a row
 * written over a second connection would have no organization to point its foreign key at.
 * The competitor is therefore written from a QUERY LISTENER rather than from a model event.
 * The listener fires on the last lookup before the write, which keeps the competitor on this
 * connection but OUTSIDE the transaction the write opens — which is what a real competitor's
 * committed row is. Written from `Package::creating` instead it would sit inside that
 * transaction and the savepoint rollback would take it away again, leaving nothing to have
 * collided with.
 *
 * @return callable(): ?Package the planted row, once the push has run
 */
function plantCompetingRepository(string $name, Group $into): callable
{
    $planted = null;

    DB::listen(function (QueryExecuted $query) use ($name, $into, &$planted): void {
        // "Does any organization hold this name" — the last query ResolvesOciRepository runs
        // before it opens the transaction that inserts.
        $isTheGap = str_contains($query->sql, 'exists') && in_array($name, $query->bindings, true);

        if ($planted !== null || ! $isTheGap) {
            return;
        }

        // Built and assigned BEFORE it is written, so the plant's own INSERTs find the guard
        // above already closed and cannot re-enter this listener.
        $planted = Package::factory()->inOrgOf($into)->make([
            'type' => PackageType::Docker,
            'source_mode' => PackageSourceMode::Publish,
            'name' => $name,
        ]);

        $planted->save();
        $into->packages()->attach($planted);
    });

    // A by-reference closure, not an arrow function: an arrow function would capture
    // `$planted` by value — as the null it still is at this point — and every assertion
    // written against it would pass without ever seeing the planted row.
    return function () use (&$planted): ?Package {
        return $planted;
    };
}

it('resolves to the winner when a competing insert lands between its own lookup and its own insert', function () {
    SystemSetting::current()->update(['oci_auto_create_repositories' => true]);

    $winner = plantCompetingRepository('neu', $this->group);

    // Unguarded, this is the 500 with an HTML body — not an OCI error document — that aborts
    // a real first push.
    pushBlobIntoNeu($this->publish, $this->bytes)
        ->assertStatus(201)
        ->assertHeader('Docker-Content-Digest', $this->blobDigest);

    // `sole()` is half the assertion: the loser must not have written a second row either.
    expect(Package::where('name', 'neu')->sole()->id)->toBe($winner()?->id);
});

it('still answers NAME_UNKNOWN when the winner is a sibling registry of the same organization', function () {
    // The other outcome of the same collision, and the reason losing the race may not simply
    // hand back whatever row won it: a concurrent push into a SIBLING registry of this
    // organization creates and attaches the name there. `(organization_id, type, name)` is
    // unique, so this push's INSERT still collides — but the winner is a repository this
    // caller may not write to, never having been assigned to the registry it addressed.
    SystemSetting::current()->update(['oci_auto_create_repositories' => true]);

    $sibling = Group::factory()->for($this->org)->create(['slug' => 'anderes']);
    $winner = plantCompetingRepository('neu', $sibling);

    pushBlobIntoNeu($this->publish, $this->bytes)
        ->assertStatus(404)
        ->assertJsonPath('errors.0.code', 'NAME_UNKNOWN');

    // Not pulled into the addressed registry as a consolation prize.
    expect($this->group->packages()->whereKey($winner()?->id)->exists())->toBeFalse();
});

it('is not blocked by a competing insert in another organization, which cannot collide', function () {
    // The scope of the constraint, stated as a test because the fix leans on it: uniqueness
    // is `(organization_id, type, name)`, so a creator in ANOTHER organization is not a winner
    // to defer to — organizations may legitimately share a name since
    // 2026_09_02_110000_enforce_package_organization.php. This push creates its own row, and
    // its 201 says nothing about the other organization a free name would not have said.
    SystemSetting::current()->update(['oci_auto_create_repositories' => true]);

    $foreign = Organization::factory()->create(['enabled_registry_types' => ['docker']]);
    $foreignGroup = Group::factory()->for($foreign)->create();
    $planted = plantCompetingRepository('neu', $foreignGroup);

    pushBlobIntoNeu($this->publish, $this->bytes)->assertStatus(201);

    $mine = Package::where('name', 'neu')->where('organization_id', $this->org->id)->sole();

    expect($mine->id)->not->toBe($planted()?->id)
        ->and($this->group->packages()->whereKey($mine->id)->exists())->toBeTrue();
});

it('inserts and attaches the created repository inside one transaction', function () {
    // The second window: a concurrent request whose lookup landed between the winner's
    // INSERT and its attach would find the package, fail the membership check and answer
    // NAME_UNKNOWN for a repository that exists. What closes it is that no other connection
    // ever observes that state — the INSERT and the attach commit together.
    //
    // A second connection cannot be used to prove that here (RefreshDatabase keeps this
    // test's fixtures in an uncommitted transaction of its own), so the two halves are
    // asserted from inside the gap instead: the state IS reached, and it is reached inside a
    // transaction this request opened.
    SystemSetting::current()->update(['oci_auto_create_repositories' => true]);

    $ambientTransactionLevel = DB::transactionLevel();
    $insideTheGap = null;

    Package::created(function (Package $package) use (&$insideTheGap) {
        $insideTheGap = [
            'level' => DB::transactionLevel(),
            'attached' => $this->group->packages()->whereKey($package->id)->exists(),
        ];
    });

    pushBlobIntoNeu($this->publish, $this->bytes)->assertStatus(201);

    expect($insideTheGap['attached'])->toBeFalse()
        ->and($insideTheGap['level'])->toBeGreaterThan($ambientTransactionLevel);
});

it('answers every unresolvable name identically while the setting is off', function () {
    // The claim the ordering of the two refusals exists to make true. With the setting off
    // there are three shapes of unresolvable name, and a publish token must not be able to
    // tell them apart by reading the body:
    //
    //   `neu`      — free, nobody holds it;
    //   `anderswo` — held by the CALLER'S OWN organization, not assigned to this registry;
    //   `fremd`    — held by another organization.
    //
    // The second one is the intra-organization channel: without the setting check running
    // first it answers with the plain "gibt es nicht" while a free name names the setting.
    expect(SystemSetting::current()->oci_auto_create_repositories)->toBeFalse();

    $ownedElsewhere = Package::factory()->inOrgOf($this->group)->create([
        'type' => PackageType::Docker,
        'source_mode' => PackageSourceMode::Publish,
        'name' => 'anderswo',
    ]);
    // Deliberately NOT attached to $this->group.
    expect($this->group->packages()->whereKey($ownedElsewhere->id)->exists())->toBeFalse();

    $foreign = Organization::factory()->create(['enabled_registry_types' => ['docker']]);
    $foreignGroup = Group::factory()->for($foreign)->create();
    $held = Package::factory()->inOrgOf($foreignGroup)->create([
        'type' => PackageType::Docker,
        'source_mode' => PackageSourceMode::Publish,
        'name' => 'fremd',
    ]);
    $foreignGroup->packages()->attach($held);

    // The repository name is in the message, so it is normalised out — the point is that
    // nothing ELSE differs.
    $answer = fn (string $name): array => (function (TestResponse $response) use ($name): array {
        return [
            'status' => $response->status(),
            'code' => $response->json('errors.0.code'),
            'message' => str_replace($name, '{name}', (string) $response->json('errors.0.message')),
        ];
    })(pushBlobIntoRepository($this->publish, $name, $this->bytes));

    expect($answer('anderswo'))->toBe($answer('neu'))
        ->and($answer('fremd'))->toBe($answer('neu'));
});
