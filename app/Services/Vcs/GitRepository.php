<?php

namespace App\Services\Vcs;

use App\Enums\GitProvider;
use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Support\Facades\Process;
use InvalidArgumentException;
use RuntimeException;
use Symfony\Component\Process\Process as SystemProcess;
use Throwable;

class GitRepository
{
    private string $mirrorPath;

    /** @var array<string, string> */
    private array $authEnv;

    public function __construct(
        private readonly string $url,
        string $storageKey,
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

    public function sync(): void
    {
        // The only outbound operation on this class — every other method runs inside the
        // local mirror. Guarding here covers the queued SyncPackage job, packages:resync,
        // the incoming-webhook trigger and the registry dist path in one place.
        $rejection = GitUrlSafety::reject($this->url);
        if ($rejection !== null) {
            throw new RuntimeException($rejection);
        }

        if (is_dir($this->mirrorPath)) {
            // fetch needs the auth header too (the mirror's stored URL is token-free).
            $result = Process::path($this->mirrorPath)->env($this->authEnv)->timeout(120)
                ->run(['git', 'fetch', '--prune', '--tags', 'origin']);

            if (! $result->successful()) {
                throw new RuntimeException('git fetch failed: '.GitAuth::scrub($result->errorOutput()));
            }

            return;
        }

        if (! is_dir(dirname($this->mirrorPath))) {
            mkdir(dirname($this->mirrorPath), 0775, true);
        }

        $result = Process::env($this->authEnv)->timeout(300)->run([
            'git', 'clone', '--mirror', '-c', 'protocol.file.allow=always', $this->url, $this->mirrorPath,
        ]);

        if (! $result->successful()) {
            throw new RuntimeException('git clone failed: '.GitAuth::scrub($result->errorOutput()));
        }
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
     * it is the abort. The process object goes out of scope immediately afterwards and its
     * destructor stops git, so the abandoned `git show` does not linger. Output arrives in
     * chunks, so the buffer briefly holds up to one chunk more than the budget before it is
     * cut back — bounded either way, which is what matters here.
     *
     * A process that ends on its own without reaching the budget is checked for success as
     * usual: abandoning at the cap must not quietly turn every git failure into "".
     *
     * @throws RuntimeException if git fails before the budget is reached
     */
    public function fileAtRefCapped(string $ref, string $path, int $maxBytes): string
    {
        $buffer = '';

        try {
            $result = Process::path($this->mirrorPath)->timeout(120)->run(
                $this->showCommand($ref, $path),
                function (string $type, string $chunk) use (&$buffer, $maxBytes) {
                    if ($type !== SystemProcess::OUT) {
                        return;
                    }

                    $buffer .= $chunk;

                    if (strlen($buffer) >= $maxBytes) {
                        throw new BlobCapReached;
                    }
                },
            );
        } catch (BlobCapReached) {
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
        $result = Process::path($this->mirrorPath)->timeout(120)->run($command);

        if (! $result->successful()) {
            throw new RuntimeException(implode(' ', $command).' failed: '.$result->errorOutput());
        }

        return $result;
    }
}
