<?php

use App\Enums\PackageType;
use App\Services\Vcs\RepositoryProbe;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use Tests\Support\FixtureRepo;

/**
 * Exercises the real RepositoryProbe against local git fixtures (no Process fake), so the
 * actual ls-remote/clone/manifest-read chain is covered end to end — this is what catches
 * regressions like the "HEAD" positional that once suppressed all tags.
 */
it('discovers name, description, default branch and version tags for a composer repo', function () {
    $result = (new RepositoryProbe)->probe(PackageType::Composer, 'file://'.FixtureRepo::make('acme/demo'));

    expect($result['ok'])->toBeTrue()
        ->and($result['name'])->toBe('acme/demo')
        ->and($result['description'])->toBe('Demo package v2')
        ->and($result['default_branch'])->toBe('main')
        ->and($result['versions'])->toBe(['v1.1.0', 'v1.0.0']);
});

it('reads the name from a package.json for an npm repo', function () {
    $result = (new RepositoryProbe)->probe(PackageType::Npm, 'file://'.FixtureRepo::makeNpm('@acme/widget'));

    expect($result['ok'])->toBeTrue()
        ->and($result['name'])->toBe('@acme/widget')
        ->and($result['versions'])->toBe(['v1.1.0', 'v1.0.0']);
});

it('reads the name from a pyproject.toml for a python repo', function () {
    $result = (new RepositoryProbe)->probe(PackageType::Python, 'file://'.FixtureRepo::makePython('acme-lib'));

    expect($result['ok'])->toBeTrue()
        ->and($result['name'])->toBe('acme-lib')
        ->and($result['versions'])->toBe(['v1.1.0', 'v1.0.0']);
});

it('reports a repository with no manifest as missing, not as a silent empty name', function () {
    $result = (new RepositoryProbe)->probe(PackageType::Composer, 'file://'.FixtureRepo::makeWithoutManifest());

    expect($result['ok'])->toBeTrue()
        ->and($result['name'])->toBeNull()
        // Nothing failed — the repository simply has no composer.json, and the operator
        // only needs to type the name.
        ->and($result['manifest'])->toBe('missing')
        ->and($result['manifest_file'])->toBe('composer.json');
});

it('reports a manifest it could not read as unreadable, and says so in the log', function () {
    // A reachable repository whose manifest read fails used to be indistinguishable from
    // one that has no manifest: both were `ok: true` with a null name and no log line at
    // all. That silence is what made the private-repository case invisible.
    Log::spy();

    $result = (new RepositoryProbe)->probe(PackageType::Composer, 'file://'.FixtureRepo::makeWithBrokenManifest());

    expect($result['ok'])->toBeTrue()
        ->and($result['name'])->toBeNull()
        ->and($result['manifest'])->toBe('unreadable');

    Log::shouldHaveReceived('warning')
        ->withArgs(fn (string $message, array $context) => $message === 'Repository probe could not read the package manifest.'
            && $context['step'] === 'parse'
            && $context['manifest'] === 'composer.json')
        ->once();
});

it('carries the git credential environment into the manifest read, not only the clone', function () {
    Process::fake([
        '*ls-remote*' => Process::result("ref: refs/heads/main\tHEAD\n"),
        '*clone*' => Process::result(''),
        '*ls-tree*' => Process::result("composer.json\n"),
        '*show*' => Process::result('{"name":"acme/tools"}'),
    ]);

    (new RepositoryProbe)->probe(PackageType::Composer, 'https://git.example.com/acme/tools.git', 'ghp_secret');

    // `--filter=blob:none` means the manifest blob is NOT in the clone: `git show` fetches
    // it from the promisor remote, over the network, as a second request. GitAuth's
    // credential and transport hardening live in GIT_CONFIG_* environment variables and are
    // never written into the clone's config, so a `show` that does not carry the
    // environment goes out unauthenticated — 401 on a private repository, and a blank name
    // field with no explanation.
    Process::assertRan(function ($process) {
        if (! str_contains(implode(' ', (array) $process->command), 'show')) {
            return false;
        }

        $env = $process->environment;

        return ($env['GIT_TERMINAL_PROMPT'] ?? null) === '0'
            && collect($env)->contains(fn ($value) => str_contains((string) $value, 'Authorization: Basic'));
    });
});

it('reports an unreachable repository without throwing', function () {
    $result = (new RepositoryProbe)->probe(PackageType::Composer, 'file:///does/not/exist-'.uniqid());

    expect($result['ok'])->toBeFalse()
        ->and($result['versions'])->toBe([]);
});
