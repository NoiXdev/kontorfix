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
 *
 * `--provenance=false --sbom=false` appears on exactly one test below (the shared-layer
 * dedup test), not on all of them: it exists there ONLY because that test needs an exact
 * blob-count delta, and a real buildx push attaches a provenance attestation as its own
 * extra manifest+blob by default — confirmed directly, since the dedup test failed with
 * one blob more than expected per image until those flags were added there. Everywhere
 * else in this file, a real buildx push is left to do whatever it does by default (in
 * practice, push an image INDEX carrying a provenance attestation), because that ambient
 * shape is real client behaviour worth covering, not an artifact to suppress. The last
 * test in this file goes further and FORCES an index with both provenance and an SBOM,
 * matching this project's own release workflow — gated behind E2E_ATTESTED_PUSH; see that
 * test's own docblock for why.
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
    $refs = implode(' ', array_map('dockerRef', ['v1', 'dedup-a', 'dedup-b', 'big', 'attest']));

    E2eStack::exec('client-docker', "docker rmi -f {$refs} >/dev/null 2>&1 || true", 60);

    // Safety net, not the primary cleanup path: the attested-push test removes its own
    // buildx builder (and the sibling buildkit container backing it) in its own body once
    // the build succeeds, but a failure partway through that test's script would leave
    // `e2e-attest-builder` — and the running `buildx_buildkit_e2e-attest-builder0`
    // container it owns — behind on the shared host daemon otherwise. A no-op when the
    // builder was never created (E2E_ATTESTED_PUSH unset, or the test never got far enough
    // to create it), same as the `docker rmi` above.
    E2eStack::exec('client-docker', 'docker buildx rm e2e-attest-builder >/dev/null 2>&1 || true', 60);
});

it('pushes an image with the real docker client', function () {
    $context = E2eStack::context();
    $ref = dockerRef('v1');
    $config = dockerConfigDir('push');

    // scratch + a single freshly generated random file: no base image to pull, so this
    // depends on nothing outside the stack (see UpstreamTest.php's docblock for why that
    // property matters to the default run of every ecosystem here).
    //
    // No --provenance=false/--sbom=false here, unlike the dedup test below: this is the
    // one place a real buildx push is left to do whatever it does by default, which in
    // practice attaches a provenance attestation and pushes an image INDEX, not a bare
    // manifest — the same shape this project's own release workflow pushes
    // (`provenance: mode=max`, `sbom: true`). ManifestTest.php already covers a
    // hand-written index payload; that proves ManifestController stores arbitrary bytes
    // under a digest, not that a real client's index-shaped push and a real pull actually
    // round-trip one. The assertions below don't care whether the pushed reference is a
    // plain manifest or an index — digest-in equals digest-out either way — so this test
    // stays green regardless of exactly what buildx's ambient default turns out to be on
    // any given Docker version, while still exercising whatever that default actually is.
    $script = <<<SH
        set -e
        rm -rf /work/push && mkdir -p /work/push && cd /work/push
        cp /fixtures/docker-image/Dockerfile.solo Dockerfile
        dd if=/dev/urandom of=payload.bin bs=1024 count=64 2>/dev/null

        mkdir -p {$config}
        echo '{$context['publish_token']}' | DOCKER_CONFIG={$config} docker login {$context['docker_host']} -u x --password-stdin

        DOCKER_CONFIG={$config} docker build -t {$ref} .
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

    // A matching digest alone is not proof that any bytes actually came off the registry:
    // `docker rmi -f` drops the image's tag reference, but not necessarily every layer's
    // content from the daemon's underlying (containerd-snapshotter) store, so a manifest
    // resolve followed by "every layer already exists" would still print a correct,
    // matching `Digest:` line even if `BlobController::show()` answered every blob request
    // with a 500 — pull would report each layer already present and never notice. "Pull
    // complete" (or "Download complete", printed just before it once bytes have actually
    // been received) only appears once a layer is genuinely fetched, which is exactly the
    // case here: the tag was removed immediately above, so the pull has nothing to resolve
    // against locally and must actually fetch. Verified by deliberately breaking
    // BlobController::show() to return 500 for every request: this test (and the 500 MiB
    // one below, which removes the local image the same way) both went red, rather than
    // quietly missing the regression the way an "always exists" pull would have.
    expect($process->getOutput())->toMatch('/Pull complete|Download complete/');

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
 * one. Run it with `bin/e2e --large-layer` (or `E2E_LARGE_LAYER=1 bin/e2e` directly).
 */
it('pushes and pulls a layer above 500 MiB with the digest intact', function () {
    if (getenv('E2E_LARGE_LAYER') !== '1') {
        test()->markTestSkipped(
            'E2E_LARGE_LAYER is not set — run `bin/e2e --large-layer` to include the '
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

        DOCKER_CONFIG={$config} docker build -t {$ref} .
        DOCKER_CONFIG={$config} docker push {$ref}
        docker rmi -f {$ref}
        DOCKER_CONFIG={$config} docker pull {$ref}
        SH;

    $process = E2eStack::exec('client-docker', $script, 900);

    expect($process->isSuccessful())->toBeTrue($process->getErrorOutput());

    // Same reasoning as the plain pull test above: a matching digest is not proof the 520
    // MiB layer's bytes were actually served, only that resolving the manifest and
    // whatever layers WERE fetched agree with what was pushed. The local image is removed
    // before this pull (same as the plain pull test), so the layer has nothing to resolve
    // against locally and this line only appears once it is genuinely re-downloaded.
    expect($process->getOutput())->toMatch('/Pull complete|Download complete/');

    preg_match_all('/[Dd]igest: (sha256:[0-9a-f]{64})/', $process->getOutput(), $matches);

    // Two digest lines — one from the push, one from the pull — and they must agree.
    expect($matches[1])->toHaveCount(2)
        ->and($matches[1][0])->toBe($matches[1][1]);
});

/**
 * Real-client coverage for an image INDEX carrying attestations — the shape this
 * project's own release workflow actually pushes (`provenance: mode=max`, `sbom: true`),
 * and the whole justification, per spec, for a registry supporting index media types at
 * all. ManifestTest.php's hand-written index payloads prove ManifestController stores and
 * serves arbitrary digest-addressed bytes; they do not prove a real `docker buildx` push
 * sequence that actually GENERATES an index (base image manifest + attestation manifest,
 * wrapped in an index) round-trips through this registry with a real `docker pull`.
 *
 * Gated behind E2E_ATTESTED_PUSH, for two honestly-stated reasons rather than one:
 *
 *   1. `--sbom=true` makes buildx pull `docker/buildkit-syft-scanner` from Docker Hub to
 *      generate the SBOM attestation — an external dependency the rest of this file's
 *      default run deliberately has none of (see UpstreamTest.php's own docblock on why
 *      the default run must depend on nothing outside the stack).
 *   2. Forcing a deterministic index+attestation shape needs the `docker-container` buildx
 *      driver with `--driver-opt network=host` (the default "docker" driver's ambient
 *      attestation behaviour — exercised unconditionally by the plain push/pull/large-layer
 *      tests above, deliberately left alone rather than disabled — is not something this
 *      test can rely on for a GUARANTEED index, since it is an implementation default that
 *      could change or differ between Docker versions). This was verified working
 *      end-to-end against this exact stack on a Docker Desktop daemon (see
 *      task-7-report.md), but this session had no way to confirm the identical
 *      `docker-container` + `network=host` combination behaves the same on the
 *      `ubuntu-latest` runner `.github/workflows/e2e.yml` actually runs on — both are
 *      native Linux Docker Engines and there is no structural reason to expect a
 *      difference, but "no reason to expect a difference" is not the same as having
 *      measured it there. Gated rather than asserted as certain.
 *
 * The buildx builder this test creates (`e2e-attest-builder`) runs as its own sibling
 * container (`buildx_buildkit_e2e-attest-builder0`) on the shared host daemon — another
 * host artifact in the same sense DockerTest.php's own docblock already flags for the
 * images this file builds and pulls. Removed in this test's own body (not deferred to
 * afterAll, since nothing else in this file depends on it existing).
 */
it('pushes an image index carrying provenance and SBOM attestations, and it round-trips', function () {
    if (getenv('E2E_ATTESTED_PUSH') !== '1') {
        test()->markTestSkipped(
            'E2E_ATTESTED_PUSH is not set — run `E2E_ATTESTED_PUSH=1 bin/e2e` to include the '
            .'attested-index push/pull test. Off by default: it pulls '
            .'docker/buildkit-syft-scanner from Docker Hub for the SBOM attestation (an '
            .'external dependency the rest of this file deliberately has none of), and it '
            .'has only been verified on a Docker Desktop daemon, not on the ubuntu-latest '
            .'runner this repository\'s CI actually uses — see this test\'s own docblock.'
        );
    }

    $context = E2eStack::context();
    $ref = dockerRef('attest');
    $config = dockerConfigDir('attest');
    $builder = 'e2e-attest-builder';

    $script = <<<SH
        set -e
        rm -rf /work/attest && mkdir -p /work/attest && cd /work/attest
        cp /fixtures/docker-image/Dockerfile.solo Dockerfile
        dd if=/dev/urandom of=payload.bin bs=1024 count=64 2>/dev/null

        mkdir -p {$config}
        export DOCKER_CONFIG={$config}
        echo '{$context['publish_token']}' | docker login {$context['docker_host']} -u x --password-stdin

        # docker-container, not the default "docker" driver: only the containerized
        # driver supports emitting attestations as a real image index at all.
        # network=host: the driver's OWN buildkit container is a separate sibling
        # container with its own network namespace by default — without this,
        # `127.0.0.1:8099` inside it is that container's own loopback, not the host's,
        # and the push fails with "connection refused" (confirmed directly: this is
        # exactly obstacle #1 from this file's own docblock, one layer further down).
        docker buildx create --driver docker-container --driver-opt network=host --name {$builder} --use

        docker buildx build --builder {$builder} --provenance=true --sbom=true -t {$ref} --push .

        docker buildx rm {$builder}
        SH;

    $process = E2eStack::exec('client-docker', $script, 300);

    expect($process->isSuccessful())->toBeTrue($process->getErrorOutput());

    // The registry's own answer, not an assumption about what buildx just did: a real
    // client pushed an index, and GET on that reference must serve it back with the index
    // media type, not the single-platform manifest media type.
    $manifest = E2eStack::getAtHostRoot(
        "/v2/{$context['docker_repository']}/manifests/attest",
        $context['read_token'],
    );
    expect($manifest['status'])->toBe(200);

    $doc = json_decode($manifest['body'], true);
    expect($doc['mediaType'] ?? null)->toBe('application/vnd.oci.image.index.v1+json');

    // And a real client can still pull it back.
    $pullScript = <<<SH
        set -e
        docker rmi -f {$ref} >/dev/null 2>&1 || true
        DOCKER_CONFIG={$config} docker pull {$ref}
        SH;

    $pullProcess = E2eStack::exec('client-docker', $pullScript, 300);

    expect($pullProcess->isSuccessful())->toBeTrue($pullProcess->getErrorOutput())
        ->and($pullProcess->getOutput())->toMatch('/Pull complete|Download complete/');
});
