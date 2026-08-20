<?php

namespace App\Support;

/**
 * The wording of an abandonment, in the two shapes the ecosystems accept.
 *
 * Composer has a structured field: `abandoned` is either `true` or the replacement's
 * package name, and Composer itself prints the sentence. npm and Python have no
 * replacement field at all — npm's `deprecated` and PEP 792's `reason` are free text — so
 * two stored columns have to become one sentence. That composition lives here and nowhere
 * else; repeating it inside three builders is how the three wordings drift apart.
 */
final readonly class AbandonmentNotice
{
    private const DEFAULT_REASON = 'Dieses Paket wird nicht mehr gepflegt.';

    public function __construct(
        public ?string $replacement = null,
        public ?string $reason = null,
    ) {}

    /** Composer's own field: the replacement name, or a bare `true` when none is known. */
    public function composerValue(): true|string
    {
        return $this->replacement ?? true;
    }

    /** The sentence npm's `deprecated` and PEP 792's `reason` both carry. */
    public function message(): string
    {
        $reason = trim((string) $this->reason);
        $base = $reason === '' ? self::DEFAULT_REASON : $reason;

        if ($this->replacement === null) {
            return $base;
        }

        // Without this the appended hint runs into an unterminated reason as one sentence.
        // Only `.`/`!`/`?` count as terminated: a reason ending in a colon or a comma is
        // still mid-sentence and gets its period.
        if (! str_ends_with($base, '.') && ! str_ends_with($base, '!') && ! str_ends_with($base, '?')) {
            $base .= '.';
        }

        return "{$base} Bitte stattdessen {$this->replacement} verwenden.";
    }
}
