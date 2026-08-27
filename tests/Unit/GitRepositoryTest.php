<?php

use App\Services\Vcs\GitRepository;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
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

it('aborts instead of syncing unlocked when another sync holds the mirror lock', function () {
    // Falling through and working anyway is what the previous version did, and it is the
    // one thing this lock must never do: with three or more callers on one mirror the
    // timeout is reached while a holder is genuinely mid-clone, so the fallback ran the
    // delete below against a live clone — exactly the race the lock was added to prevent.
    config(['kontorfix.mirror_lock_wait' => 0]);

    $key = 'test-pkg-'.uniqid();

    // Held by someone else for the whole call and deliberately never released.
    expect(Cache::lock('mirror:'.$key, 900, 'someone-else')->get())->toBeTrue();

    $repo = new GitRepository('file://'.FixtureRepo::make(), $key);

    expect(fn () => $repo->sync())->toThrow(RuntimeException::class);
    expect(is_dir(storage_path('app/vcs/'.$key.'.git')))->toBeFalse();
});

it('leaves an existing mirror untouched when it cannot get the mirror lock', function () {
    // The abort has to happen *before* performSync(), not somewhere inside it: a caller
    // that gives up must not have deleted anything on its way out.
    config(['kontorfix.mirror_lock_wait' => 0]);

    $key = 'test-pkg-'.uniqid();
    $mirror = storage_path('app/vcs/'.$key.'.git');
    mkdir($mirror, 0775, true);
    file_put_contents($mirror.'/stray', 'not a repo'); // Repairable: the delete path

    expect(Cache::lock('mirror:'.$key, 900, 'someone-else')->get())->toBeTrue();

    $repo = new GitRepository('file://'.FixtureRepo::make(), $key);

    $threw = false;

    try {
        $repo->sync();
    } catch (RuntimeException) {
        $threw = true;
    }

    // Checked in this order deliberately: the failure that matters is the directory being
    // gone, not the missing exception. A version that falls through and works unlocked
    // should be reported as "it deleted the mirror", which is the actual defect.
    expect(is_file($mirror.'/stray'))->toBeTrue()
        ->and($threw)->toBeTrue();
});

it('waits for a busy mirror lock and repairs only once it actually holds it', function () {
    // The behaviour the lock exists for, rather than its edges: a second sync() on the same
    // mirror does not run alongside the first, it waits for it. Modelled with a holder that
    // goes away on its own (a TTL that expires) because the alternative — a second sync
    // running concurrently — is what this test would have to permit to observe it in one
    // process, and that is the very thing being ruled out.
    config(['kontorfix.mirror_lock_wait' => 30]);

    $key = 'test-pkg-'.uniqid();
    $mirror = storage_path('app/vcs/'.$key.'.git');
    mkdir($mirror, 0775, true);
    file_put_contents($mirror.'/stray', 'not a repo'); // Repairable: the delete path

    expect(Cache::lock('mirror:'.$key, 1, 'someone-else')->get())->toBeTrue();

    // The fixture is built BEFORE the clock starts. It spawns seven git processes and takes
    // a noticeable fraction of a second; measured inside the window it would count towards
    // $elapsed, and on a loaded machine it could clear the 1.0s threshold on its own — a
    // version of sync() that never blocked at all would then still pass. That is a silent
    // false green rather than a flake, and this test exists precisely because the property
    // was previously untested.
    $repo = new GitRepository('file://'.FixtureRepo::make(), $key);

    $started = microtime(true);
    $repo->sync();
    $elapsed = microtime(true) - $started;

    // It blocked for the holder rather than proceeding immediately...
    expect($elapsed)->toBeGreaterThanOrEqual(1.0);
    // ...and the destructive repair happened afterwards, not alongside.
    expect(is_file($mirror.'/stray'))->toBeFalse()
        ->and(is_file($mirror.'/HEAD'))->toBeTrue();
});

it('releases the mirror lock after a sync so a concurrent sync of the same mirror is not blocked', function () {
    $key = 'test-pkg-'.uniqid();
    $repo = new GitRepository('file://'.FixtureRepo::make(), $key);
    $repo->sync();

    // If sync() had not released the lock, acquiring it fresh here would fail.
    expect(Cache::lock('mirror:'.$key, 900, 'someone-else')->get())->toBeTrue();
});

it('releases the mirror lock when the sync fails, not only when it succeeds', function () {
    // The release lives in a finally, which is easy to move out of by accident and
    // impossible to notice: the failing sync reports the failure either way. What differs
    // is everything afterwards — a lock left held wedges the mirror for its full TTL, so
    // the retry that would have fixed a transient failure aborts instead, and the third one
    // fires a failure digest for a package that was only briefly unreachable.
    $key = 'test-pkg-'.uniqid();
    $repo = new GitRepository('file:///does/not/exist-'.uniqid(), $key);

    expect(fn () => $repo->sync())->toThrow(RuntimeException::class);
    expect(Cache::lock('mirror:'.$key, 900, 'someone-else')->get())->toBeTrue();
});

it('still waits for the mirror lock when the config key is missing entirely', function () {
    // Covers the `??` fallback in sync(), which every other test in this file steps over by
    // setting the value explicitly. A fallback of 0 — or a caller that dropped the default
    // argument, so config() returns null — would turn a missing key into "never wait", i.e.
    // abort on the first contended sync, silently.
    $kontorfix = config('kontorfix');
    unset($kontorfix['mirror_lock_wait']);
    config(['kontorfix' => $kontorfix]);

    expect(config('kontorfix.mirror_lock_wait'))->toBeNull();

    $key = 'test-pkg-'.uniqid();
    expect(Cache::lock('mirror:'.$key, 1, 'someone-else')->get())->toBeTrue();

    $repo = new GitRepository('file://'.FixtureRepo::make(), $key);

    $started = microtime(true);
    $repo->sync();

    expect(microtime(true) - $started)->toBeGreaterThanOrEqual(1.0)
        ->and(is_file(storage_path('app/vcs/'.$key.'.git/HEAD')))->toBeTrue();
});

// `git clone` refuses any path that already has a directory entry, so a mirror path that is
// occupied by something which is not a directory fails identically on every retry, forever.
// Classifying those as Absent (which a bare is_dir() check does) is what made that
// permanent: the repairs that would work — unlinking the entry, or renaming it aside when
// it belongs to another uid — are only reachable from Repairable and ForeignOwner.

it('re-clones over a dangling symlink sitting at the mirror path', function () {
    $key = 'test-pkg-'.uniqid();
    $mirror = storage_path('app/vcs/'.$key.'.git');
    if (! is_dir(dirname($mirror))) {
        mkdir(dirname($mirror), 0775, true);
    }
    // Dangling on purpose: file_exists() follows the link and reports false, so this is the
    // one shape an is_dir()/file_exists() pair misses entirely.
    symlink('/does/not/exist-'.uniqid(), $mirror);

    $repo = new GitRepository('file://'.FixtureRepo::make(), $key);
    $repo->sync();

    expect(is_link($mirror))->toBeFalse()
        ->and(is_file($mirror.'/HEAD'))->toBeTrue()
        ->and($repo->tags())->toContain('v1.0.0');
});

it('re-clones over a stray file sitting at the mirror path', function () {
    $key = 'test-pkg-'.uniqid();
    $mirror = storage_path('app/vcs/'.$key.'.git');
    if (! is_dir(dirname($mirror))) {
        mkdir(dirname($mirror), 0775, true);
    }
    file_put_contents($mirror, 'not a directory');

    $repo = new GitRepository('file://'.FixtureRepo::make(), $key);
    $repo->sync();

    // Removing the entry needs write permission on the parent only — the same permission
    // the clone that follows already depends on — so File::deleteDirectory(), which is a
    // silent no-op on a file, must not be the tool used here.
    expect(is_dir($mirror))->toBeTrue()
        ->and(is_file($mirror.'/HEAD'))->toBeTrue()
        ->and($repo->tags())->toContain('v1.0.0');
});

// A mirror owned by another uid cannot be deleted by this service — that needs write
// permission *inside* it — but it can be renamed aside, which needs write permission only on
// storage/app/vcs. A symlink to a root-owned directory is how that state is produced here
// without privileges: MirrorState::of() stats through the link, so it sees the foreign owner,
// while rename() moves the link itself and never touches what it points at.

it('moves a foreign-owned mirror aside and clones a fresh one in its place', function () {
    Log::spy();

    $key = 'test-pkg-'.uniqid();
    $mirror = storage_path('app/vcs/'.$key.'.git');
    if (! is_dir(dirname($mirror))) {
        mkdir(dirname($mirror), 0775, true);
    }
    symlink('/usr', $mirror); // root-owned, and unwritable by this uid either way

    $owner = fileowner($mirror);
    expect($owner)->not->toBe(posix_geteuid());

    $repo = new GitRepository('file://'.FixtureRepo::make(), $key);
    $repo->sync();

    $displaced = glob(dirname($mirror).'/'.$key.'.git.foreign-*') ?: [];

    try {
        expect($displaced)->toHaveCount(1)
            // The link moved; its target was never followed, let alone emptied.
            ->and(is_link($displaced[0]))->toBeTrue()
            ->and(readlink($displaced[0]))->toBe('/usr')
            // A displaced name can never be mistaken for a mirror: mirrors end in `.git`.
            ->and(basename($displaced[0]))->toMatch('/\.git\.foreign-\d{8}-\d{6}-[0-9a-f]{8}$/')
            // ...and the package is working again without an operator.
            ->and(is_link($mirror))->toBeFalse()
            ->and(is_file($mirror.'/HEAD'))->toBeTrue()
            ->and($repo->tags())->toContain('v1.0.0');
    } finally {
        foreach ($displaced as $link) {
            unlink($link);
        }
    }

    // The sync succeeded, so sync_error is cleared and the log is the only place the
    // permanent residue is visible at all. It has to name the directory and still carry the
    // fleet-wide chown that makes the whole case go away.
    //
    // The uid is asserted because it is the one part of this message that can only be read
    // *before* the rename: afterwards there is nothing at $mirror to stat, fileowner()
    // returns false and the message degrades to "gehörte uid unbekannt" — a plausible
    // string, produced by a plausible reordering, that a check on "chown -R" alone would
    // wave through while telling the operator nothing about who to chown from.
    Log::shouldHaveReceived('warning')->withArgs(function (string $message, array $context) use ($mirror, $owner) {
        return str_contains($message, 'foreign-owned git mirror')
            && $context['mirror'] === $mirror
            && str_contains($context['displaced'], '.git.foreign-')
            && str_contains($context['remedy'], 'chown -R')
            && str_contains($context['remedy'], 'gehörte uid '.$owner);
    })->once();
});

it('falls back to the actionable message when a foreign-owned mirror cannot be moved aside either', function () {
    $key = 'test-pkg-'.uniqid();
    $parent = storage_path('app/vcs');
    if (! is_dir($parent)) {
        mkdir($parent, 0775, true);
    }
    $mirror = $parent.'/'.$key.'.git';
    symlink('/usr', $mirror);

    // Renaming needs write permission on the parent — the same permission the fresh clone
    // needs. Take it away and displacement becomes impossible, which is the read-only
    // volume / wrong-owner-on-vcs case. There is nothing left to do but tell the operator.
    chmod($parent, 0555);

    try {
        $repo = new GitRepository('file://'.FixtureRepo::make(), $key);

        expect(fn () => $repo->sync())->toThrow(RuntimeException::class, 'chown -R');
        // Nothing was moved and nothing was invented in its place.
        expect(glob($parent.'/'.$key.'.git.foreign-*'))->toBe([])
            ->and(is_link($mirror))->toBeTrue();
    } finally {
        chmod($parent, 0775);
        unlink($mirror);
    }
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
