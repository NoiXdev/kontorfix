<?php

use App\Models\Organization;
use App\Models\RegistryToken;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

it('resolves a bearer token onto the request (npm style)', function () {
    Route::get('/_test/reg', fn (Request $r) => response()->json([
        'id' => $r->attributes->get('registryToken')?->id,
    ]))->middleware('registry.auth');

    $org = Organization::factory()->create();
    [$token, $plain] = RegistryToken::issue($org, 'npm', null);

    $this->withHeaders(['Authorization' => 'Bearer '.$plain])
        ->getJson('/_test/reg')->assertOk()->assertJsonPath('id', $token->id);
});

it('still resolves basic auth (composer style) and stays anonymous without creds', function () {
    Route::get('/_test/reg', fn (Request $r) => response()->json([
        'id' => $r->attributes->get('registryToken')?->id,
    ]))->middleware('registry.auth');

    $org = Organization::factory()->create();
    [$token, $plain] = RegistryToken::issue($org, 'composer', null);

    $this->withHeaders(['Authorization' => 'Basic '.base64_encode('token:'.$plain)])
        ->getJson('/_test/reg')->assertOk()->assertJsonPath('id', $token->id);
    $this->withoutHeader('Authorization')
        ->getJson('/_test/reg')->assertOk()->assertJsonPath('id', null);
});

it('rejects an invalid bearer token as anonymous', function () {
    Route::get('/_test/reg', fn (Request $r) => response()->json([
        'id' => $r->attributes->get('registryToken')?->id,
    ]))->middleware('registry.auth');

    $this->withHeaders(['Authorization' => 'Bearer kfx_'.str_repeat('z', 40)])
        ->getJson('/_test/reg')->assertOk()->assertJsonPath('id', null);
});
