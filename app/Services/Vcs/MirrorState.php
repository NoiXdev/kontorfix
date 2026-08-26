<?php

namespace App\Services\Vcs;

use Illuminate\Support\Facades\Process;
use RuntimeException;

/**
 * What can be done with an existing git mirror directory.
 *
 * v0.7.0 dropped the container from root to www-data. Every mirror the previous release
 * created is owned by root, and git refuses to work in a repository owned by another user
 * ("detected dubious ownership"). The service cannot repair that: removing a directory
 * needs write permission on its *immediate* parent, and the foreign-owned `.git` directory
 * is that parent — deleting as www-data failed on every entry.
 *
 * So the two cases are kept apart on purpose. A mirror this service owns but cannot use is
 * thrown away and re-cloned. A mirror owned by someone else is reported in terms an
 * operator can act on, instead of passing git's wording through.
 *
 * A third case is kept apart just as deliberately: uncertainty must never be treated as
 * "broken". `of()` only ever returns Repairable when git has positively told us the path is
 * not a usable repository — never merely because the check itself failed to run (git
 * missing, a timeout, a transient filesystem error). Collapsing "definitely broken" and
 * "couldn't tell" into the same outcome would mean an infra hiccup deletes and re-clones a
 * perfectly good mirror; under load, a burst of such hiccups would each trigger a full
 * re-clone, adding load and causing more hiccups elsewhere — exactly the thundering herd
 * this design exists to avoid. An indeterminate result is therefore surfaced as a
 * RuntimeException instead of a classification, the same way every other sync failure in
 * this codebase is reported — the caller sees a failed sync, not a silently repaired one.
 */
enum MirrorState
{
    case Absent;
    case Usable;
    case Repairable;
    case ForeignOwner;

    public static function of(string $path): self
    {
        if (! is_dir($path)) {
            return self::Absent;
        }

        $owner = @fileowner($path);

        if ($owner !== false && $owner !== posix_geteuid()) {
            return self::ForeignOwner;
        }

        // --git-dir pins this check to exactly $path. Without it, git's ordinary repository
        // discovery walks up through parent directories looking for a `.git`, and a mirror
        // lives inside this application's own working tree — an unpinned check run against a
        // stray directory can walk all the way up to *this project's* repository and answer a
        // question about that one instead of about $path (worse: if some ancestor happened to
        // be a bare repo, it would report "true" and misclassify a broken mirror as Usable).
        $result = Process::path($path)->timeout(15)->run(['git', '--git-dir='.$path, 'rev-parse', '--is-bare-repository']);

        if ($result->successful()) {
            return trim($result->output()) === 'true' ? self::Usable : self::Repairable;
        }

        // Exit 128 with this exact message is git positively telling us $path is not a
        // git repository at all — a stray directory, a half-written clone missing HEAD, or
        // similar. That is decisive evidence, not a guess.
        if (str_contains($result->errorOutput(), 'not a git repository')) {
            return self::Repairable;
        }

        // Any other failure (git missing, a permissions surprise, a flaky filesystem) looks
        // identical to a broken mirror from here, but is not evidence that the mirror itself
        // is broken. See the class docblock: uncertainty must not cause deletion.
        throw new RuntimeException(sprintf(
            'Could not determine whether %s is a usable git mirror: %s',
            $path,
            trim($result->errorOutput()) !== '' ? trim($result->errorOutput()) : 'git exited '.$result->exitCode(),
        ));
    }

    /** German: this reaches the operator through `packages.sync_error`. */
    public static function foreignOwnerMessage(string $path): string
    {
        $owner = @fileowner($path);

        return sprintf(
            'Der Git-Mirror gehört uid %s, dieser Dienst läuft als uid %d. '
            .'Das Verzeichnis %s entfernen — der nächste Sync klont neu.',
            $owner === false ? 'unbekannt' : (string) $owner,
            posix_geteuid(),
            $path,
        );
    }
}
