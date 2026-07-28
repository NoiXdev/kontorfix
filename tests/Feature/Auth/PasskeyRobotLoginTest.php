<?php

use App\Enums\AccountType;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Laravel\Passkeys\Passkey;
use Laravel\Passkeys\Passkeys;

uses(RefreshDatabase::class);

// Die Passkey-Zeremonie selbst ist krypto-lastig (WebAuthn-Attestation) und nicht
// sinnvoll ohne brüchiges Mocking testbar. Die maßgebliche Policy-Entscheidung sitzt
// aber vollständig in der über Passkeys::authorizeLoginUsing() registrierten Closure —
// die testen wir hier direkt über Passkeys::allowsLogin(), so wie es das Framework
// selbst beim Login aufruft. Die RejectRobotWebSession-Middleware bleibt zusätzlich
// als autoritativer Schutz (Defense-in-Depth) bestehen.
it('denies a passkey login for a robot account', function () {
    $robot = User::factory()->create(['account_type' => AccountType::Robot]);
    $passkey = new Passkey([
        'name' => 'robot-passkey',
        'credential_id' => 'cred-robot',
        'credential' => ['aaguid' => Str::uuid()->toString()],
    ]);
    $passkey->user_id = $robot->id;
    $passkey->save();

    expect(Passkeys::allowsLogin(Request::create('/'), $passkey))->toBeFalse();
});

it('still allows a passkey login for a human account', function () {
    $human = User::factory()->create(['account_type' => AccountType::Human]);
    $passkey = new Passkey([
        'name' => 'human-passkey',
        'credential_id' => 'cred-human',
        'credential' => ['aaguid' => Str::uuid()->toString()],
    ]);
    $passkey->user_id = $human->id;
    $passkey->save();

    expect(Passkeys::allowsLogin(Request::create('/'), $passkey))->toBeTrue();
});
