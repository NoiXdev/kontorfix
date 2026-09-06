<?php

use Tests\E2E\Support\E2eStack;

/**
 * `docker push`/`docker pull` against the OCI registry at the domain-access root
 * (`127.0.0.1:8099` — see database/seeders/E2eSeeder.php for the seeded `domains` row and
 * the Docker repository ManifestController/BlobController need to already exist).
 *
 * Unlike the other three ecosystems, `client-docker` (docker/compose.e2e.yaml) is not a
 * container running its OWN Docker daemon (docker-in-docker): it is a bare `docker` CLI
 * with the HOST's socket bind-mounted in, so every command below is actually executed by
 * the SAME daemon `bin/e2e` itself already runs `docker compose` against — Docker Desktop's
 * daemon on a developer machine, or the runner's daemon in CI. This is what makes obstacle
 * #1 (Docker refuses plaintext HTTP to a foreign registry) a non-issue here without any
 * `insecure-registries` configuration: the push/pull request leaves from that daemon's own
 * network stack, addressed to `127.0.0.1:8099` — the exact loopback address `app` already
 * publishes on, and the one plaintext exception every Docker daemon hardcodes — exactly as
 * if a developer had typed the identical command in a host shell. The corollary: images and
 * layers built or pulled here are NOT cleaned up by `bin/e2e`'s teardown() (which only tears
 * down this compose project, never the shared daemon's image store) — the afterAll() below
 * removes only the specific tags this file creates.
 *
 * Obstacle #2 (a Docker client sends `Host: 127.0.0.1:8099`, but `/v2/` is registered only
 * in the domain-access route group — routes/registry.php) turned out to need no code
 * change: `Illuminate\Http\Request::getHost()` (Symfony underneath) already strips a
 * trailing `:<port>` before ResolveRegistryContext looks the value up, so the seeded
 * `domains` row carries the bare hostname `127.0.0.1`. See E2eSeeder's own comment on that
 * row, and tests/Feature/Registry/CustomDomainTest.php for the normal-suite Pest test that
 * pins the stripping behaviour directly.
 */
function dockerRef(string $tag): string
{
    $context = E2eStack::context();

    return "{$context['docker_host']}/{$context['docker_repository']}:{$tag}";
}

/**
 * A fresh DOCKER_CONFIG directory per script rather than the container's default
 * `~/.docker` — client-docker is one long-lived container reused across every test in this
 * file, so without this a credential `docker login` writes for one test would still be
 * sitting there for the next. The refusal test in particular needs a directory that has
 * never seen a credential at all.
 */
function dockerConfigDir(string $name): string
{
    return "/work/docker-config-{$name}";
}

/**
 * The one assertion no client-facing endpoint can make: how many blobs this organization
 * actually holds. Deliberately NOT black-box, unlike every other helper in
 * tests/E2E/Support/E2eStack.php — there is no `/v2/` catalog of an organization's total
 * blob count, and the claim the fourth test below exists to check (the shared base layer is
 * stored exactly once, not once per image that references it) can only be read off the
 * storage layer itself. `app` is a compose service like any other; E2eStack::exec() works
 * against it exactly as it does against a client container.
 */
function dockerBlobCount(): int
{
    $script = <<<'PHP'
        php artisan tinker --execute="
            \$org = App\Models\Organization::where('slug', 'e2e-customer')->firstOrFail();
            echo App\Models\OciBlob::where('organization_id', \$org->id)->count();
        " 2>/dev/null | tail -n 1
        PHP;

    $process = E2eStack::exec('app', $script, 60);

    return (int) trim($process->getOutput());
}

afterAll(function () {
    $refs = implode(' ', array_map('dockerRef', ['v1', 'dedup-a', 'dedup-b', 'big']));

    E2eStack::exec('client-docker', "docker rmi -f {$refs} >/dev/null 2>&1 || true", 60);
});

it('pushes an image with the real docker client', function () {
    $context = E2eStack::context();
    $ref = dockerRef('v1');
    $config = dockerConfigDir('push');

    // scratch + a single freshly generated random file: no base image to pull, so this
    // depends on nothing outside the stack (see UpstreamTest.php's docblock for why that
    // property matters to the default run of every ecosystem here).
    $script = <<<SH
        set -e
        rm -rf /work/push && mkdir -p /work/push && cd /work/push
        cp /fixtures/docker-image/Dockerfile.solo Dockerfile
        dd if=/dev/urandom of=payload.bin bs=1024 count=64 2>/dev/null

        mkdir -p {$config}
        echo '{$context['publish_token']}' | DOCKER_CONFIG={$config} docker login {$context['docker_host']} -u x --password-stdin

        DOCKER_CONFIG={$config} docker build --provenance=false --sbom=false -t {$ref} .
        DOCKER_CONFIG={$config} docker push {$ref}
        SH;

    $process = E2eStack::exec('client-docker', $script, 300);

    expect($process->isSuccessful())->toBeTrue($process->getErrorOutput())
        ->and($process->getOutput())->toMatch('/digest: sha256:[0-9a-f]{64}/');

    preg_match('/digest: (sha256:[0-9a-f]{64})/', $process->getOutput(), $matches);

    // Not just "some manifest exists for this tag": the exact digest docker reported for
    // the push must be the exact digest the registry now serves that tag under.
    expect(E2eStack::ociManifestDigest($context['docker_repository'], 'v1', $context['read_token']))
        ->toBe($matches[1]);
});

it('pulls it back into a clean local store and the digest matches', function () {
    $context = E2eStack::context();
    $ref = dockerRef('v1');
    $config = dockerConfigDir('pull');

    // Read off the registry directly rather than carried over from the push test's own
    // in-memory state: the two tests share only what the registry itself now holds, the
    // same way NpmTest.php's install test never reuses anything the publish test computed.
    $registryDigest = E2eStack::ociManifestDigest($context['docker_repository'], 'v1', $context['read_token']);
    expect($registryDigest)->not->toBeNull();

    $script = <<<SH
        set -e
        mkdir -p {$config}
        echo '{$context['read_token']}' | DOCKER_CONFIG={$config} docker login {$context['docker_host']} -u x --password-stdin

        # A clean local store: remove whatever the push test's own build left behind, so
        # this pull genuinely has to fetch from the registry rather than resolving
        # instantly against an image that was never gone from the local daemon.
        docker rmi -f {$ref} >/dev/null 2>&1 || true

        DOCKER_CONFIG={$config} docker pull {$ref}
        SH;

    $process = E2eStack::exec('client-docker', $script, 300);

    expect($process->isSuccessful())->toBeTrue($process->getErrorOutput());

    preg_match('/[Dd]igest: (sha256:[0-9a-f]{64})/', $process->getOutput(), $matches);

    expect($matches[1] ?? null)->toBe($registryDigest);
});

it('refuses an anonymous pull with 401 and pulls nothing', function () {
    $context = E2eStack::context();
    $ref = dockerRef('v1');
    $config = dockerConfigDir('anon');

    // Half one: the server said 401. A failing client command alone proves nothing — a
    // typo in the reference fails identically.
    $anonymous = E2eStack::getAtHostRoot("/v2/{$context['docker_repository']}/manifests/v1");
    expect($anonymous['status'])->toBe(401);

    // Half two: nothing landed locally. A directory that has never held a credential (not
    // merely a logged-out one — see dockerConfigDir()'s own docblock) and the local image
    // removed first, so neither a stored token nor a cache hit from the pull test above
    // can rescue an anonymous pull that should fail outright.
    $script = <<<SH
        rm -rf {$config} && mkdir -p {$config}
        docker rmi -f {$ref} >/dev/null 2>&1 || true
        DOCKER_CONFIG={$config} docker pull {$ref} >/dev/null 2>&1 || true
        docker image inspect {$ref} >/dev/null 2>&1 && echo PRESENT || echo ABSENT
        SH;

    $process = E2eStack::exec('client-docker', $script, 120);

    // Equality on the final line, not a substring match on the whole output — same
    // reasoning NpmTest.php's sibling refusal test spells out.
    $lines = array_values(array_filter(
        array_map('trim', explode("\n", $process->getOutput())),
        fn (string $line): bool => $line !== '',
    ));

    expect(end($lines))->toBe('ABSENT');
});

it('transfers no layer bytes when a second image shares a base layer', function () {
    $context = E2eStack::context();
    $refA = dockerRef('dedup-a');
    $refB = dockerRef('dedup-b');
    $config = dockerConfigDir('dedup');

    $countBeforeA = dockerBlobCount();

    // Dockerfile.a and Dockerfile.b both COPY the exact same `base.bin` from this one build
    // context, first — a byte-for-byte identical layer between the two images — before
    // their own distinct file. See the fixtures' own docblocks.
    //
    // --provenance=false --sbom=false: modern buildx attaches a provenance/SBOM
    // attestation to a pushed image by default, which lands as its own extra manifest and
    // blob — confirmed against this exact test, which failed with one blob more than
    // expected per image until these flags were added. Disabled here because it would
    // make the blob-count arithmetic below depend on buildx's own attestation format
    // rather than on anything this task's deduplication claim is actually about.
    $prepareAndPushA = <<<SH
        set -e
        rm -rf /work/dedup && mkdir -p /work/dedup && cd /work/dedup
        dd if=/dev/urandom of=base.bin bs=1024 count=256 2>/dev/null
        echo image-a > unique-a.txt
        echo image-b > unique-b.txt
        cp /fixtures/docker-image/Dockerfile.a Dockerfile.a
        cp /fixtures/docker-image/Dockerfile.b Dockerfile.b

        mkdir -p {$config}
        echo '{$context['publish_token']}' | DOCKER_CONFIG={$config} docker login {$context['docker_host']} -u x --password-stdin

        DOCKER_CONFIG={$config} docker build --provenance=false --sbom=false -f Dockerfile.a -t {$refA} .
        DOCKER_CONFIG={$config} docker push {$refA}
        SH;

    $processA = E2eStack::exec('client-docker', $prepareAndPushA, 300);
    expect($processA->isSuccessful())->toBeTrue($processA->getErrorOutput());

    $countAfterA = dockerBlobCount();

    $pushB = <<<SH
        set -e
        cd /work/dedup
        DOCKER_CONFIG={$config} docker build --provenance=false --sbom=false -f Dockerfile.b -t {$refB} .
        DOCKER_CONFIG={$config} docker push {$refB}
        SH;

    $processB = E2eStack::exec('client-docker', $pushB, 300);
    expect($processB->isSuccessful())->toBeTrue($processB->getErrorOutput());

    // Docker's own report: the shared base layer was recognised as already present on the
    // registry and never re-uploaded.
    expect($processB->getOutput())->toContain('Layer already exists');

    $countAfterB = dockerBlobCount();

    // Registry side, not merely what the client printed: image A adds its two layers plus
    // its config (three new blobs); image B must add only its own unique layer plus its
    // own config (two), never a second copy of the shared base layer. That is the actual
    // claim deduplication makes, checked against the data model itself rather than trusting
    // that the client only printed "Layer already exists" because it felt like it.
    expect($countAfterA - $countBeforeA)->toBe(3)
        ->and($countAfterB - $countAfterA)->toBe(2);
});

/**
 * Spec §4's 500 MiB gate — a condition of Task 7 being done, not a footnote. Off by
 * default because building, pushing and pulling ~520 MiB of incompressible data is slow;
 * gated behind an environment flag exactly the way UpstreamTest.php gates its own tests,
 * with a stated skip reason so a silently vanished test cannot be confused with a deleted
 * one. Run it with `E2E_LARGE_LAYER=1 bin/e2e`.
 */
it('pushes and pulls a layer above 500 MiB with the digest intact', function () {
    if (getenv('E2E_LARGE_LAYER') !== '1') {
        test()->markTestSkipped(
            'E2E_LARGE_LAYER is not set — run `E2E_LARGE_LAYER=1 bin/e2e` to include the '
            .'500 MiB layer gate (spec §4).'
        );
    }

    $context = E2eStack::context();
    $ref = dockerRef('big');
    $config = dockerConfigDir('big');

    $script = <<<SH
        set -e
        rm -rf /work/big && mkdir -p /work/big && cd /work/big
        cp /fixtures/docker-image/Dockerfile.solo Dockerfile
        # Incompressible: /dev/urandom, not /dev/zero — a zero-filled "large" layer
        # compresses away to almost nothing and would prove nothing about actually moving
        # this many bytes through the blob upload/download path.
        dd if=/dev/urandom of=payload.bin bs=1M count=520 2>/dev/null

        mkdir -p {$config}
        echo '{$context['publish_token']}' | DOCKER_CONFIG={$config} docker login {$context['docker_host']} -u x --password-stdin

        DOCKER_CONFIG={$config} docker build --provenance=false --sbom=false -t {$ref} .
        DOCKER_CONFIG={$config} docker push {$ref}
        docker rmi -f {$ref}
        DOCKER_CONFIG={$config} docker pull {$ref}
        SH;

    $process = E2eStack::exec('client-docker', $script, 900);

    expect($process->isSuccessful())->toBeTrue($process->getErrorOutput());

    preg_match_all('/[Dd]igest: (sha256:[0-9a-f]{64})/', $process->getOutput(), $matches);

    // Two digest lines — one from the push, one from the pull — and they must agree.
    expect($matches[1])->toHaveCount(2)
        ->and($matches[1][0])->toBe($matches[1][1]);
});
