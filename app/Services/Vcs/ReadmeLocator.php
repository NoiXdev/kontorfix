<?php

namespace App\Services\Vcs;

use Throwable;

/**
 * Locates a project README inside a bare git mirror.
 *
 * The sync clones with `git clone --mirror`, so there is no working tree and nothing to
 * read from disk. The root listing comes from `git ls-tree` and the contents from
 * `git show`, both reached through GitRepository's public surface — `run()` itself stays
 * private, see `GitRepository::rootFileNames()`.
 */
class ReadmeLocator
{
    /** Source bytes read before truncation. */
    public const MAX_BYTES = 262144;

    /** Preference order. The first match wins, compared case-insensitively. */
    private const CANDIDATES = ['readme.md', 'readme.markdown', 'readme.rst', 'readme.txt', 'readme'];

    /**
     * @return array{filename: string, source: string}|null
     */
    public static function find(GitRepository $repo, string $ref): ?array
    {
        $filename = self::pick(self::rootEntries($repo, $ref));

        if ($filename === null) {
            return null;
        }

        try {
            $source = $repo->fileAtRef($ref, $filename);
        } catch (Throwable) {
            return null;
        }

        if (strlen($source) > self::MAX_BYTES) {
            $source = self::truncate($source);
        }

        return ['filename' => $filename, 'source' => $source];
    }

    /**
     * Root-level file (blob) names only. A README inside a subdirectory is documentation
     * for that directory, not the project's front page — and GitRepository::rootFileNames()
     * already excludes directories/submodules, so a directory named e.g. "README.md" can't
     * be picked and handed to `fileAtRef()`, which would happily return its tree listing.
     *
     * @return list<string>
     */
    private static function rootEntries(GitRepository $repo, string $ref): array
    {
        try {
            return $repo->rootFileNames($ref);
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * @param  list<string>  $entries
     */
    private static function pick(array $entries): ?string
    {
        foreach (self::CANDIDATES as $candidate) {
            foreach ($entries as $entry) {
                if (strtolower($entry) === $candidate) {
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
     * 1. It can split a multi-byte UTF-8 character in half. The renderer (ReadmeRenderer)
     *    throws on that rather than degrading gracefully — CommonMark raises
     *    UnexpectedEncodingException on invalid UTF-8, and the plain-text path's
     *    htmlspecialchars() silently returns "" for the whole string. Either way a
     *    one-byte accident at the cap would take out an otherwise-fine README. mb_strcut()
     *    cuts at a byte budget without ever splitting a character, so this is a real bug a
     *    naive cap would ship, not a hypothetical.
     *
     * 2. It can leave an unterminated ``` / ~~~ fenced code block open. CommonMark treats
     *    an unterminated fence as running to end-of-document rather than throwing, so this
     *    is not a crash risk — but left alone it would swallow the truncation notice below
     *    into the code block, rendering it as if the README itself said "Diese README
     *    wurde gekürzt." instead of showing it as a note. closeUnterminatedFence() appends
     *    a closing fence first so the notice always renders as prose.
     */
    private static function truncate(string $source): string
    {
        $cut = mb_strcut($source, 0, self::MAX_BYTES, 'UTF-8');
        $cut = self::closeUnterminatedFence($cut);

        return $cut."\n\n---\n\n_Diese README wurde gekürzt._";
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
