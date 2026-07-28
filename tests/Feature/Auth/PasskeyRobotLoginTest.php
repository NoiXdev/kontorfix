<?php

use App\Enums\AccountType;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Laravel\Passkeys\Passkey;
use Laravel\Passkeys\Passkeys;

uses(RefreshDatabase::class);

// The passkey ceremony itself is crypto-heavy (WebAuthn attestation) and not
// reasonably testable without brittle mocking. However, the decisive policy decision lives
// entirely in the closure registered via Passkeys::authorizeLoginUsing() —
// we test that directly here via Passkeys::allowsLogin(), just as the framework
// itself calls it during login. The RejectRobotWebSession middleware additionally
// remains in place as the authoritative safeguard (defense in depth).
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
