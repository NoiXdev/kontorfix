<?php

use App\Jobs\SyncPackage;
use App\Models\Group;
use App\Models\Organization;
use App\Models\Package;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use Tests\Support\FixtureRepo;

it('builds the zip lazily, stores it on the artifacts disk and streams it', function () {
    Storage::fake('artifacts');
    $pkg = Package::factory()->create(['name' => 'acme/demo', 'repository_url' => 'file://'.FixtureRepo::make()]);
    (new SyncPackage($pkg))->handle();
    $group = Group::factory()->for(Organization::factory())->create(['slug' => 'kadenz']);
    $group->packages()->attach($pkg);

    $res = $this->withHeaders(tokenHeaderFor($group))->get('/r/kadenz/dists/acme/demo/1.0.0.0.zip');

    $sha = $pkg->versions()->where('version', '1.0.0.0')->first()->source_reference;
    $res->assertOk()->assertHeader('content-type', 'application/zip');
    Storage::disk('artifacts')->assertExists("dists/{$pkg->id}/{$sha}.zip");
});

it('serves the cached zip on the second request without rebuilding', function () {
    Storage::fake('artifacts');
    $pkg = Package::factory()->create(['name' => 'acme/demo', 'repository_url' => 'file://'.FixtureRepo::make()]);
    (new SyncPackage($pkg))->handle();
    $group = Group::factory()->for(Organization::factory())->create(['slug' => 'kadenz']);
    $group->packages()->attach($pkg);
    $headers = tokenHeaderFor($group);

    $this->withHeaders($headers)->get('/r/kadenz/dists/acme/demo/1.0.0.0.zip')->assertOk();
    // Second request: dist_path is set, no rebuild needed
    $this->withHeaders($headers)->get('/r/kadenz/dists/acme/demo/1.0.0.0.zip')->assertOk();

    $sha = $pkg->versions()->where('version', '1.0.0.0')->first()->source_reference;
    expect($pkg->versions()->where('version', '1.0.0.0')->first()->dist_path)
        ->toBe("dists/{$pkg->id}/{$sha}.zip");
});

it('rebuilds the dist when a tag was force-pushed to a new commit', function () {
    Storage::fake('artifacts');
    $fixture = FixtureRepo::make();
    $pkg = Package::factory()->create(['name' => 'acme/demo', 'repository_url' => 'file://'.$fixture]);
    (new SyncPackage($pkg))->handle();
    $group = Group::factory()->for(Organization::factory())->create(['slug' => 'kadenz']);
    $group->packages()->attach($pkg);
    $headers = tokenHeaderFor($group);

    $this->withHeaders($headers)->get('/r/kadenz/dists/acme/demo/1.0.0.0.zip')->assertOk();
    $oldSha = $pkg->versions()->where('version', '1.0.0.0')->first()->source_reference;
    Storage::disk('artifacts')->assertExists("dists/{$pkg->id}/{$oldSha}.zip");

    // Real force-push: same tag, new commit.
    $git = fn (string $cmd) => Process::path($fixture)->run($cmd)->throw();
    $git('git -c user.email=t@t -c user.name=t commit --allow-empty -m forcepush');
    $git('git tag -f v1.0.0');
    (new SyncPackage($pkg))->handle();

    $newSha = $pkg->versions()->where('version', '1.0.0.0')->first()->source_reference;
    expect($newSha)->not->toBe($oldSha);

    $this->withHeaders($headers)->get('/r/kadenz/dists/acme/demo/1.0.0.0.zip')->assertOk();

    // New SHA path is built and served — no stale delivery.
    Storage::disk('artifacts')->assertExists("dists/{$pkg->id}/{$newSha}.zip");
    expect($pkg->versions()->where('version', '1.0.0.0')->first()->dist_path)
        ->toBe("dists/{$pkg->id}/{$newSha}.zip");
});

it('waits out a briefly busy mirror lock instead of failing the download', function () {
    // The scenario the mirror lock was added for, seen from the caller that has no retry
    // behind it: two cold versions of one package requested in parallel take two different
    // dist locks, so both reach GitRepository::sync() and one of them has to wait. An
    // aborted sync used to leave ComposerController::dist() with an uncaught
    // RuntimeException, i.e. an HTTP 500 and a failed `composer install`. A holder that is
    // about to finish — a fetch, or a clone in its last seconds — must be waited out and
    // the archive served.
    //
    // Two packages built from the same fixture, so the *only* difference between the two
    // requests is the held lock. Measuring one request against a 1.0s threshold cannot tell
    // "it blocked for a second" from "the request took a second", and on a loaded machine
    // the second is not rare — the control is what makes the assertion mean anything.
    Storage::fake('artifacts');
    $group = Group::factory()->for(Organization::factory())->create(['slug' => 'kadenz']);
    $headers = tokenHeaderFor($group);

    $make = function (string $name) use ($group) {
        $pkg = Package::factory()->create(['name' => $name, 'repository_url' => 'file://'.FixtureRepo::make()]);
        (new SyncPackage($pkg))->handle();
        $group->packages()->attach($pkg);

        return $pkg;
    };

    $control = $make('acme/control');
    $contended = $make('acme/demo');

    $started = microtime(true);
    $this->withHeaders($headers)->get('/r/kadenz/dists/acme/control/1.0.0.0.zip')->assertOk();
    $controlElapsed = microtime(true) - $started;

    // Someone else is mid-sync on this mirror and lets go on their own, exactly as a
    // finishing clone would. Modelled with an expiring TTL because a second sync running
    // concurrently in this process is the thing being ruled out, not something to arrange.
    // Taken immediately before the clock so the TTL is not spent on setup.
    expect(Cache::lock('mirror:'.$contended->id, 1, 'someone-else')->get())->toBeTrue();

    $started = microtime(true);
    $res = $this->withHeaders($headers)->get('/r/kadenz/dists/acme/demo/1.0.0.0.zip');
    $contendedElapsed = microtime(true) - $started;

    $res->assertOk()->assertHeader('content-type', 'application/zip');
    // It really blocked for the holder rather than racing past it: the same work, plus most
    // of a second-long lock.
    expect($contendedElapsed)->toBeGreaterThan($controlElapsed + 0.8);

    $sha = $contended->versions()->where('version', '1.0.0.0')->first()->source_reference;
    Storage::disk('artifacts')->assertExists("dists/{$contended->id}/{$sha}.zip");
});

it('answers 503 with Retry-After, on the web budget, when the mirror lock stays busy', function () {
    // The regression that made this split necessary. A request blocked on the mirror lock
    // occupies one thread of the FrankenPHP pool that serves the entire registry — the
    // healthcheck `/up` included — so the web caller must NOT be given the queue caller's
    // wait, which is sized to outlast a full `git clone --mirror`. A handful of parallel
    // requests for cold versions would otherwise park every thread for minutes and take the
    // container out of the proxy's rotation.
    //
    // The two waits are set far apart here for exactly that reason: if dist() ever stops
    // passing its own budget to sync(), this request takes the queue's wait instead and the
    // elapsed assertion catches it. That is the whole point of the test — the status code
    // alone would still be 503.
    config(['kontorfix.mirror_lock_wait' => 8, 'kontorfix.mirror_lock_wait_web' => 1]);

    // ServiceUnavailableHttpException is an HttpException, so it sits in Laravel's
    // $internalDontReport and is silent by default — unlike the 500 it replaced, which
    // was reported. A spy rather than a strict mock: it must not choke on unrelated log
    // calls elsewhere in the request, only confirm this one happened.
    Log::spy();

    Storage::fake('artifacts');
    $pkg = Package::factory()->create(['name' => 'acme/demo', 'repository_url' => 'file://'.FixtureRepo::make()]);
    (new SyncPackage($pkg))->handle();
    $group = Group::factory()->for(Organization::factory())->create(['slug' => 'kadenz']);
    $group->packages()->attach($pkg);

    // Held for the whole request and deliberately never released: a clone that is nowhere
    // near finishing, which is the case the web caller must refuse rather than wait for.
    expect(Cache::lock('mirror:'.$pkg->id, 900, 'someone-else')->get())->toBeTrue();

    $started = microtime(true);
    $res = $this->withHeaders(tokenHeaderFor($group))->get('/r/kadenz/dists/acme/demo/1.0.0.0.zip');
    $elapsed = microtime(true) - $started;

    // 503 rather than 500: the package is fine, the archive will exist shortly, and the
    // client is told when to come back instead of being told the request can never succeed.
    $res->assertStatus(503)->assertHeader('Retry-After');
    expect($elapsed)->toBeLessThan(4.0);

    // The signal that would show pool saturation on a live instance — otherwise this
    // 503 leaves no trace anywhere, silent by construction (see the config above).
    Log::shouldHaveReceived('info')->once();

    // Nothing half-built was left behind, and no download was counted.
    $sha = $pkg->versions()->where('version', '1.0.0.0')->first()->source_reference;
    Storage::disk('artifacts')->assertMissing("dists/{$pkg->id}/{$sha}.zip");
    expect($pkg->versions()->where('version', '1.0.0.0')->first()->download_count)->toBe(0);
});

it('denies dist download without access', function () {
    Storage::fake('artifacts');
    $pkg = Package::factory()->create(['name' => 'acme/demo']);
    $group = Group::factory()->for(Organization::factory())->create(['slug' => 'kadenz']);
    // Package NOT assigned
    $this->withHeaders(tokenHeaderFor($group))
        ->get('/r/kadenz/dists/acme/demo/1.0.0.0.zip')->assertNotFound();
});

it('returns 404 for an unknown version of an assigned package', function () {
    Storage::fake('artifacts');
    $pkg = Package::factory()->create(['name' => 'acme/demo', 'repository_url' => 'file://'.FixtureRepo::make()]);
    (new SyncPackage($pkg))->handle();
    $group = Group::factory()->for(Organization::factory())->create(['slug' => 'kadenz']);
    $group->packages()->attach($pkg);

    $this->withHeaders(tokenHeaderFor($group))
        ->get('/r/kadenz/dists/acme/demo/9.9.9.0.zip')->assertNotFound();
});
