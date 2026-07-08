<?php

use App\Models\Organization;
use App\Models\RegistryToken;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

it('issues a plaintext token once and stores only the hash', function () {
    $org = Organization::factory()->create();
    [$token, $plain] = RegistryToken::issue($org, name: 'ci', group: null);

    expect($plain)->toStartWith('kfx_')
        ->and($token->token_hash)->toBe(hash('sha256', $plain))
        ->and(RegistryToken::findByPlainText($plain)?->is($token))->toBeTrue();
});

it('rejects expired tokens', function () {
    $org = Organization::factory()->create();
    [$token, $plain] = RegistryToken::issue($org, name: 'old', group: null);
    $token->update(['expires_at' => now()->subDay()]);

    expect(RegistryToken::findByPlainText($plain))->toBeNull();
});

it('resolves a valid basic-auth token onto the request and updates last_used_at', function () {
    Route::get('/_test/registry-auth', function (Request $request) {
        return response()->json(['token_id' => $request->attributes->get('registryToken')?->id]);
    })->middleware('registry.auth');

    $org = Organization::factory()->create();
    [$token, $plain] = RegistryToken::issue($org, name: 'ci', group: null);

    $this->withHeaders(['Authorization' => 'Basic '.base64_encode('anyuser:'.$plain)])
        ->getJson('/_test/registry-auth')
        ->assertOk()->assertJsonPath('token_id', $token->id);

    expect($token->fresh()->last_used_at)->not->toBeNull();
});

it('passes anonymous requests through with a null token', function () {
    Route::get('/_test/registry-auth', function (Request $request) {
        return response()->json(['token_id' => $request->attributes->get('registryToken')?->id]);
    })->middleware('registry.auth');

    $this->getJson('/_test/registry-auth')->assertOk()->assertJsonPath('token_id', null);
});

it('accepts the token as basic username too', function () {
    Route::get('/_test/registry-auth', function (Request $request) {
        return response()->json(['token_id' => $request->attributes->get('registryToken')?->id]);
    })->middleware('registry.auth');

    $org = Organization::factory()->create();
    [$token, $plain] = RegistryToken::issue($org, name: 'ci', group: null);

    $this->withHeaders(['Authorization' => 'Basic '.base64_encode($plain.':')])
        ->getJson('/_test/registry-auth')
        ->assertOk()->assertJsonPath('token_id', $token->id);
});
