<?php

namespace App\Services\Vcs;

/**
 * Locates a project README inside a bare git mirror.
 *
 * The sync clones with `git clone --mirror`, so there is no working tree and nothing to
 * read from disk. The root listing comes from `git ls-tree` and the contents from
 * `git show`, both reached through GitRepository's public surface — `run()` itself stays
 * private, see `GitRepository::rootFileEntries()`.
 */
class ReadmeLocator
{
    /** Source bytes read before truncation. */
    public const MAX_BYTES = 262144;

    /** Preference order. The first match wins, compared case-insensitively. */
    private const CANDIDATES = ['readme.md', 'readme.markdown', 'readme.rst', 'readme.txt', 'readme'];

    /**
     * Three outcomes, and the caller has to be able to tell them apart:
     *
     * - an array — this ref has a README, here it is;
     * - `null` — the root listed cleanly and holds no README candidate. The project has
     *   none, which includes the case of one that was deleted upstream;
     * - a Throwable — the listing or the read failed, so nothing is known either way.
     *
     * This class used to return `null` for all three, and a caller cannot distinguish
     * "upstream deleted the README" from "git would not talk to us" after the fact. That
     * collapse is why a README deleted upstream — including one deleted *because* it
     * leaked something — went on being served: the only safe reading of an ambiguous
     * `null` is "keep what you have". Failures are therefore no longer swallowed here;
     * they propagate, and `null` means exactly one thing.
     *
     * @return array{filename: string, source: string}|null
     *
     * @throws \Throwable if the root cannot be listed or the README blob cannot be read
     */
    public static function find(GitRepository $repo, string $ref): ?array
    {
        $entry = self::pick($repo->rootFileEntries($ref));

        if ($entry === null) {
            return null;
        }

        return ['filename' => $entry['name'], 'source' => self::read($repo, $ref, $entry)];
    }

    /**
     * Reads the picked blob, never letting more than MAX_BYTES of it into this process.
     *
     * The size decides *before* the read, not after. Reading first and cutting afterwards
     * looks equivalent and is not: `git show`'s whole stdout lands in one PHP string, so a
     * repository with a 500 MB README exhausts `memory_limit` — a PHP fatal, not a
     * Throwable, so no caller's catch runs and the queue worker dies and replays the job.
     * By the time a `strlen()` check could fire, the damage is done. The size in the root
     * listing (GitRepository::rootFileEntries()) is what makes the decision reachable
     * without reading anything.
     *
     * @param  array{name: string, size: int}  $entry
     */
    private static function read(GitRepository $repo, string $ref, array $entry): string
    {
        if ($entry['size'] > self::MAX_BYTES) {
            // One byte past the cap, so truncate()'s mb_strcut() can still tell that the
            // text continues and back off to the last whole character. Handed exactly
            // MAX_BYTES it would have no way to know the final character was cut short.
            return self::truncate($repo->fileAtRefCapped($ref, $entry['name'], self::MAX_BYTES + 1), $entry['name']);
        }

        $source = $repo->fileAtRef($ref, $entry['name']);

        // Second line of defence, deliberately kept: the branch above trusts the size the
        // listing reported, and this one does not. A blob that comes back longer than
        // advertised — a listing and a read that disagree for any reason — is still cut.
        return strlen($source) > self::MAX_BYTES
            ? self::truncate($source, $entry['name'])
            : $source;
    }

    /**
     * Picks the preferred candidate out of the root-level file (blob) entries. A README
     * inside a subdirectory is documentation for that directory, not the project's front
     * page — and GitRepository::rootFileEntries() lists the root only and already excludes
     * directories/submodules, so a directory named e.g. "README.md" can't be picked and
     * handed to `fileAtRef()`, which would happily return its tree listing.
     *
     * No match is a real answer, not a failure: the listing succeeded, and a repository
     * whose root holds a directory or a symlink named README.md genuinely has no README
     * blob to show.
     *
     * @param  list<array{name: string, size: int}>  $entries
     * @return array{name: string, size: int}|null
     */
    private static function pick(array $entries): ?array
    {
        foreach (self::CANDIDATES as $candidate) {
            foreach ($entries as $entry) {
                if (strtolower($entry['name']) === $candidate) {
                    return $entry;
                }
            }
        }

        return null;
    }

    /**
     * Cuts $source to the byte cap and appends a truncation notice.
     *
     * Two things a plain `substr()` gets wrong here, both because MAX_BYTES is a byte
     * count that lands wherever it lands, with no regard for what's at that offset:
     *
     * 1. It can split a multi-byte UTF-8 character in half, and on the markdown path that
     *    takes out the whole file: CommonMark raises UnexpectedEncodingException on invalid
     *    UTF-8, so a one-byte accident at the cap loses an otherwise-fine README. (The
     *    plain-text path is not affected — Laravel's e() passes ENT_QUOTES | ENT_SUBSTITUTE,
     *    so htmlspecialchars() replaces the bad bytes with U+FFFD rather than returning ""
     *    for the whole string, which is what an earlier version of this comment claimed.
     *    Without ENT_SUBSTITUTE it would return "", but that is not the flag set in use.)
     *    mb_strcut() cuts at a byte budget without ever splitting a character, so this is a
     *    real bug a naive cap would ship on the markdown path, not a hypothetical.
     *
     * 2. It can leave an unterminated ``` / ~~~ fenced code block open — on the markdown
     *    path, which is why both that and the notice's own syntax depend on which path
     *    will render the file. CommonMark treats
     *    an unterminated fence as running to end-of-document rather than throwing, so this
     *    is not a crash risk — but left alone it would swallow the truncation notice below
     *    into the code block, rendering it as if the README itself said "Diese README
     *    wurde gekürzt." instead of showing it as a note. closeUnterminatedFence() appends
     *    a closing fence first so the notice always renders as prose. Neither the fence
     *    repair nor markdown emphasis has any meaning on the plain-text path, where the
     *    whole file is escaped into a <pre> block: there, `_..._` reaches the reader as
     *    literal underscores and an appended ``` as a stray line of backticks. So the
     *    notice is written in whichever syntax ReadmeRenderer will apply to this filename.
     */
    private static function truncate(string $source, string $filename): string
    {
        $cut = mb_strcut($source, 0, self::MAX_BYTES, 'UTF-8');

        if (! ReadmeRenderer::isMarkdown($filename)) {
            return $cut."\n\n---\n\nDiese README wurde gekürzt.";
        }

        return self::closeUnterminatedFence($cut)."\n\n---\n\n_Diese README wurde gekürzt._";
    }

    /**
     * Heuristic, not a markdown parser: walks lines looking for fence markers (three or
     * more backticks or tildes, optionally indented up to three spaces — both forms are
     * handled by the regex/char-class below, not out of scope) and tracks whether the
     * last one opened or closed a block. If the truncated text ends mid-fence, appends a
     * matching closing fence so nothing after it — notably the truncation notice — gets
     * absorbed into the code block.
     *
     * Per the CommonMark fence rule, a closing fence must use the *same character* as the
     * opening one and be *at least as long*. Matching on character alone is not enough: a
     * README that documents markdown or shell fencing commonly nests a shorter example
     * fence inside a longer outer one (e.g. a 4-backtick block containing a 3-backtick
     * snippet) — CommonMark treats the inner, shorter run as literal text, not a real
     * close, and this method must agree or it ends up believing an still-open block is
     * closed. A closing line also carries no info string (only optional trailing
     * whitespace) per spec, so a same-length run followed by other text — e.g. a second
     * opening fence of the same length and character — does not close the current one
     * either.
     */
    private static function closeUnterminatedFence(string $source): string
    {
        $inFence = false;
        $fenceChar = null;
        $fenceLength = 0;

        foreach (preg_split('/\R/', $source) ?: [] as $line) {
            if (! preg_match('/^ {0,3}(`{3,}|~{3,})(.*)$/', $line, $matches)) {
                continue;
            }

            $marker = $matches[1];
            $trailing = trim($matches[2]);
            $char = $marker[0];
            $length = strlen($marker);

            if (! $inFence) {
                $inFence = true;
                $fenceChar = $char;
                $fenceLength = $length;
            } elseif ($char === $fenceChar && $length >= $fenceLength && $trailing === '') {
                $inFence = false;
                $fenceChar = null;
                $fenceLength = 0;
            }
        }

        return $inFence ? $source."\n".str_repeat((string) $fenceChar, $fenceLength) : $source;
    }
}
