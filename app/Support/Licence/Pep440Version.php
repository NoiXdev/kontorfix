<?php

namespace App\Support\Licence;

/**
 * A parsed PEP 440 version, comparable per the spec's own ordering rules —
 * https://packaging.python.org/en/latest/specifications/version-specifiers/#version-scheme
 *
 * Composer and npm bounds are enforced with `composer/semver`, which already speaks their
 * version schemes. PyPI cannot reuse it: PEP 440 has epochs, pre/post/dev release segments,
 * and an ordering (`1.0.dev1 < 1.0a1 < 1.0 < 1.0.post1`, epochs dominate everything) that
 * semver has no concept of and would get wrong.
 *
 * `parse()` returns `null` for anything that is not a syntactically valid PEP 440 version —
 * including version strings this class simply does not recognise. That `null` is a signal
 * to the caller, not a shortcoming to work around: a bound comparison against an unparseable
 * version cannot be answered honestly, and the safe default is to treat it as outside every
 * bounded window (fail closed) rather than guess an ordering.
 */
final class Pep440Version
{
    /**
     * The canonical PEP 440 version pattern, applied after lowercasing and stripping a
     * leading `v`. Captures the epoch, the release segment, and the three optional release
     * modifiers (pre/post/dev) in one pass. Each modifier's own marker (`pre_l`/`post_l`/
     * `dev_l`) is captured separately from its optional digits (`pre_n`/`post_n`/`dev_n`) so
     * a marker with no digits — e.g. `1.0.dev` — is still detected as present, defaulting to
     * `0`, rather than being indistinguishable from the marker being absent entirely. A local
     * version segment (`+...`) is deliberately not matched, so any version carrying one fails
     * to parse rather than being guessed at.
     */
    private const PATTERN = '/^
        (?:(?<epoch>[0-9]+)!)?
        (?<release>[0-9]+(?:\.[0-9]+)*)
        (?:[-_.]?(?<pre_l>a|b|c|rc|alpha|beta|pre|preview)[-_.]?(?<pre_n>[0-9]+)?)?
        (?:[-_.]?(?<post_l>post|rev|r)[-_.]?(?<post_n>[0-9]+)?)?
        (?:[-_.]?(?<dev_l>dev)[-_.]?(?<dev_n>[0-9]+)?)?
    $/x';

    /** Pre-release letter, normalised to its rank: alpha/a=0, beta/b=1, everything else (c, rc, pre, preview)=2. */
    private const PRE_RANK = [
        'a' => 0, 'alpha' => 0,
        'b' => 1, 'beta' => 1,
        'c' => 2, 'rc' => 2, 'pre' => 2, 'preview' => 2,
    ];

    /** @param list<int> $release */
    private function __construct(
        private readonly int $epoch,
        private readonly array $release,
        private readonly ?int $preRank,
        private readonly ?int $preNum,
        private readonly ?int $postNum,
        private readonly ?int $devNum,
    ) {}

    public static function parse(string $version): ?self
    {
        $normalized = strtolower(trim($version));

        if ($normalized === '') {
            return null;
        }

        if (str_starts_with($normalized, 'v')) {
            $normalized = substr($normalized, 1);
        }

        if (! preg_match(self::PATTERN, $normalized, $m)) {
            return null;
        }

        $epoch = $m['epoch'] !== '' ? (int) $m['epoch'] : 0;
        $release = array_map(static fn (string $part): int => (int) $part, explode('.', $m['release']));

        $preRank = null;
        $preNum = null;
        if (isset($m['pre_l']) && $m['pre_l'] !== '') {
            $preRank = self::PRE_RANK[$m['pre_l']];
            $preNum = isset($m['pre_n']) && $m['pre_n'] !== '' ? (int) $m['pre_n'] : 0;
        }

        $postNum = null;
        if (isset($m['post_l']) && $m['post_l'] !== '') {
            $postNum = isset($m['post_n']) && $m['post_n'] !== '' ? (int) $m['post_n'] : 0;
        }

        $devNum = null;
        if (isset($m['dev_l']) && $m['dev_l'] !== '') {
            $devNum = isset($m['dev_n']) ? (int) $m['dev_n'] : 0;
        }

        return new self($epoch, $release, $preRank, $preNum, $postNum, $devNum);
    }

    /**
     * Mirrors the reference `packaging` library's comparison key, component by component:
     * epoch, then the release segment (zero-padded so `1.0` and `1.0.0` compare equal), then
     * a pre-release "bucket" — a bare dev release (no pre, no post) sorts before everything
     * else of the same release, a real pre-release sorts next (ranked then numbered), and a
     * final or post release sorts after both — then the post number, then the dev number
     * (present sorts before absent, since a dev build of a given release precedes it).
     */
    public function compareTo(self $other): int
    {
        if ($this->epoch !== $other->epoch) {
            return $this->epoch <=> $other->epoch;
        }

        $releaseComparison = self::compareRelease($this->release, $other->release);
        if ($releaseComparison !== 0) {
            return $releaseComparison;
        }

        $bucketComparison = $this->preBucket() <=> $other->preBucket();
        if ($bucketComparison !== 0) {
            return $bucketComparison;
        }

        if ($this->preBucket() === 1) {
            $preComparison = [$this->preRank, $this->preNum] <=> [$other->preRank, $other->preNum];
            if ($preComparison !== 0) {
                return $preComparison;
            }
        }

        $postComparison = ($this->postNum ?? -1) <=> ($other->postNum ?? -1);
        if ($postComparison !== 0) {
            return $postComparison;
        }

        return ($this->devNum ?? PHP_INT_MAX) <=> ($other->devNum ?? PHP_INT_MAX);
    }

    /**
     * Which of the three pre-release "buckets" this version falls into: `0` for a bare dev
     * release (no pre, no post — sorts before everything else of the same release), `1` for
     * an actual pre-release (a/b/rc), `2` for a final or post release.
     */
    private function preBucket(): int
    {
        if ($this->preRank === null && $this->postNum === null && $this->devNum !== null) {
            return 0;
        }

        return $this->preRank === null ? 2 : 1;
    }

    /**
     * @param  list<int>  $left
     * @param  list<int>  $right
     */
    private static function compareRelease(array $left, array $right): int
    {
        $length = max(count($left), count($right));

        for ($i = 0; $i < $length; $i++) {
            $comparison = ($left[$i] ?? 0) <=> ($right[$i] ?? 0);
            if ($comparison !== 0) {
                return $comparison;
            }
        }

        return 0;
    }
}
