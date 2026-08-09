<?php

use App\Models\User;
use App\Services\Setup\SetupToken;
use Illuminate\Support\Facades\Artisan;

/**
 * A02 — the first-run token is the only thing between an anonymous request and instance
 * takeover, and it used to travel as `GET /setup?token=<40 chars>`. A query string is
 * copied into reverse-proxy and CDN access logs, APM traces, shell history and the
 * browser's own history, so anyone who can read a log line during the window between
 * deployment and wizard completion can replay it and become super-admin.
 *
 * A secret in a URL is fixed by moving it out of the URL, not by redacting the log, so
 * the token now travels in a POST body and the query parameter is not consulted at all.
 * Nothing has to keep working across the change: `setup:token` regenerates on every app
 * start, so no printed URL survives the restart that ships this anyway.
 */
beforeEach(function () {
    config()->set('kontorfix.setup.require_token', true);
});

it('prints a token and a URL that does not carry it', function () {
    Artisan::call('setup:token');
    $output = Artisan::output();
    $token = app(SetupToken::class)->current();

    expect($output)->toContain((string) $token)
        ->and($output)->toContain(rtrim((string) config('app.url'), '/').'/setup')
        ->and($output)->not->toContain('?token=')
        ->and($output)->not->toContain('/setup?');
});

it('ignores a valid token presented in the query string', function () {
    $token = app(SetupToken::class)->regenerate();

    $this->get('/setup?token='.$token)->assertInertia(fn ($page) => $page->where('locked', true));

    // …and the gate did not quietly remember it either.
    $this->get('/setup')->assertInertia(fn ($page) => $page->where('locked', true));
    // The route gate refuses before validation, so an empty body is enough to prove it.
    $this->post('/setup', [])->assertForbidden();
});

it('unlocks the wizard when the token is posted', function () {
    $token = app(SetupToken::class)->regenerate();

    $this->post('/setup/unlock', ['token' => $token])->assertRedirect(route('setup.show'));

    $this->get('/setup')->assertInertia(fn ($page) => $page->where('locked', false));
});

it('stays locked on a wrong or missing token posted to the unlock endpoint', function () {
    app(SetupToken::class)->regenerate();

    $this->post('/setup/unlock', ['token' => 'not-the-token'])
        ->assertRedirect(route('setup.show'))
        ->assertSessionHasErrors('token');

    $this->post('/setup/unlock', [])->assertSessionHasErrors('token');

    $this->get('/setup')->assertInertia(fn ($page) => $page->where('locked', true));
    // The route gate refuses before validation, so an empty body is enough to prove it.
    $this->post('/setup', [])->assertForbidden();
});

it('throttles guesses at the unlock endpoint', function () {
    app(SetupToken::class)->regenerate();

    for ($i = 0; $i < 10; $i++) {
        $this->post('/setup/unlock', ['token' => 'guess-'.$i])->assertRedirect(route('setup.show'));
    }

    $this->post('/setup/unlock', ['token' => 'guess-11'])->assertStatus(429);
});

it('seals the unlock endpoint once the instance is set up', function () {
    $token = app(SetupToken::class)->regenerate();
    User::factory()->create();

    $this->post('/setup/unlock', ['token' => $token])->assertRedirect(route('home'));
});

it('ignores a valid token presented in the query string of the unlock endpoint', function () {
    $token = app(SetupToken::class)->regenerate();

    // The endpoint is a POST, but `input()` reads the query bag as well as the body, so
    // `POST /setup/unlock?token=…` still worked and was still written to every access
    // log on the way — while the method docblock asserted the token is never in a URL.
    $this->post('/setup/unlock?token='.$token)
        ->assertRedirect(route('setup.show'))
        ->assertSessionHasErrors('token');

    $this->get('/setup')->assertInertia(fn ($page) => $page->where('locked', true));

    // Reachability anchor: the same endpoint, the same token, in the body — it unlocks.
    // So the refusal above is the transport decision and not the throttle, the seal or
    // a value that never reached the gate at all.
    $this->post('/setup/unlock', ['token' => $token])->assertRedirect(route('setup.show'));
    $this->get('/setup')->assertInertia(fn ($page) => $page->where('locked', false));
});
