<?php

namespace App\Services\Vcs;

use App\Enums\GitProvider;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use InvalidArgumentException;
use RuntimeException;
use Symfony\Component\Process\Process as SystemProcess;
use Throwable;

class GitRepository
{
    /**
     * Seconds a mirror lock stays valid without being released.
     *
     * This is not a budget for the work, it is the ceiling on how long a holder that died
     * without releasing can wedge a mirror. Two things have to be true of it, and only the
     * first is a provable relation:
     *
     * 1. **It must not lapse under a live holder.** The longest a live queue holder can
     *    hold this lock is its own job timeout (SyncPackage::TIMEOUT): worst case it
     *    acquires the lock immediately, so the whole of that budget is spent inside the
     *    locked region. LOCK_TTL === SyncPackage::TIMEOUT is therefore the tight, correct
     *    value, and the equality is deliberate — at that instant the worker's own alarm
     *    fires, so the TTL can only expire on a process that is already being killed.
     *    tests/Unit/SyncTimingRelationsTest.php asserts `LOCK_TTL >= the job timeout the
     *    queue payload actually carries`.
     *
     *    The pre-v0.7.1 value, 330s, broke that: the TTL expired while the holder was still
     *    cloning, a waiter acquired the lock, and its first act was a recursive delete of
     *    the directory being cloned into. A lock whose TTL can expire mid-operation is
     *    worse than no lock, because the code around it assumes exclusivity.
     *
     * 2. **It should cover the unbounded part of the work.** Bounded work under the lock is
     *    WORST_CASE_WORK (315s). The unbounded part is the directory delete on the
     *    Repairable path: a half-finished clone of a large repository, unlinked recursively
     *    on an overlay filesystem, is not something a timeout covers. This is a judgement,
     *    not a guarantee — nothing here can bound it.
     *
     *    The number that actually rations the delete is not this one. The worker's alarm
     *    fires SyncPackage::TIMEOUT after the job started, and it fires *inside* the locked
     *    region, so the delete's real budget is `TIMEOUT - mirror_lock_wait -
     *    WORST_CASE_WORK` = 900 - 330 - 315 = 255s, and everything after sync() (version
     *    import, README render) shares whatever is left of that. LOCK_TTL's larger apparent
     *    headroom (585s) is unreachable for a queue holder; it exists for the web holder,
     *    which runs under no alarm at all. Where the two disagree, the alarm wins.
     *
     * The cost of a value this large is paid whenever a holder dies without releasing: the
     * mirror is unusable for up to 15 minutes. config/horizon.php and docker/compose.yaml
     * are configured to make the routine causes of that (autoscaler scale-down, deploy
     * restart) stop happening — but SIGKILL, OOM and host failure remain, and no
     * configuration removes them. Do not read this constant as "a killed holder is
     * impossible"; read it as "when it happens anyway, this is how long it costs".
     */
    public const LOCK_TTL = 900;

    /** `git clone --mirror` timeout, in seconds. The slowest single step under the lock. */
    public const CLONE_TIMEOUT = 300;

    /** `git fetch` timeout, in seconds — the Usable path, always cheaper than a clone. */
    public const FETCH_TIMEOUT = 120;

    /** Timeout for every git command that runs *inside* the mirror (ls-tree, show, archive). */
    public const COMMAND_TIMEOUT = 120;

    /**
     * The longest a healthy sync can hold the mirror lock, ignoring the unbounded delete:
     * classify the mirror, then clone it. Everything else in this file's timing budget is
     * expressed against this number rather than against a literal, so that raising a git
     * timeout moves the lock TTL, the wait and the job timeout with it.
     */
    public const WORST_CASE_WORK = MirrorState::CHECK_TIMEOUT + self::CLONE_TIMEOUT;

    /**
     * Fallback for `kontorfix.mirror_lock_wait` when the key is absent entirely.
     *
     * Chosen so a waiter outlasts any *live* holder (WORST_CASE_WORK, plus slack) rather
     * than being shorter than the work it waits for. See sync() for why that matters.
     *
     * This is the *queue* caller's wait. The web caller uses DEFAULT_WEB_LOCK_WAIT.
     */
    public const DEFAULT_LOCK_WAIT = self::WORST_CASE_WORK + 15;

    /**
     * Fallback for `kontorfix.mirror_lock_wait_web` — the wait a *synchronous HTTP request*
     * may spend blocked on this lock.
     *
     * Deliberately two orders of magnitude below DEFAULT_LOCK_WAIT, and the reason is not
     * politeness towards the client. See sync() for the whole argument; the short version is
     * that a blocked web request is a thread of a small, shared pool, and the queue's wait
     * applied to the web path can park the entire pool — including the container
     * healthcheck — for minutes.
     *
     * 15s is what a *fetch* takes at worst on the Usable path (FETCH_TIMEOUT is 120, but a
     * fetch of an already-mirrored repository is normally sub-second), so a request that
     * arrives while a cheap sync is in flight still gets served from the finished mirror.
     * A request that arrives while a *clone* is in flight does not, and must not: it gets
     * 503 + Retry-After instead of holding a thread for five minutes.
     */
    public const DEFAULT_WEB_LOCK_WAIT = 15;

    private string $mirrorPath;

    /** @var array<string, string> */
    private array $authEnv;

    public function __construct(
        private readonly string $url,
        private readonly string $storageKey,
        ?string $token = null,
        ?GitProvider $provider = null,
        ?string $username = null,
    ) {
        if (! preg_match('/^[A-Za-z0-9._-]+$/', $storageKey) || str_contains($storageKey, '..')) {
            throw new InvalidArgumentException('Invalid storage key.');
        }

        $this->mirrorPath = storage_path('app/vcs/'.$storageKey.'.git');
        $this->authEnv = GitAuth::env($url, $token, $provider, $username);
    }

    /**
     * @param  int|null  $waitSeconds  How long to block on the mirror lock. Null means the
     *                                 queue caller's budget (`kontorfix.mirror_lock_wait`);
     *                                 a synchronous HTTP caller must pass its own, much
     *                                 shorter one. See the comment inside.
     *
     * @throws MirrorLockBusy when the wait runs out
     */
    public function sync(?int $waitSeconds = null): void
    {
        // The only outbound operation on this class — every other method runs inside the
        // local mirror. Guarding here covers the queued SyncPackage job, packages:resync,
        // the incoming-webhook trigger and the registry dist path in one place.
        $rejection = GitUrlSafety::reject($this->url);
        if ($rejection !== null) {
            throw new RuntimeException($rejection);
        }

        // SyncPackage serialises against itself per package id (WithoutOverlapping), and
        // ComposerController::dist() takes a lock per *dist file*. Neither stops two
        // separate calls from reaching this method for the same mirror at the same time —
        // e.g. two versions of the same package, both cold, requested in parallel by a
        // parallel `composer install`. If the mirror is Repairable for both, the second
        // call's delete below can remove the first call's directory mid-clone or
        // mid-archive. That is not data corruption (the dist zip is staged to a temp path
        // and renamed only once complete, elsewhere), just a wasted re-clone and an error
        // response — but the delete is still the first destructive operation on a path
        // several requests can share, so it is worth serialising properly rather than
        // leaving it to chance.
        //
        // Two decisions here, and they depend on each other.
        //
        // **The wait is the caller's, not this method's**, because the two callers pay for
        // it in different currencies and the single shared value was wrong for one of them
        // whichever way it was set.
        //
        // - The **queue** caller (SyncPackage, $waitSeconds === null →
        //   `kontorfix.mirror_lock_wait`, default DEFAULT_LOCK_WAIT = WORST_CASE_WORK + 15)
        //   wants a wait that outlasts the work. Anything shorter turns the abort below
        //   from "the holder is wedged" into "the holder is busy", and the two have
        //   opposite correct answers: a wedged holder means give up, a busy one means wait
        //   for the mirror it is about to finish. Waiting costs that caller a parked worker
        //   process out of a pool sized for exactly this, and its own job timeout covers
        //   the wait plus the work after it.
        //
        // - The **web** caller (ComposerController::dist(), which passes
        //   `kontorfix.mirror_lock_wait_web`, default DEFAULT_WEB_LOCK_WAIT = 15) pays in
        //   server threads, and it does not own them. The app container runs
        //   `frankenphp php-server` in classic mode: every request occupies one thread of a
        //   pool sized from the CPU count, and that pool serves the whole registry —
        //   metadata, downloads, the admin UI and the container healthcheck `/up`. A CI
        //   fleet asking for N cold versions of one package in parallel takes N *different*
        //   `dist-build:` locks, so that lock does not serialise them and all N arrive
        //   here. One clones; the rest block. Give them the queue's wait and every one of
        //   those threads is parked for up to five and a half minutes, `/up` stops being
        //   answered inside the healthcheck's 5s×5 budget, the container goes unhealthy and
        //   the proxy takes it out of rotation — a whole-registry outage produced by a
        //   feature whose purpose is to avoid one. With the short wait the same burst
        //   parks each thread for 15s and then answers 503 + Retry-After (see
        //   MirrorLockBusy and ComposerController::dist()).
        //
        //   What the web caller loses is the case the long wait was introduced for: a
        //   second cold version requested while the first is cloning is no longer served
        //   from the finished mirror, it is told to come back. That is the trade, stated
        //   plainly — a bounded 503 the client retries beats an unbounded wait that can
        //   take the instance down. It does still cover the common contention, which is a
        //   *fetch* rather than a clone.
        //
        //   Note what this does **not** fix: the request that *wins* the lock still
        //   performs the clone inline, on that same thread, for up to WORST_CASE_WORK. The
        //   lazy dist build has always worked that way, and N cold versions of N
        //   *different* packages still occupy N threads for the length of a clone. Moving
        //   the build off the request is a larger change than this one.
        //
        // **The timeout aborts** rather than falling through unlocked. Running the delete
        // below against a live cloning holder is precisely the race the lock was added to
        // prevent, and the asymmetry with the per-dist lock (which does fall through) is
        // deliberate: building a dist twice is wasteful, deleting a mirror twice is
        // destructive.
        //
        // Aborting stays affordable because it is idempotent, visible and retried:
        // SyncPackage backs off [60, 300, 900] under a retryUntil window that does not
        // count contention as a failed attempt, and a dist request gets a 503 it can
        // repeat. A delete race, by contrast, destroys a running clone and reports it as
        // "git clone failed", which marks the package failed and fires the digest for real.
        $lock = Cache::lock('mirror:'.$this->storageKey, self::LOCK_TTL);

        try {
            $lock->block($waitSeconds ?? (int) config('kontorfix.mirror_lock_wait', self::DEFAULT_LOCK_WAIT));
        } catch (LockTimeoutException) {
            throw new MirrorLockBusy;
        }

        try {
            $this->performSync();
        } finally {
            $lock->release();
        }
    }

    private function performSync(): void
    {
        $state = MirrorState::of($this->mirrorPath);

        if ($state === MirrorState::ForeignOwner) {
            $state = $this->displaceForeignMirror();
        }

        // A mirror we own but cannot fetch into is worth less than the seconds a fresh clone
        // costs. Dropping it here means one bad clone does not wedge a package forever.
        if ($state === MirrorState::Repairable) {
            // Three shapes reach this branch and only one of them is a directory to empty.
            //
            // File::deleteDirectory() follows a symlink at the given path and empties the
            // *target's* contents instead of removing the link itself — never call it on a
            // link. Nothing in this codebase creates a symlink at a mirror path today, but
            // nothing guarantees this location can never become one either; unlinking the
            // entry is safe regardless of what it points to, whereas deleting through it is
            // not. It is also a silent no-op on a plain file, which would leave the entry
            // in place for `git clone` to refuse.
            //
            // Ordered so that a path which no longer exists at all falls through untouched:
            // displaceForeignMirror() reports Absent after renaming, and a future change
            // that reported Repairable there instead must not turn into unlink() on
            // nothing.
            if (is_dir($this->mirrorPath) && ! is_link($this->mirrorPath)) {
                File::deleteDirectory($this->mirrorPath);
            } elseif (is_link($this->mirrorPath) || file_exists($this->mirrorPath)) {
                unlink($this->mirrorPath);
            }
            $state = MirrorState::Absent;
        }

        if ($state === MirrorState::Usable) {
            // fetch needs the auth header too (the mirror's stored URL is token-free).
            $result = Process::path($this->mirrorPath)->env($this->authEnv)->timeout(self::FETCH_TIMEOUT)
                ->run(['git', 'fetch', '--prune', '--tags', 'origin']);

            if (! $result->successful()) {
                throw new RuntimeException('git fetch failed: '.GitAuth::scrub($result->errorOutput()));
            }

            return;
        }

        if (! is_dir(dirname($this->mirrorPath))) {
            mkdir(dirname($this->mirrorPath), 0775, true);
        }

        $result = Process::env($this->authEnv)->timeout(self::CLONE_TIMEOUT)->run([
            'git', 'clone', '--mirror', '-c', 'protocol.file.allow=always', $this->url, $this->mirrorPath,
        ]);

        if (! $result->successful()) {
            throw new RuntimeException('git clone failed: '.GitAuth::scrub($result->errorOutput()));
        }
    }

    /**
     * Moves a mirror owned by another uid out of the way so the clone below can take its
     * place, and reports Absent. Throws — with the old actionable message — only if even
     * that is impossible.
     *
     * The v0.7.0 incident proved this service cannot *delete* a root-owned mirror: emptying
     * `<key>.git` needs write permission inside `<key>.git`, which uid 33 does not have.
     * It says nothing about renaming one. `rename()` touches only the directory entry, so
     * it needs write permission on the parent, `storage/app/vcs` — the very permission the
     * fresh `git clone` two lines later already depends on. If we can re-clone at all, we
     * can displace; if we cannot displace, the re-clone would have failed too, which is why
     * the fallback is the unchanged "here is what to run" message rather than a retry.
     *
     * What this does not do is delete the displaced directory afterwards. It cannot — that
     * is the same permission problem, just at a different path — so the copy stays on the
     * volume for good. That is the honest price of self-healing and the reason for the
     * log line: the sync it accompanies *succeeds*, clearing `sync_error`, so without the
     * log the residue would appear nowhere at all. It is bounded, not a leak: displacing a
     * mirror replaces it with one this service owns, so a given package pays it once per
     * time something external puts a foreign-owned directory there — in practice once, on
     * the upgrade this whole case exists for. Warning rather than error because nothing is
     * broken; there is an action item, and it is one command for the whole fleet.
     *
     * The suffix carries a timestamp (so an operator can tell displacements apart and see
     * how old they are) and four random bytes (so repeated runs cannot collide — the
     * timestamp alone has one-second resolution). A collision would not be silent anyway:
     * rename() onto a non-empty directory fails and lands in the fallback below. The name
     * can also never be mistaken for a mirror, because every mirror path ends in `.git`
     * and every displaced path ends in hex.
     */
    private function displaceForeignMirror(): MirrorState
    {
        $displaced = sprintf('%s.foreign-%s-%s', $this->mirrorPath, date('Ymd-His'), bin2hex(random_bytes(4)));

        // Built before the rename: it names the owning uid, which is read off the
        // directory that is about to move.
        $notice = MirrorState::displacedMessage($this->mirrorPath, $displaced);

        if (! @rename($this->mirrorPath, $displaced)) {
            // Leads with the *action* that failed rather than with the subject, because the
            // sentence that follows opens with "Der Git-Mirror …" — two sentences in a row
            // starting the same way read as a copy-paste slip to the operator seeing them.
            throw new RuntimeException(
                'Automatisches Verschieben zur Seite ist fehlgeschlagen — hier hilft nur ein '
                .'manueller Eingriff. '.MirrorState::foreignOwnerMessage($this->mirrorPath)
            );
        }

        Log::warning('Displaced a foreign-owned git mirror; the displaced copy is never removed by this service.', [
            'mirror' => $this->mirrorPath,
            'displaced' => $displaced,
            'remedy' => $notice,
        ]);

        return MirrorState::Absent;
    }

    /** @return list<string> */
    public function tags(): array
    {
        return array_values(array_filter(explode("\n", $this->run(['git', 'tag', '-l'])->output())));
    }

    public function commitFor(string $ref): string
    {
        return trim($this->run(['git', 'rev-list', '-n', '1', '--end-of-options', $ref])->output());
    }

    /**
     * Committer date of the ref as an ISO-8601 string — stable across re-syncs,
     * unlike now().
     */
    public function committedAt(string $ref): string
    {
        return trim($this->run(['git', 'log', '-1', '--format=%cI', '--end-of-options', $ref])->output());
    }

    public function fileAtRef(string $ref, string $path): string
    {
        // --end-of-options prevents a ref/path like "--output=..." from being
        // interpreted as a git option (option injection from a malicious upstream tag).
        return $this->run($this->showCommand($ref, $path))->output();
    }

    /**
     * Same as fileAtRef(), but never lets more than $maxBytes of the blob into this
     * process — the whole point being that the remainder is never read at all.
     *
     * fileAtRef() hands `git show`'s entire stdout to the caller as one string, so a
     * repository carrying a multi-hundred-megabyte file exhausts `memory_limit`. That is a
     * PHP fatal rather than a Throwable: nothing catches it, the worker process dies, and
     * the queue replays the job. A caller that already knows the blob is oversize (see
     * rootFileEntries(), which reports each blob's size) must therefore have a way to read
     * a bounded prefix instead of reading everything and cutting afterwards.
     *
     * Symfony's runner offers no way for an output callback to say "stop"; throwing out of
     * it is the abort. Output arrives in chunks, so the buffer briefly holds up to one chunk
     * more than the budget before it is cut back — bounded either way, which is what matters
     * here.
     *
     * Two details that are not optional:
     *
     * - The callback throws **once**. Tearing the process down reads its pipes one last
     *   time and calls the callback again, and a second throw escapes from *there* —
     *   outside this try/catch, during stop() or, worse, during garbage collection, where
     *   it surfaces as a BlobCapReached inside whatever unrelated code happens to be
     *   running. The $capped latch makes every call after the first a no-op.
     * - git is stopped explicitly rather than left to the destructor, so the abandoned
     *   `git show` ends when this method does and not whenever the process object is
     *   collected.
     *
     * A process that ends on its own without reaching the budget is checked for success as
     * usual: abandoning at the cap must not quietly turn every git failure into "".
     *
     * @throws RuntimeException if git fails before the budget is reached
     */
    public function fileAtRefCapped(string $ref, string $path, int $maxBytes): string
    {
        $buffer = '';
        $capped = false;

        $process = Process::path($this->mirrorPath)->timeout(self::COMMAND_TIMEOUT)->start(
            $this->showCommand($ref, $path),
            function (string $type, string $chunk) use (&$buffer, &$capped, $maxBytes) {
                if ($capped || $type !== SystemProcess::OUT) {
                    return;
                }

                $buffer .= $chunk;

                if (strlen($buffer) >= $maxBytes) {
                    $capped = true;

                    throw new BlobCapReached;
                }
            },
        );

        try {
            $result = $process->wait();
        } catch (Throwable $e) {
            // The latch, not the exception type, decides whether this was our own abort:
            // a timeout or any other genuine failure leaves it false and must propagate
            // rather than be reported as a successful short read.
            if (! $capped) {
                throw $e;
            }

            $process->stop();

            return substr($buffer, 0, $maxBytes);
        }

        if (! $result->successful()) {
            throw new RuntimeException('git show failed: '.$result->errorOutput());
        }

        return substr($buffer, 0, $maxBytes);
    }

    /** @return list<string> */
    private function showCommand(string $ref, string $path): array
    {
        return ['git', 'show', '--end-of-options', "{$ref}:{$path}"];
    }

    /**
     * Regular files at the root of $ref, non-recursive, each with the byte size git
     * records for its blob. `run()` stays private — this is the one narrow slice of it a
     * caller outside this class needs (ReadmeLocator).
     *
     * The size is here rather than in a separate lookup because `git ls-tree -l` reports it
     * in the listing this method already runs: it costs an extra column, not an extra
     * process. The alternative, `git cat-file -s {ref}:{path}`, would spawn a second git per
     * candidate *and* resolve the path a second time, independently of the listing that
     * chose it — so the size checked and the blob subsequently read would be two separate
     * resolutions rather than one. Returning name and size together keeps the decision and
     * the object it describes tied to a single authoritative listing, which is what lets
     * ReadmeLocator refuse an oversize blob before reading a byte of it.
     *
     * Deliberately regular files only, not directories or symlinks:
     *
     * - Directories: `git show {ref}:{path}` exits 0 and happily prints a tree listing when
     *   $path is a directory rather than failing, so a caller that can't tell a blob from a
     *   tree ahead of time would treat a directory named e.g. "README.md" as if it were that
     *   file.
     * - Symlinks: a symlink is *also* type "blob" (git stores the link target as the blob's
     *   content), so a type check alone lets one through. `git show` on a symlink path
     *   returns the literal target string, not the target's content and not an error — a
     *   reader would see one line of nonsense (e.g. "TARGET.md") where the README belongs.
     *   Resolving the link instead was considered and rejected: it means following a path
     *   the repository author controls, inside a bare mirror, with the same "where does
     *   this actually point" questions a symlink raises anywhere else. Skipping it is the
     *   honest outcome — the caller sees no README rather than a wrong one.
     *
     * With `-l` and without `--name-only`, `git ls-tree` reports each entry as
     * "<mode> <type> <sha> <size>\t<name>" — <mode> is "120000" for a symlink (vs. "100644"
     * / "100755" for a regular file), <type> is "blob" for both a regular file and a
     * symlink, "tree" for a subdirectory, "commit" for a submodule — so both mode and type
     * are filtered here rather than left for the caller to infer from a command that can't
     * fail on either. <size> is right-aligned in a padded column and is "-" for anything
     * that is not a blob, hence the whitespace split and the digit check below; an entry
     * whose size does not parse is dropped rather than reported with a guessed size, so a
     * caller sizing a read against it can never be handed an unbounded one.
     *
     * Entry names are not unquoted: `core.quotePath` (on by default) makes git render a
     * name containing a non-ASCII byte or a tab as a C-style escaped, double-quoted string
     * rather than the raw bytes. That's a real gap in this parser for arbitrary filenames,
     * but not one that can hide a legitimate README: every name in
     * ReadmeLocator::CANDIDATES is plain ASCII with no special characters, which git never
     * quotes, so the candidate this method exists to find is never affected by it.
     *
     * @return list<array{name: string, size: int}>
     */
    public function rootFileEntries(string $ref): array
    {
        $output = $this->run(['git', 'ls-tree', '-l', '--end-of-options', $ref])->output();

        $entries = [];

        foreach (explode("\n", $output) as $line) {
            if (trim($line) === '') {
                continue;
            }

            [$info, $name] = array_pad(explode("\t", $line, 2), 2, null);

            if ($info === null || $name === null) {
                continue;
            }

            [$mode, $type, , $size] = array_pad(preg_split('/\s+/', trim($info)) ?: [], 4, null);

            if ($type === 'blob' && $mode !== '120000' && is_string($size) && ctype_digit($size)) {
                $entries[] = ['name' => $name, 'size' => (int) $size];
            }
        }

        return $entries;
    }

    /**
     * Builds a zip archive of the ref. The caller is responsible for deleting the
     * returned file.
     */
    public function archiveZip(string $ref): string
    {
        $stub = tempnam(sys_get_temp_dir(), 'kfx-dist-');
        $zip = $stub.'.zip';

        try {
            $this->run(['git', 'archive', '--format=zip', '-o', $zip, '--end-of-options', $ref]);
        } catch (Throwable $e) {
            @unlink($zip); // git creates the output file before the ref check — clean up on error
            throw $e;
        } finally {
            @unlink($stub); // always remove the tempnam stub
        }

        return $zip;
    }

    /**
     * Builds a gzipped-tar archive of the ref with an internal path prefix (npm expects a
     * `package/` root; a Python sdist expects `{name}-{version}/`). The prefix is built by
     * the caller from validated data — never from an untrusted tag. The caller must delete
     * the returned file.
     */
    public function archiveTarGz(string $ref, string $prefix): string
    {
        if (! preg_match('#^[A-Za-z0-9._/+-]+/$#', $prefix)) {
            throw new InvalidArgumentException('Invalid archive prefix.');
        }

        $stub = tempnam(sys_get_temp_dir(), 'kfx-dist-');
        $tgz = $stub.'.tar.gz';

        try {
            $this->run(['git', 'archive', '--format=tar.gz', '--prefix='.$prefix, '-o', $tgz, '--end-of-options', $ref]);
        } catch (Throwable $e) {
            @unlink($tgz);
            throw $e;
        } finally {
            @unlink($stub);
        }

        return $tgz;
    }

    /**
     * @param  list<string>  $command
     */
    private function run(array $command): ProcessResult
    {
        $result = Process::path($this->mirrorPath)->timeout(self::COMMAND_TIMEOUT)->run($command);

        if (! $result->successful()) {
            throw new RuntimeException(implode(' ', $command).' failed: '.$result->errorOutput());
        }

        return $result;
    }
}
