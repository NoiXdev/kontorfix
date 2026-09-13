<?php

// `/o/{orgSlug}` answered 404 for an unknown organization and 401 for a real one, both
// before any authentication, un-throttled, in a single URL segment. That turned the first
// path component — which since the org-slug change is the CUSTOMER's name — into a
// wordlist-checkable oracle: "is this company a customer of this instance".
//
// The project treats exactly that as a protection goal in its own words:
// ResolvePortalContext's docblock explains the portal answers uniformly so a customer slug
// cannot be guessed. This endpoint contradicted it.
//
// The 401 for a real registry is NOT the bug and is deliberately kept — Composer prompts on
// it, pip's keyring lookup triggers on it, twine expects it. The fix is to make the unknown
// case answer the same thing, not to make the known case answer something else.

use App\Models\Organization;

it('answers an unknown organization exactly as it answers one the caller may not read', function () {
    $real = Organization::factory()->create(['slug' => 'echte-firma']);

    $known = $this->get('/o/'.$real->slug.'/packages.json');
    $unknown = $this->get('/o/voellig-erfunden/packages.json');

    expect($unknown->getStatusCode())->toBe($known->getStatusCode())
        ->and($unknown->getStatusCode())->toBe(401)
        ->and($unknown->getContent())->toBe($known->getContent());
});

it('gives the same answer across every ecosystem on the org prefix', function () {
    $real = Organization::factory()->create(['slug' => 'echte-firma']);

    foreach (['/packages.json', '/p2/acme/demo.json', '/simple/', '/acme-paket'] as $path) {
        $known = $this->get('/o/'.$real->slug.$path);
        $unknown = $this->get('/o/voellig-erfunden'.$path);

        expect($unknown->getStatusCode())
            ->toBe($known->getStatusCode(), "status differs for {$path}");
    }
});

it('does not let a foreign token tell the two apart either', function () {
    $real = Organization::factory()->create(['slug' => 'echte-firma']);
    $other = Organization::factory()->create(['slug' => 'andere-firma']);

    $headers = orgTokenHeaderFor($other);

    $known = $this->get('/o/'.$real->slug.'/packages.json', $headers);
    $unknown = $this->get('/o/voellig-erfunden/packages.json', $headers);

    expect($unknown->getStatusCode())->toBe($known->getStatusCode())
        ->and($unknown->getStatusCode())->toBe(403)
        ->and($unknown->getContent())->toBe($known->getContent());
});

it('still serves the organization its own token is for', function () {
    // The guard must not have turned every org endpoint into a refusal.
    $org = Organization::factory()->create(['slug' => 'echte-firma']);

    $this->get('/o/'.$org->slug.'/packages.json', orgTokenHeaderFor($org))->assertOk();
});
