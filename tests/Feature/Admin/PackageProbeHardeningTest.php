<?php

// A04/A09 — `POST /admin/packages/probe` shells out to git for any Admin or Maintainer of
// any organization, the lowest console tier, with no preconditions. It was unthrottled and
// unlogged, its 30 s `ls-remote` timeout raised an UNCAUGHT ProcessTimedOutException (a 500
// plus a stack trace per request, after parking a worker for 30 s), and the unrecognised
// branch of readableGitError() handed the caller raw transport stderr — for the ssh:// class
// that is the resolved IP and per-port connection state, i.e. a network oracle sitting
// behind rather than in front of the address policy (carried partial B15).

use App\Enums\PackageType;
use App\Enums\UserRole;
use App\Models\Organization;
use App\Models\User;
use App\Services\Vcs\RepositoryProbe;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process as SymfonyProcess;

function probeHardeningAdmin(): User
{
    return User::factory()
        ->for(Organization::factory()->create(['is_operator' => true]))
        ->create(['role' => UserRole::Admin]);
}

function probeTimeout(): ProcessTimedOutException
{
    $process = new SymfonyProcess(['git', 'ls-remote']);
    $process->setTimeout(30);

    return new ProcessTimedOutException($process, ProcessTimedOutException::TYPE_GENERAL);
}

it('answers a probe timeout instead of raising an uncaught 500', function () {
    Process::fake(['*ls-remote*' => fn () => throw probeTimeout()]);

    $this->actingAs(probeHardeningAdmin())->postJson('/admin/packages/probe', [
        'type' => 'composer',
        'repository_url' => 'https://github.com:81/acme/tools.git',
    ])
        ->assertOk()
        ->assertJsonPath('ok', false)
        ->assertJsonPath('error', 'Repository nicht erreichbar.')
        ->assertJsonPath('versions', []);
});

it('reaches git at all on this route — the anchor for the timeout case', function () {
    // Same actor, same route, same payload shape, and the process IS run: so the case
    // above is about the exception being caught inside the probe, not about validation,
    // the operator gate or the address policy answering earlier.
    Process::fake([
        '*ls-remote*' => Process::result(output: "ref: refs/heads/main\tHEAD\n"),
        '*clone*' => Process::result(''),
        '*show*' => Process::result('{"name":"acme/tools"}'),
    ]);

    $this->actingAs(probeHardeningAdmin())->postJson('/admin/packages/probe', [
        'type' => 'composer',
        'repository_url' => 'https://github.com:81/acme/tools.git',
    ])->assertOk()->assertJsonPath('ok', true);

    Process::assertRan(fn ($process) => str_contains(implode(' ', (array) $process->command), 'ls-remote'));
});

it('does not echo raw ssh transport stderr back to the caller', function () {
    Process::fake([
        '*ls-remote*' => Process::result(
            output: '',
            errorOutput: "ssh: connect to host github.com port 2222: Connection refused\r\n"
                ."debug1: Connecting to github.com [203.0.113.77] port 2222.\r\n",
            exitCode: 128,
        ),
    ]);

    $response = $this->actingAs(probeHardeningAdmin())->postJson('/admin/packages/probe', [
        'type' => 'composer',
        'repository_url' => 'ssh://git@github.com:2222/acme/tools.git',
    ])->assertOk()->assertJsonPath('ok', false);

    $body = $response->getContent();
    expect($body)->not->toContain('203.0.113.77')
        ->and($body)->not->toContain('2222')
        ->and($body)->not->toContain('Connection refused');
    $response->assertJsonPath('error', 'Repository konnte nicht gelesen werden.');
});

it('still tells the caller apart the three failure classes it is allowed to name', function () {
    // The oracle is the raw-stderr fallthrough, not the product feedback. These three stay,
    // which is also what proves the case above is the fallthrough and not a blanket rewrite.
    $cases = [
        'fatal: Authentication failed for https://github.com/x' => 'Zugriff verweigert — Repository privat? Für SSH einen Deploy-Key hinterlegen.',
        'ERROR: Repository not found.' => 'Repository nicht gefunden.',
        'fatal: unable to access https://github.com/x' => 'Repository nicht erreichbar.',
    ];

    foreach ($cases as $stderr => $expected) {
        Process::fake(['*ls-remote*' => Process::result(output: '', errorOutput: $stderr, exitCode: 128)]);

        expect((new RepositoryProbe)->probe(PackageType::Composer, 'https://github.com/acme/x.git')['error'])
            ->toBe($expected);
    }
});

it('throttles the probe endpoint', function () {
    Process::fake(['*ls-remote*' => Process::result(output: "ref: refs/heads/main\tHEAD\n")]);
    $user = probeHardeningAdmin();

    foreach (range(1, 10) as $ignored) {
        $this->actingAs($user)->postJson('/admin/packages/probe', [
            'type' => 'composer',
            'repository_url' => 'https://github.com/acme/tools.git',
        ])->assertOk();
    }

    $this->actingAs($user)->postJson('/admin/packages/probe', [
        'type' => 'composer',
        'repository_url' => 'https://github.com/acme/tools.git',
    ])->assertStatus(429);
});

it('keeps the probe budget per account, so one tenant cannot spend another tenant\'s', function () {
    Process::fake(['*ls-remote*' => Process::result(output: "ref: refs/heads/main\tHEAD\n")]);
    $noisy = probeHardeningAdmin();
    $quiet = probeHardeningAdmin();

    foreach (range(1, 11) as $ignored) {
        $this->actingAs($noisy)->postJson('/admin/packages/probe', [
            'type' => 'composer',
            'repository_url' => 'https://github.com/acme/tools.git',
        ]);
    }

    $this->actingAs($quiet)->postJson('/admin/packages/probe', [
        'type' => 'composer',
        'repository_url' => 'https://github.com/acme/tools.git',
    ])->assertOk();
});

it('writes an audit record naming the actor, the target and the outcome', function () {
    Process::fake(['*ls-remote*' => Process::result(output: '', errorOutput: 'fatal: Repository not found.', exitCode: 128)]);
    $user = probeHardeningAdmin();

    Log::shouldReceive('warning')->zeroOrMoreTimes();
    Log::shouldReceive('info')
        ->once()
        ->withArgs(fn (string $message, array $context): bool => $message === 'Repository probe.'
            && $context['user_id'] === $user->id
            && $context['repository_url'] === 'https://github.com/acme/tools.git'
            && $context['ok'] === false);

    $this->actingAs($user)->postJson('/admin/packages/probe', [
        'type' => 'composer',
        'repository_url' => 'https://github.com/acme/tools.git',
    ])->assertOk();
});

it('keeps an inline credential out of the audit record', function () {
    Process::fake(['*ls-remote*' => Process::result(output: "ref: refs/heads/main\tHEAD\n")]);
    $user = probeHardeningAdmin();

    Log::shouldReceive('warning')->zeroOrMoreTimes();
    Log::shouldReceive('info')
        ->once()
        ->withArgs(fn (string $message, array $context): bool => $message === 'Repository probe.'
            && ! str_contains((string) $context['repository_url'], 'ghp_supersecrettoken'));

    $this->actingAs($user)->postJson('/admin/packages/probe', [
        'type' => 'composer',
        'repository_url' => 'https://x-access-token:ghp_supersecrettoken@github.com/acme/tools.git',
    ])->assertOk();
});
