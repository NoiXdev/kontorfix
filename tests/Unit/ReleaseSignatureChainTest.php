<?php

/**
 * The release workflow signed an image the deployment did not name.
 *
 * release.yml has built, pushed and cosign-signed a Docker Hub image since it was added.
 * `docker/compose.yaml` ran every service from `harbor.cloud.noidee.dev/...` — a leftover
 * from the scaffolding commit twenty days BEFORE that pipeline existed, never updated when
 * it landed. The file contradicted itself: its own header told operators to verify and pin
 * `docker.io/<namespace>/<image>` while the `image:` lines below pointed somewhere else,
 * and nothing in the repository populated that somewhere.
 *
 * These assertions pin the property rather than the wording: the registry the deployment
 * pulls from is the one the release publishes to and signs, and the signature is over a
 * digest rather than a tag.
 */
function releaseWorkflow(): string
{
    return (string) file_get_contents(base_path('.github/workflows/release.yml'));
}

function releaseComposeSource(): string
{
    return (string) file_get_contents(base_path('docker/compose.yaml'));
}

/** @return list<string> Every image reference the compose file runs the application from. */
function composeApplicationImages(): array
{
    preg_match_all('/^\s*image:\s*([^\s#]+)/m', releaseComposeSource(), $matches);

    return array_values(array_filter(
        $matches[1],
        fn (string $ref): bool => str_contains($ref, 'kontorfix'),
    ));
}

it('deploys the application from the registry the release publishes to', function () {
    $images = composeApplicationImages();

    expect($images)->not->toBeEmpty();

    // Docker Hub is where release.yml pushes and signs; a reference anywhere else would be
    // an image this repository never produced and no attestation covers.
    foreach ($images as $ref) {
        expect($ref)->toContain('docker.io/');
    }

    expect(releaseWorkflow())->toContain('docker.io/${{ vars.DOCKERHUB_NAMESPACE }}/${{ vars.DOCKERHUB_IMAGE }}');
});

it('lets a deployment override the reference with a verified digest', function () {
    // The header tells operators to replace the mutable tag with @sha256:<digest> once
    // they have verified a release. That needs a single knob rather than four literals, or
    // one forgotten service runs an unpinned image beside three pinned ones.
    foreach (composeApplicationImages() as $ref) {
        expect($ref)->toStartWith('${KONTORFIX_IMAGE:-');
    }
});

it('points the verification instructions at the same registry it runs', function () {
    // The original defect in one assertion: the header said docker.io while the image
    // lines said Harbor, so an operator following the instructions verified an artifact
    // the stack never pulled.
    expect(releaseComposeSource())->toContain('cosign verify docker.io/')
        ->and(releaseComposeSource())->not->toContain('harbor.');
});

it('signs what it pushes, by digest rather than by tag', function () {
    // A tag is a mutable pointer, so a signature over it says nothing about the bytes a
    // consumer receives.
    expect(releaseWorkflow())->toMatch('/cosign sign[^\n]*@\$\{?DIGEST/');
});
