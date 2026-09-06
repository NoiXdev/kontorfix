<?php

namespace App\Support;

use App\Services\Vcs\GitUrlSafety;

/**
 * The one definition of what a package repository URL may look like, plus the German
 * messages for the two rules that reject a plausible-looking URL.
 *
 * Two endpoints validate this field: StorePackageRequest (creating the package) and
 * PackageController::probe() (the "Prüfen" button the create mask gates saving on). They
 * used to carry two copies of the rule list, and only the store request translated the
 * messages — so the same rejected URL produced a German message on save and Laravel's
 * built-in English one from the probe. There is no lang/ directory in this project and the
 * app locale is `en`, so an untranslated rule is an English string in a German UI.
 * Sharing both halves is what keeps the two from drifting apart again.
 *
 * Only the URL *shape* lives here — that is what the two call sites genuinely have in
 * common. Whether the field is required (always, for the probe; only for a git-sourced
 * package, for the store request) and the NotRedactedCredentialUrl guard (which only
 * matters where a stored value can be written back) stay with their call site.
 *
 * The scheme list itself is *not* defined here: it is read from
 * `GitUrlSafety::allowedSchemes()`, the same normalised list `kontorfix.vcs.allowed_schemes`
 * feeds into the two real sinks (RepositoryProbe::probe() and GitRepository::sync()). This
 * class used to hardcode `https`/`ssh` while the sinks honoured the config, so an operator
 * who widened the allowlist to reach an internal git server got a refusal from the create
 * form for a URL the sync would happily accept. Reading the same method — rather than
 * re-reading `kontorfix.vcs.allowed_schemes` and re-normalising it here — means this class
 * also inherits GitUrlSafety's never-fail-open behaviour on an empty or malformed config
 * for free, instead of risking a second, subtly different implementation of it.
 */
final class RepositoryUrlRules
{
    /** The field both call sites validate. Messages below are keyed by it. */
    public const FIELD = 'repository_url';

    /**
     * Only real Git remotes over the configured transports — no gopher:// etc., which would
     * otherwise be passed to the git subprocess as an SSRF surface. The scheme list is
     * whatever `GitUrlSafety::allowedSchemes()` currently allows, so this can never accept
     * (or reject) a scheme the sync sink disagrees with.
     *
     * @return array<int, string>
     */
    public static function shape(): array
    {
        $schemes = GitUrlSafety::allowedSchemes();

        return [
            'string',
            'max:500',
            'url:'.implode(',', $schemes),
            'starts_with:'.implode(',', array_map(fn (string $scheme): string => "{$scheme}://", $schemes)),
        ];
    }

    /**
     * German messages for the shape rules that a human-entered URL realistically trips.
     *
     * The common case is the SSH clone URL GitHub and GitLab offer by default,
     * `git@github.com:org/repo.git`: it fails both `url` and `starts_with`, and Laravel's
     * untranslated defaults would say so in English.
     *
     * The scheme names are generated from the same `GitUrlSafety::allowedSchemes()` list as
     * shape() above, so widening the config with another **non-host-less** scheme is
     * reflected in the wording too — an operator who added `http` and still gets rejected
     * (e.g. for a genuinely unsupported scheme) is told about every such scheme that would
     * have worked, not just the two shipped defaults. That claim does not extend to a
     * host-less transport (currently only `file`, reachable in this codebase only via the
     * test suite's scheme allowlist): it is left out of the enumeration on purpose — "must
     * start with file://" is not meaningful guidance for a URL pasted into a browser form —
     * so a mixed config such as `['file', 'https']` produces wording that names only
     * `https://` even though shape() below also accepts `file://`. Omitting it narrows only
     * the *wording*, never what shape() actually *accepts* — with the shipped default
     * (`https`, `ssh`, neither host-less) this produces byte-identical text to the previous
     * hardcoded messages.
     *
     * @return array<string, string>
     */
    public static function messages(): array
    {
        $schemes = array_values(array_filter(
            GitUrlSafety::allowedSchemes(),
            fn (string $scheme): bool => ! GitUrlSafety::isLocalScheme($scheme),
        ));

        // A config that allows only host-less transports (e.g. only `file`) has nothing
        // meaningful to enumerate — fall back to the full list rather than word a message
        // around an empty enumeration.
        if ($schemes === []) {
            $schemes = GitUrlSafety::allowedSchemes();
        }

        return [
            self::FIELD.'.starts_with' => sprintf(
                'Die Repository-URL muss mit %s beginnen.',
                self::humanList(array_map(fn (string $scheme): string => "{$scheme}://", $schemes)),
            ),
            self::FIELD.'.url' => sprintf(
                // No literal "-" between %s and "Repository": each scheme in the list
                // already carries its own trailing hyphen (see the map below), matching
                // the previous hardcoded "https- oder ssh-Repository-URL".
                'Bitte eine gültige %sRepository-URL angeben.',
                self::humanList(array_map(fn (string $scheme): string => "{$scheme}-", $schemes)),
            ),
        ];
    }

    /**
     * Joins a list of strings the German way: comma-separated, with "oder" before the last
     * one. With exactly two items — the shipped default (`https`, `ssh`) — this degrades to
     * `"a oder b"`, byte-identical to the wording this class used to hardcode.
     *
     * @param  array<int, string>  $items
     */
    private static function humanList(array $items): string
    {
        if (count($items) <= 1) {
            return $items[0] ?? '';
        }

        $last = array_pop($items);

        return implode(', ', $items).' oder '.$last;
    }
}
