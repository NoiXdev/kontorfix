<?php

namespace App\Support;

use App\Models\PackageVersion;
use Composer\Semver\VersionParser;
use Illuminate\Support\Collection;
use UnexpectedValueException;

/**
 * The one place that decides what "newest" means for a package's versions.
 *
 * Neither detail-page controller had an ordering: the admin page loaded the relation
 * unordered and took whatever the database returned, and the portal ordered by the
 * nullable `released_at`. Both pages now default a selector to the newest version, so
 * "newest" has to be defined once and defined the same way on both sides.
 *
 * Lexical ordering is wrong for a package registry — it puts 1.9.0 above 1.10.0 — so
 * versions are compared semantically. Tags that are not versions at all ("nightly",
 * "trunk") cannot be compared that way; they sort after every real version, newest
 * release date first, so they never occupy the default slot.
 */
class VersionOrder
{
    /**
     * @param  Collection<int, PackageVersion>  $versions
     * @return Collection<int, PackageVersion>
     */
    public static function sort(Collection $versions): Collection
    {
        $parser = new VersionParser;

        // Normalize once per version up front (keyed by spl_object_id, since PackageVersion
        // has no stable identity here — these can be transient, unsaved models in tests)
        // instead of re-normalizing on every comparison inside the sort callback.
        $normalized = [];
        [$comparable, $rest] = $versions->partition(
            function (PackageVersion $version) use ($parser, &$normalized): bool {
                try {
                    $label = $parser->normalize(self::label($version));
                } catch (UnexpectedValueException) {
                    return false;
                }

                // normalize() has a legacy special case that accepts "master"/"trunk"/"default"
                // as branch aliases and happily returns "dev-trunk" etc. instead of throwing, so
                // a tag with one of those names would otherwise sort as a comparable version.
                // Excluding every "dev-*" result is safe in this codebase specifically because
                // GitSourceImporter only ever imports from tags(), never branches, and npm
                // publish requires a real semver — so no genuine version row is a branch
                // pseudo-version today. Composer itself treats "dev-main"/"dev-master" as
                // legitimate installable versions; a future change that starts importing
                // branches (not just tags) would need to revisit this exclusion.
                if (str_starts_with($label, 'dev-')) {
                    return false;
                }

                $normalized[spl_object_id($version)] = $label;

                return true;
            }
        );

        // Composer normalizes to a dot-padded form (e.g. "1.10.0.0"), which cannot be sorted
        // lexically without breaking on multi-digit segments (1.9.0.0 > 1.10.0.0 as strings).
        // version_compare() understands that form natively, including the "-beta1" suffix
        // normalize() appends for pre-releases, and returns the -1/0/1 a sort callback needs.
        $sorted = $comparable
            ->sort(fn (PackageVersion $a, PackageVersion $b): int => version_compare(
                $normalized[spl_object_id($b)],
                $normalized[spl_object_id($a)]
            ))
            ->values();

        return $sorted
            ->concat($rest->sortByDesc(fn (PackageVersion $v) => $v->released_at?->getTimestamp() ?? 0)->values())
            ->values();
    }

    private static function label(PackageVersion $version): string
    {
        return (string) ($version->version_pretty ?? $version->version);
    }
}
