<?php

/**
 * The release workflow signed an image the deployment does not use.
 *
 * `docker/compose.yaml` runs every service from a Harbor reference, while release.yml
 * built, pushed and cosign-signed the Docker Hub image. Searching the whole repository —
 * workflows, scripts, Makefiles, docs — turned up no mechanism that populates Harbor at
 * all, so how the deployed artifact came into existence was not visible here and was
 * certainly not covered by the attestation. Combined with a mutable `:latest` tag and no
 * pull-time verification, the signature was decorative for the deploy path.
 *
 * These assertions pin the property rather than the wording: every registry the deployment
 * pulls from is one the release pushes to and signs, in the same build, and therefore at
 * the same digest.
 */
function releaseWorkflow(): string
{
    return (string) file_get_contents(base_path('.github/workflows/release.yml'));
}

function composeFile(): string
{
    return (string) file_get_contents(base_path('docker/compose.yaml'));
}

it('lets the deployment name the same image the release publishes and signs', function () {
    // The chain can only hold if both ends can be pointed at one reference. The workflow
    // side is HARBOR_IMAGE; the deployment side has to be a variable too, or the two are
    // wired together by nothing but an operator's memory.
    expect(composeFile())->toContain('${KONTORFIX_IMAGE:-')
        ->and(releaseWorkflow())->toContain('vars.HARBOR_IMAGE');

    // And every application service has to follow it — a single forgotten literal would
    // run one container from an unsigned image while the rest are verified.
    preg_match_all('/^\s*image:\s*([^\s#]+)/m', composeFile(), $matches);
    $appImages = array_values(array_filter(
        $matches[1],
        fn (string $ref): bool => str_contains($ref, 'kontorfix'),
    ));

    expect($appImages)->not->toBeEmpty();
    foreach ($appImages as $ref) {
        expect($ref)->toStartWith('${KONTORFIX_IMAGE:-');
    }
});

it('pushes the deployment reference from the same build as the signed one', function () {
    // Same build → same digest → the signature covers both registries. Two separate builds
    // would produce two digests and leave the deployed one unsigned again.
    $workflow = releaseWorkflow();

    $metaSection = substr($workflow, (int) strpos($workflow, 'Compute image tags'), 1400);

    expect($metaSection)->toContain('vars.HARBOR_IMAGE')
        ->and($workflow)->toContain('DIGEST: ${{ steps.build.outputs.digest }}');
});

it('signs every reference it pushes, by digest', function () {
    $workflow = releaseWorkflow();

    // By digest, not by tag: a tag is a mutable pointer, so a signature over it says
    // nothing about the bytes a consumer receives.
    expect($workflow)->toContain('cosign sign')
        ->and($workflow)->toMatch('/cosign sign[^\n]*@\$\{?DIGEST/');
});

it('makes an unsigned deployment registry visible instead of silently skipping it', function () {
    // The Harbor push is conditional on the repository being configured for it. A
    // condition that silently does nothing would recreate exactly the gap this closes, so
    // the workflow has to say so when it skips.
    $workflow = releaseWorkflow();

    expect($workflow)->toContain('::warning::');
});
