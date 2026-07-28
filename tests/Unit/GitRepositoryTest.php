<?php

use App\Services\Vcs\GitRepository;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Tests\Support\FixtureRepo;

afterEach(function () {
    File::deleteDirectory(storage_path('app/vcs'));

    foreach (glob(sys_get_temp_dir().'/kfx-fixture-*') ?: [] as $dir) {
        File::deleteDirectory($dir);
    }
});

it('mirrors a repo, lists tags and reads composer.json at a tag', function () {
    $repo = new GitRepository('file://'.FixtureRepo::make(), 'test-pkg-'.uniqid());
    $repo->sync();

    expect($repo->tags())->toContain('v1.0.0', 'v1.1.0');

    $json = json_decode($repo->fileAtRef('v1.0.0', 'composer.json'), true);
    expect($json['name'])->toBe('acme/demo')
        ->and($json['require']['php'])->toBe('>=8.2');
});

it('resolves a commit sha for a ref', function () {
    $repo = new GitRepository('file://'.FixtureRepo::make(), 'test-pkg-'.uniqid());
    $repo->sync();

    expect($repo->commitFor('v1.0.0'))->toMatch('/^[0-9a-f]{40}$/');
});

it('creates a zip archive for a ref', function () {
    $repo = new GitRepository('file://'.FixtureRepo::make(), 'test-pkg-'.uniqid());
    $repo->sync();
    $zip = $repo->archiveZip('v1.0.0');

    expect(file_exists($zip))->toBeTrue()
        ->and(filesize($zip))->toBeGreaterThan(0);
    unlink($zip);
});

it('is idempotent: sync twice fetches instead of recloning', function () {
    $fixture = FixtureRepo::make();
    $key = 'test-pkg-'.uniqid();
    $repo = new GitRepository('file://'.$fixture, $key);
    $repo->sync();

    // add a new tag, sync again
    Process::path($fixture)
        ->run('git tag v1.2.0')->throw();
    $repo->sync();

    expect($repo->tags())->toContain('v1.2.0');
});

it('throws a useful error for unreachable urls', function () {
    $repo = new GitRepository('file:///does/not/exist-'.uniqid(), 'test-pkg-'.uniqid());
    expect(fn () => $repo->sync())->toThrow(RuntimeException::class);
});

it('rejects storage keys that attempt path traversal', function () {
    expect(fn () => new GitRepository('file:///tmp/x', '../../etc/evil'))
        ->toThrow(InvalidArgumentException::class);
});

it('does not let a malicious ref inject git options', function () {
    $repo = new GitRepository('file://'.FixtureRepo::make(), 'test-pkg-'.uniqid());
    $repo->sync();

    // A tag/ref that looks like a git option must not be able to write a file,
    // it must instead fail as an (invalid) object name.
    $evil = '/tmp/kfx-pwned-'.uniqid().'.zip';
    expect(fn () => $repo->archiveZip("--output={$evil}"))->toThrow(RuntimeException::class);
    expect(file_exists($evil))->toBeFalse();
});

it('does not leave the tempnam stub behind when archiving', function () {
    $repo = new GitRepository('file://'.FixtureRepo::make(), 'test-pkg-'.uniqid());
    $repo->sync();

    $stubsBefore = array_filter(glob(sys_get_temp_dir().'/kfx-dist-*'), fn ($f) => ! str_ends_with($f, '.zip'));
    $zip = $repo->archiveZip('v1.0.0');
    $stubsAfter = array_filter(glob(sys_get_temp_dir().'/kfx-dist-*'), fn ($f) => ! str_ends_with($f, '.zip'));

    expect($stubsAfter)->toBe($stubsBefore); // no new stub created by this call
    unlink($zip);
});
