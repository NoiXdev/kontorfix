<?php

use App\Services\Vcs\GitRepository;
use Illuminate\Support\Facades\Cache;
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

it('does not let the internal cap signal escape when the abandoned read is torn down', function () {
    // Aborting the read means throwing out of the output callback, and tearing the process
    // down afterwards reads its pipes one last time and calls that callback again. A second
    // throw escapes the capped read entirely — out of stop(), or later out of a destructor
    // and into whatever unrelated code happens to be running, which is how it first showed
    // up: a BlobCapReached reported against a path-traversal test in another file.
    //
    // A tiny budget against a multi-megabyte blob is the shape that reproduces it: git is
    // still streaming when the abort happens, so there is always more output waiting at
    // teardown. Anything escaping here fails this test rather than a random later one.
    $dir = sys_get_temp_dir().'/kfx-fixture-escape-'.uniqid();
    mkdir($dir, 0775, true);
    file_put_contents($dir.'/BIG.md', str_repeat('a', 8 * 1024 * 1024));
    Process::path($dir)->run(['git', 'init', '-q', '-b', 'main'])->throw();
    Process::path($dir)->run(['git', 'add', '-A'])->throw();
    Process::path($dir)
        ->env(['GIT_AUTHOR_NAME' => 'T', 'GIT_AUTHOR_EMAIL' => 't@t.test', 'GIT_COMMITTER_NAME' => 'T', 'GIT_COMMITTER_EMAIL' => 't@t.test'])
        ->run(['git', 'commit', '-q', '-m', 'init'])->throw();

    $repo = new GitRepository('file://'.$dir, 'test-pkg-'.uniqid());
    $repo->sync();

    expect(strlen($repo->fileAtRefCapped('HEAD', 'BIG.md', 16)))->toBe(16);

    // A second capped read on the same mirror has to behave identically — a signal left
    // in flight by the first would land here.
    expect(strlen($repo->fileAtRefCapped('HEAD', 'BIG.md', 16)))->toBe(16);
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

it('re-clones a mirror that exists but is not a usable repository', function () {
    $key = 'test-pkg-'.uniqid();
    $mirror = storage_path('app/vcs/'.$key.'.git');
    mkdir($mirror, 0775, true);
    file_put_contents($mirror.'/stray', 'not a repo');

    $repo = new GitRepository('file://'.FixtureRepo::make(), $key);
    $repo->sync();

    expect(is_file($mirror.'/HEAD'))->toBeTrue()
        ->and(is_file($mirror.'/stray'))->toBeFalse();
});

it('fetches an existing healthy mirror instead of re-cloning it', function () {
    $key = 'test-pkg-'.uniqid();
    $repo = new GitRepository('file://'.FixtureRepo::make(), $key);
    $repo->sync();

    $marker = storage_path('app/vcs/'.$key.'.git/.kept');
    touch($marker);

    $repo->sync();

    // A re-clone would wipe the directory; the marker surviving proves the fetch path ran.
    expect(is_file($marker))->toBeTrue();
});

it('does not delete an existing mirror when the usability check itself is inconclusive', function () {
    $key = 'test-pkg-'.uniqid();
    $mirror = storage_path('app/vcs/'.$key.'.git');
    mkdir($mirror, 0775, true);
    file_put_contents($mirror.'/marker', 'still here');

    // Not git positively saying "this is not a repository" — a stand-in for a timeout, a
    // missing binary, or any other infra hiccup that looks like a broken mirror but isn't
    // evidence of one. Uncertainty must not cause deletion.
    Process::fake([
        '*rev-parse*' => Process::result(
            errorOutput: 'fatal: unable to read current working directory: No such file or directory',
            exitCode: 128,
        ),
    ]);

    $repo = new GitRepository('file://'.FixtureRepo::make(), $key);

    expect(fn () => $repo->sync())->toThrow(RuntimeException::class);
    // The mirror must still be there — an inconclusive check must not have deleted it.
    expect(is_file($mirror.'/marker'))->toBeTrue();
});

it('never empties the target of a symlink sitting at the mirror path when repairing', function () {
    $key = 'test-pkg-'.uniqid();
    $mirror = storage_path('app/vcs/'.$key.'.git');
    if (! is_dir(dirname($mirror))) {
        mkdir(dirname($mirror), 0775, true);
    }

    $target = sys_get_temp_dir().'/kfx-symlink-target-'.uniqid();
    mkdir($target, 0775, true);
    file_put_contents($target.'/precious.txt', 'do not delete me');
    symlink($target, $mirror);

    $repo = new GitRepository('file://'.FixtureRepo::make(), $key);
    $repo->sync();

    // File::deleteDirectory() follows a symlink and empties whatever it points to; a
    // correct repair removes only the link and leaves the target's contents untouched.
    expect(is_file($target.'/precious.txt'))->toBeTrue()
        ->and(is_link($mirror))->toBeFalse()
        ->and(is_file($mirror.'/HEAD'))->toBeTrue();

    File::deleteDirectory($target);
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

// Two versions of the same package, both cold, requested in parallel produce exactly this
// shape: two GitRepository instances built from the same storage key, both calling sync()
// at once. Nothing above GitRepository serialises that (SyncPackage's WithoutOverlapping is
// per package id, and ComposerController's dist lock is per dist file) — see the comment on
// GitRepository::sync() for why the mirror lock exists.

it('still syncs when another sync holds the mirror lock and never lets go', function () {
    // A stuck holder must not hang this call forever; the wait is bounded and the
    // fallback is the unlocked sync that predates the lock.
    config(['kontorfix.mirror_lock_wait' => 0]);

    $key = 'test-pkg-'.uniqid();

    // Held for the whole call and deliberately never released.
    expect(Cache::lock('mirror:'.$key, 330)->get())->toBeTrue();

    $repo = new GitRepository('file://'.FixtureRepo::make(), $key);
    $repo->sync();

    expect($repo->tags())->toContain('v1.0.0');
});

it('releases the mirror lock after a sync so a concurrent sync of the same mirror is not blocked', function () {
    $key = 'test-pkg-'.uniqid();
    $repo = new GitRepository('file://'.FixtureRepo::make(), $key);
    $repo->sync();

    // If sync() had not released the lock, acquiring it fresh here would fail.
    expect(Cache::lock('mirror:'.$key, 330)->get())->toBeTrue();
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
