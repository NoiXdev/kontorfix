<?php

use App\Services\Vcs\MirrorState;
use Illuminate\Support\Facades\Process;

function makeBareRepo(string $path): void
{
    mkdir($path, 0775, true);
    exec('git init --bare --quiet '.escapeshellarg($path));
}

it('reports an absent mirror', function () {
    expect(MirrorState::of(sys_get_temp_dir().'/does-not-exist-'.uniqid()))->toBe(MirrorState::Absent);
});

it('reports a healthy bare repository as usable', function () {
    $path = sys_get_temp_dir().'/mirror-ok-'.uniqid().'.git';
    makeBareRepo($path);

    expect(MirrorState::of($path))->toBe(MirrorState::Usable);
});

it('reports a directory that is not a repository as repairable', function () {
    $path = sys_get_temp_dir().'/mirror-broken-'.uniqid().'.git';
    mkdir($path, 0775, true);
    file_put_contents($path.'/stray', 'not a repo');

    expect(MirrorState::of($path))->toBe(MirrorState::Repairable);
});

it('reports a half-written repository as repairable', function () {
    $path = sys_get_temp_dir().'/mirror-half-'.uniqid().'.git';
    makeBareRepo($path);
    unlink($path.'/HEAD');

    expect(MirrorState::of($path))->toBe(MirrorState::Repairable);
});

it('classifies the path itself, not an ancestor directory that happens to be a bare repository', function () {
    $bare = sys_get_temp_dir().'/mirror-ancestor-'.uniqid().'.git';
    makeBareRepo($bare);

    // A stray subdirectory living inside a real bare repository's own tree. It is not a
    // mirror itself, but git's ordinary repository discovery (no --git-dir pin) starts at
    // this path and walks upward, so an unpinned check run from here finds the ancestor
    // bare repo and answers a question about *that* repository instead of about $stray —
    // reporting "true" (bare) and misclassifying a broken path as Usable.
    $stray = $bare.'/extra-stray';
    mkdir($stray, 0775, true);
    file_put_contents($stray.'/whatever', 'not a repo');

    expect(MirrorState::of($stray))->toBe(MirrorState::Repairable);
});

it('reports a failure instead of guessing Repairable when the usability check itself cannot be trusted', function () {
    $path = sys_get_temp_dir().'/mirror-ambiguous-'.uniqid().'.git';
    mkdir($path, 0775, true);

    // Simulates any failure of the check that is not git positively saying "this is not a
    // repository" — a missing binary, a permissions surprise, a flaky filesystem, a timeout
    // under load. None of these are evidence the mirror is broken.
    Process::fake([
        '*rev-parse*' => Process::result(
            errorOutput: 'fatal: unable to read current working directory: No such file or directory',
            exitCode: 128,
        ),
    ]);

    expect(fn () => MirrorState::of($path))->toThrow(RuntimeException::class);
});

it('names both uids in the foreign-owner message and says what to do', function () {
    $message = MirrorState::foreignOwnerMessage('/app/storage/app/vcs/abc.git');

    expect($message)->toContain('/app/storage/app/vcs/abc.git')
        ->and($message)->toContain((string) posix_geteuid());
});
