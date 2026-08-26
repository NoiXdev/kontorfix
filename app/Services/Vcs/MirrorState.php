<?php

namespace App\Services\Vcs;

use Illuminate\Support\Facades\Process;

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

        // Cheap and decisive: a mirror git itself refuses to recognise is one we cannot
        // fetch into, whatever the reason — interrupted clone, truncated HEAD, stray
        // directory left by a crash.
        $result = Process::path($path)->timeout(15)->run(['git', 'rev-parse', '--is-bare-repository']);

        return $result->successful() && trim($result->output()) === 'true'
            ? self::Usable
            : self::Repairable;
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
