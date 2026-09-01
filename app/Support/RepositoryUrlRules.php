<?php

namespace App\Support;

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
 */
final class RepositoryUrlRules
{
    /** The field both call sites validate. Messages below are keyed by it. */
    public const FIELD = 'repository_url';

    /**
     * Only real Git remotes over https/ssh — no file:// or gopher:// etc., which would
     * otherwise be passed to the git subprocess as an SSRF surface.
     *
     * @return array<int, string>
     */
    public static function shape(): array
    {
        return ['string', 'max:500', 'url:https,ssh', 'starts_with:https://,ssh://'];
    }

    /**
     * German messages for the shape rules that a human-entered URL realistically trips.
     *
     * The common case is the SSH clone URL GitHub and GitLab offer by default,
     * `git@github.com:org/repo.git`: it fails both `url` and `starts_with`, and Laravel's
     * untranslated defaults would say so in English.
     *
     * @return array<string, string>
     */
    public static function messages(): array
    {
        return [
            self::FIELD.'.starts_with' => 'Die Repository-URL muss mit https:// oder ssh:// beginnen.',
            self::FIELD.'.url' => 'Bitte eine gültige https- oder ssh-Repository-URL angeben.',
        ];
    }
}
