<?php

use App\Services\Vcs\MirrorState;

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

it('names both uids in the foreign-owner message and says what to do', function () {
    $message = MirrorState::foreignOwnerMessage('/app/storage/app/vcs/abc.git');

    expect($message)->toContain('/app/storage/app/vcs/abc.git')
        ->and($message)->toContain((string) posix_geteuid());
});
