<?php

use GuzzleHttp\Client;
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
 * Obstacle #2 (a Docker client sends `Host: 127.0.0.1:8099`, and the domain-mode tests here
 * need that host to resolve to a registry) turned out to need no code change:
 * `Illuminate\Http\Request::getHost()` (Symfony underneath) already strips a trailing
 * `:<port>` before the resolver looks the value up, so the seeded `domains` row carries the
 * bare hostname `127.0.0.1`. See E2eSeeder's own comment on that row, and
 * tests/Feature/Registry/CustomDomainTest.php for the normal-suite Pest test that pins the
 * stripping behaviour directly.
 *
 * Both addressing modes are exercised here, and neither substitutes for the other: the
 * domain-mode tests below reach the registry at `127.0.0.1:8099` (the seeded `domains`
 * row), and the path-mode test reaches the SAME registry at
 * `localhost:8099/e2e-customer/e2e-registry/…`, where no `domains` row exists and
 * ResolveOciContext has to split the organization and registry slugs off the repository
 * name instead. Two different strings for one loopback address, deliberately — see
 * E2eSeeder's comment on `docker_path_host`.
 *
 * `--provenance=false --sbom=false` appears on several tests below, not on all of them, for
 * two independent reasons rather than one habit. The shared-layer dedup test needs an
 * exact blob-count delta, and a real buildx push attaches a provenance attestation as its
 * own extra manifest+blob by default — confirmed directly, since that test failed with one
 * blob more than expected per image until the flags were added there. The clean-store pull
 * test, the 500 MiB test, and the sweep test's own tag need the SAME flags for a different
 * reason: each pushes through the ephemeral `docker-container` buildx builder precisely so
 * its layers never enter this daemon's own image store (see each test's own comment), and
 * their assertions compare a single manifest digest — an attestation index in place of a
 * bare manifest would still round-trip, but a `Docker-Content-Digest` read against it would
 * be the index's digest, not the one thing under test. Everywhere else in this file — the
 * plain push/pull test and the path-address test, both a plain `docker build` on the
 * default driver — a real push is left to do whatever it does by default (in practice, push
 * an image INDEX carrying a provenance attestation), because that ambient shape is real
 * client behaviour worth covering, not an artifact to suppress. The attested-push test goes
 * further still and FORCES an index with both provenance and an SBOM, matching this
 * project's own release workflow — gated behind E2E_ATTESTED_PUSH; see that test's own
 * docblock for why.
 */
function dockerRef(string $tag): string
{
    $context = E2eStack::context();

    return "{$context['docker_host']}/{$context['docker_repository']}:{$tag}";
}

/**
 * The same repository, addressed by PATH namespace instead of by the registered domain:
 * `<host>/<org>/<registry>/<repo>` on a host with no `domains` row of its own.
 */
function dockerPathRef(string $tag): string
{
    $context = E2eStack::context();

    return "{$context['docker_path_host']}/{$context['docker_path_repository']}:{$tag}";
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
    $refs = implode(' ', array_map('dockerRef', [
        'v1', 'dedup-a', 'dedup-b', 'big', 'attest', 'pullback', 'sweep',
    ]));

    E2eStack::exec('client-docker', "docker rmi -f {$refs} >/dev/null 2>&1 || true", 60);

    // The path-addressed tag carries a different host string, so it is a different local
    // image reference and the sweep above does not cover it.
    E2eStack::exec('client-docker', 'docker rmi -f '.dockerPathRef('pathmode').' >/dev/null 2>&1 || true', 60);

    // Safety net, not the primary cleanup path: every test below that drives an ephemeral
    // `docker-container` buildx builder removes it in its own body once the build succeeds,
    // but a failure partway through one of those scripts (`set -e` aborts before the `docker
    // buildx rm` line runs) would leave the builder — and the sibling
    // `buildx_buildkit_<name>0` container it owns — behind on the shared host daemon
    // otherwise. A no-op for any builder never created (its test skipped, or never got far
    // enough to create it), same as the `docker rmi` above.
    $builders = implode(' ', [
        'e2e-attest-builder', 'e2e-pullback-builder', 'e2e-big-builder', 'e2e-sweep-builder',
    ]);
    E2eStack::exec('client-docker', "for b in {$builders}; do docker buildx rm \$b >/dev/null 2>&1 || true; done", 60);
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
    $ref = dockerRef('pullback');
    $pushConfig = dockerConfigDir('pullback-push');
    $pullConfig = dockerConfigDir('pullback-pull');
    $builder = 'e2e-pullback-builder';

    // Pushed through the ephemeral `docker-container` buildx builder — exactly like the
    // attested-push test below, and for the identical reason: `--push` sends the built
    // image straight to the registry without ever loading it into THIS daemon's own image
    // store, so it cannot leave anything behind for `docker rmi` to (fail to) clean up in
    // the first place. That is what makes the "clean local store" this test's name promises
    // actually true on the classic (overlay2/graphdriver) store CI runs on: a `docker
    // build` + `docker push` here would load the image locally, and on that store `docker
    // rmi` does not free layers BuildKit's own build cache still references — the very next
    // `docker pull` would then report "Already exists" instead of "Pull complete", passing
    // for the wrong reason (see this file's own docblock and the path-address test below,
    // which is the one place that trade-off is accepted instead of avoided).
    //
    // `--provenance=false --sbom=false`: this test compares a single manifest digest, not
    // an index shape, so a default buildx push's extra attestation manifest would only be
    // noise here.
    $pushScript = <<<SH
        set -e
        rm -rf /work/pullback && mkdir -p /work/pullback && cd /work/pullback
        cp /fixtures/docker-image/Dockerfile.solo Dockerfile
        dd if=/dev/urandom of=payload.bin bs=1024 count=64 2>/dev/null

        mkdir -p {$pushConfig}
        export DOCKER_CONFIG={$pushConfig}
        echo '{$context['publish_token']}' | docker login {$context['docker_host']} -u x --password-stdin

        docker buildx create --driver docker-container --driver-opt network=host --name {$builder} --use
        docker buildx build --builder {$builder} --provenance=false --sbom=false -t {$ref} --push .
        docker buildx rm {$builder}
        SH;

    $pushProcess = E2eStack::exec('client-docker', $pushScript, 300);
    expect($pushProcess->isSuccessful())->toBeTrue($pushProcess->getErrorOutput());

    // Read off the registry directly rather than parsed from the push output: a buildx
    // `--push` build does not print the same `digest: sha256:…` line a plain `docker push`
    // does, and the attest test below establishes the same pattern — the registry's own
    // `Docker-Content-Digest` header is the authority either way.
    $registryDigest = E2eStack::ociManifestDigest($context['docker_repository'], 'pullback', $context['read_token']);
    expect($registryDigest)->not->toBeNull();

    $pullScript = <<<SH
        set -e
        mkdir -p {$pullConfig}
        echo '{$context['read_token']}' | DOCKER_CONFIG={$pullConfig} docker login {$context['docker_host']} -u x --password-stdin

        # Never actually present locally (the build above only pushed, it never loaded),
        # but harmless either way — the point is that nothing local can rescue this pull.
        docker rmi -f {$ref} >/dev/null 2>&1 || true

        DOCKER_CONFIG={$pullConfig} docker pull {$ref}
        SH;

    $pullProcess = E2eStack::exec('client-docker', $pullScript, 300);

    expect($pullProcess->isSuccessful())->toBeTrue($pullProcess->getErrorOutput());

    // A matching digest alone is not proof that any bytes actually came off the registry:
    // a manifest resolve followed by "every layer already exists" would still print a
    // correct, matching `Digest:` line even if `BlobController::show()` answered every blob
    // request with a 500 — pull would report each layer already present and never notice.
    // "Pull complete" (or "Download complete", printed just before it once bytes have
    // actually been received) only appears once a layer is genuinely fetched — and now
    // genuinely does on BOTH store types, because this image's layers never entered any
    // daemon's store to be "already exists"-cached against. Verified by deliberately
    // breaking BlobController::show() to return 500 for every request: this test (and the
    // 500 MiB one below, converted the same way) both went red, rather than quietly missing
    // the regression the way an "always exists" pull would have.
    expect($pullProcess->getOutput())->toMatch('/Pull complete|Download complete/');

    preg_match('/[Dd]igest: (sha256:[0-9a-f]{64})/', $pullProcess->getOutput(), $matches);

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
    $builder = 'e2e-big-builder';

    // Same conversion as the plain "clean local store" pull test above, and for the same
    // reason: pushed through the ephemeral `docker-container` buildx builder with `--push`
    // so the 520 MiB layer never lands in THIS daemon's own image store, and `docker rmi`
    // therefore has nothing to (fail to) free on the classic store — see this file's own
    // docblock for the store-semantics difference between CI and a developer machine.
    $script = <<<SH
        set -e
        rm -rf /work/big && mkdir -p /work/big && cd /work/big
        cp /fixtures/docker-image/Dockerfile.solo Dockerfile
        # Incompressible: /dev/urandom, not /dev/zero — a zero-filled "large" layer
        # compresses away to almost nothing and would prove nothing about actually moving
        # this many bytes through the blob upload/download path.
        dd if=/dev/urandom of=payload.bin bs=1M count=520 2>/dev/null

        mkdir -p {$config}
        export DOCKER_CONFIG={$config}
        echo '{$context['publish_token']}' | docker login {$context['docker_host']} -u x --password-stdin

        docker buildx create --driver docker-container --driver-opt network=host --name {$builder} --use
        docker buildx build --builder {$builder} --provenance=false --sbom=false -t {$ref} --push .
        docker buildx rm {$builder}

        docker rmi -f {$ref} >/dev/null 2>&1 || true
        docker pull {$ref}
        SH;

    $process = E2eStack::exec('client-docker', $script, 900);

    expect($process->isSuccessful())->toBeTrue($process->getErrorOutput());

    // Same reasoning as the plain pull test above: a matching digest is not proof the 520
    // MiB layer's bytes were actually served, only that resolving the manifest and
    // whatever layers WERE fetched agree with what was pushed. The layer was never local to
    // begin with (buildx only pushed, it never loaded), so this line only appears once it
    // is genuinely fetched.
    expect($process->getOutput())->toMatch('/Pull complete|Download complete/');

    // The push side no longer prints a `digest: sha256:…` line the way a plain `docker
    // push` does (buildx's own build output differs), so the push-side digest is read off
    // the registry directly instead — the same authority the attest test and the plain
    // pull test above both use.
    $registryDigest = E2eStack::ociManifestDigest($context['docker_repository'], 'big', $context['read_token']);
    expect($registryDigest)->not->toBeNull();

    preg_match('/[Dd]igest: (sha256:[0-9a-f]{64})/', $process->getOutput(), $matches);

    expect($matches[1] ?? null)->toBe($registryDigest);
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

/**
 * The path-namespaced address, driven by the real client rather than by curl.
 *
 * Domain mode is covered by every other test in this file and neither mode substitutes for
 * the other: they differ in exactly the thing that can break — whether `{name}` reaches the
 * controller as `kontorfix-e2e-demo` or as `e2e-customer/e2e-registry/kontorfix-e2e-demo`.
 * A Pest feature test can assert the resolver's output; only a real `docker push`/`docker
 * pull` establishes that a client actually accepts an address of this shape, sends `/v2/`
 * at the host root with the namespace folded into the repository name, and round-trips an
 * image through it.
 *
 * `--provenance=false --sbom=false` is deliberately NOT set here, matching the plain push
 * test above: a real buildx default push (in practice an image INDEX carrying a provenance
 * attestation) is the ambient client behaviour worth covering, and the assertions below
 * compare digests rather than count blobs, so the shape does not matter to them.
 */
it('pushes and pulls through the path address rather than the registered domain', function () {
    $context = E2eStack::context();
    $ref = dockerPathRef('pathmode');
    $config = dockerConfigDir('pathmode');

    $script = <<<SH
        set -e
        rm -rf /work/pathmode && mkdir -p /work/pathmode && cd /work/pathmode
        cp /fixtures/docker-image/Dockerfile.solo Dockerfile
        dd if=/dev/urandom of=payload.bin bs=1024 count=64 2>/dev/null

        mkdir -p {$config}
        echo '{$context['publish_token']}' | DOCKER_CONFIG={$config} docker login {$context['docker_path_host']} -u x --password-stdin

        DOCKER_CONFIG={$config} docker build -t {$ref} .
        DOCKER_CONFIG={$config} docker push {$ref}

        # A clean local store, same reasoning as the plain pull test: without this the pull
        # resolves against the image the build just left behind and fetches nothing.
        docker rmi -f {$ref}
        DOCKER_CONFIG={$config} docker pull {$ref}
        SH;

    $process = E2eStack::exec('client-docker', $script, 300);

    // No `/Pull complete|Download complete/` assertion here, unlike every other pull in
    // this file: this is the one test whose PUSH goes through a plain `docker build` on the
    // shared daemon (deliberately — it is the only coverage a classic engine's PATH-
    // addressed push actually authenticates at all now, see VersionController and this
    // file's own docblock), so its layers sit in the daemon's BuildKit build cache even
    // after `docker rmi`, and on the classic (overlay2) store `docker rmi` does not free
    // them from it — the pull below can report "Already exists" instead of "Pull complete"
    // there while still genuinely round-tripping a correct digest. That "bytes really came
    // off the registry" guarantee lives in the buildx-pushed tags instead (the clean-store
    // pull test, the 500 MiB test, and the sweep test's final pull above), whose layers
    // never enter any daemon's image store to begin with.
    expect($process->isSuccessful())->toBeTrue($process->getErrorOutput());

    preg_match_all('/[Dd]igest: (sha256:[0-9a-f]{64})/', $process->getOutput(), $matches);

    // Two digest lines — one from the push, one from the pull — and they must agree.
    expect($matches[1])->toHaveCount(2)
        ->and($matches[1][0])->toBe($matches[1][1]);

    // The registry's own answer through the path address, not merely what the client printed.
    expect(E2eStack::ociManifestDigest(
        $context['docker_path_repository'],
        'pathmode',
        $context['read_token'],
        E2eStack::pathHostRoot(),
    ))->toBe($matches[1][0]);

    // And the same image under the DOMAIN address's short name: a path-mode address is a
    // second door to one registry, not a second registry. This is the assertion that would
    // fail if the resolver had written the pushed manifest into anything but the registry
    // the two slugs name.
    expect(E2eStack::ociManifestDigest(
        $context['docker_repository'],
        'pathmode',
        $context['read_token'],
    ))->toBe($matches[1][0]);
});

it('sweeps an abandoned upload session but never a layer a pull still needs', function () {
    $context = E2eStack::context();

    // A tag of its own, pushed through the ephemeral `docker-container` buildx builder
    // BEFORE the aging step below — never through `docker build`, so its layers never sit
    // in this daemon's own image store for the final pull to (mis)resolve against. Pushed
    // first, not after aging, so the aging step below (which ages EVERY OciBlob/OciManifest
    // row, this one included) actually exercises the property this test is about: the
    // sweep must leave content a tag still reaches alone BECAUSE it is referenced, not
    // merely because it is young. Its own tag (not `v1`, which the earlier push test
    // already built with a plain `docker build` and which the classic store's BuildKit
    // build cache can therefore satisfy a `docker pull` from without a genuine fetch — see
    // this file's own docblock).
    $ref = dockerRef('sweep');
    $config = dockerConfigDir('sweep');
    $builder = 'e2e-sweep-builder';

    $pushScript = <<<SH
        set -e
        rm -rf /work/sweep && mkdir -p /work/sweep && cd /work/sweep
        cp /fixtures/docker-image/Dockerfile.solo Dockerfile
        dd if=/dev/urandom of=payload.bin bs=1024 count=64 2>/dev/null

        mkdir -p {$config}
        export DOCKER_CONFIG={$config}
        echo '{$context['publish_token']}' | docker login {$context['docker_host']} -u x --password-stdin

        docker buildx create --driver docker-container --driver-opt network=host --name {$builder} --use
        docker buildx build --builder {$builder} --provenance=false --sbom=false -t {$ref} --push .
        docker buildx rm {$builder}
        SH;

    $pushProcess = E2eStack::exec('client-docker', $pushScript, 300);
    expect($pushProcess->isSuccessful())->toBeTrue($pushProcess->getErrorOutput());

    // A REAL abandoned upload: POST opens the session, nothing ever finishes it. Basic
    // auth, the same form docker login itself hands the registry.
    $client = new Client(['http_errors' => false, 'timeout' => 30]);
    $response = $client->post(
        E2eStack::hostRoot()."/v2/{$context['docker_repository']}/blobs/uploads/",
        ['auth' => ['x', $context['publish_token']]],
    );
    expect($response->getStatusCode())->toBe(202);

    // Age the whole registry past a 1-hour grace period, and expire the session. Done
    // through the app container because that is where the database lives; the CONTENT is
    // untouched — only timestamps move, which is exactly what a day of real time would do.
    $prepare = <<<'PHP'
        php artisan tinker --execute="
            App\Models\SystemSetting::current()->update(['oci_blob_grace_hours' => 1]);
            App\Models\OciBlob::query()->update(['created_at' => now()->subHours(2)]);
            App\Models\OciManifest::query()->update(['created_at' => now()->subHours(2)]);
            App\Models\OciBlobUpload::query()->update(['expires_at' => now()->subMinute()]);
            echo App\Models\OciBlobUpload::count();
        " 2>/dev/null | tail -n 1
        PHP;

    $before = E2eStack::exec('app', $prepare, 60);
    expect((int) trim($before->getOutput()))->toBeGreaterThanOrEqual(1);

    // The sweep, against the real stack: real files on the real artifacts disk.
    $sweep = E2eStack::exec('app', 'php artisan oci:sweep', 120);
    expect($sweep->isSuccessful())->toBeTrue($sweep->getErrorOutput());

    // The session is gone — row and file both. The file half matters: the row alone
    // disappearing would leave exactly the disk filler the sweep exists to stop.
    $check = <<<'PHP'
        php artisan tinker --execute="
            echo App\Models\OciBlobUpload::count();
            echo ':';
            echo collect(Illuminate\Support\Facades\Storage::disk('artifacts')->files('docker/uploads'))->count();
        " 2>/dev/null | tail -n 1
        PHP;

    $after = E2eStack::exec('app', $check, 60);
    expect(trim($after->getOutput()))->toBe('0:0');

    // THE gate: everything a tag still reaches survived a sweep whose grace period the
    // aging above genuinely put it past. A hand-built fixture cannot rule out the sweeper
    // deleting something reachable — only a real pull after a real sweep can. Pulling the
    // buildx-pushed tag from above, whose layers never entered any daemon's image store, so
    // "Pull complete" below is genuine registry-fetch proof on both store types rather than
    // something BuildKit's build cache could satisfy for a tag `docker build` once loaded
    // locally.
    $pullConfig = dockerConfigDir('sweep-pull');

    $script = <<<SH
        set -e
        mkdir -p {$pullConfig}
        echo '{$context['read_token']}' | DOCKER_CONFIG={$pullConfig} docker login {$context['docker_host']} -u x --password-stdin
        docker rmi -f {$ref} >/dev/null 2>&1 || true
        DOCKER_CONFIG={$pullConfig} docker pull {$ref}
        SH;

    $process = E2eStack::exec('client-docker', $script, 300);

    expect($process->isSuccessful())->toBeTrue($process->getErrorOutput())
        // Bytes really fetched, not resolved against a local leftover — the same
        // "Pull complete" reasoning the plain pull test above records at length.
        ->and($process->getOutput())->toMatch('/Pull complete|Download complete/');
});
