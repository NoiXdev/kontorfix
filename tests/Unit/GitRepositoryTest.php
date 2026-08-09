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

it('reports the blob size of every root file alongside its name', function () {
    $repo = new GitRepository('file://'.FixtureRepo::make(), 'test-pkg-'.uniqid());
    $repo->sync();

    $entries = collect($repo->rootFileEntries('v1.0.0'))->keyBy('name');
    $manifest = $repo->fileAtRef('v1.0.0', 'composer.json');

    // The size has to come out of the same listing, not a guess: a caller that has to
    // decide whether a blob is safe to read cannot read it first to find out.
    expect($entries)->toHaveKey('composer.json')
        ->and($entries['composer.json']['size'])->toBe(strlen($manifest));
});

it('stops reading a blob once the byte budget is spent', function () {
    $dir = sys_get_temp_dir().'/kfx-fixture-capped-'.uniqid();
    mkdir($dir, 0775, true);
    file_put_contents($dir.'/BIG.md', str_repeat('a', 4 * 1024 * 1024));
    Process::path($dir)->run(['git', 'init', '-q', '-b', 'main'])->throw();
    Process::path($dir)->run(['git', 'add', '-A'])->throw();
    Process::path($dir)
        ->env(['GIT_AUTHOR_NAME' => 'T', 'GIT_AUTHOR_EMAIL' => 't@t.test', 'GIT_COMMITTER_NAME' => 'T', 'GIT_COMMITTER_EMAIL' => 't@t.test'])
        ->run(['git', 'commit', '-q', '-m', 'init'])->throw();

    $repo = new GitRepository('file://'.$dir, 'test-pkg-'.uniqid());
    $repo->sync();

    // Exactly the budget, not "the whole blob, cut afterwards" — the point of this method
    // is that the remaining megabytes never enter the process at all.
    expect(strlen($repo->fileAtRefCapped('HEAD', 'BIG.md', 8192)))->toBe(8192);
});

it('returns a short blob whole when it fits inside the byte budget', function () {
    $repo = new GitRepository('file://'.FixtureRepo::make(), 'test-pkg-'.uniqid());
    $repo->sync();

    expect($repo->fileAtRefCapped('v1.0.0', 'composer.json', 1024 * 1024))
        ->toBe($repo->fileAtRef('v1.0.0', 'composer.json'));
});

it('still reports a failing git show through the capped reader', function () {
    $repo = new GitRepository('file://'.FixtureRepo::make(), 'test-pkg-'.uniqid());
    $repo->sync();

    // Abandoning the process at the cap must not turn every failure into an empty string.
    expect(fn () => $repo->fileAtRefCapped('v1.0.0', 'does-not-exist.md', 1024))
        ->toThrow(RuntimeException::class);
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
