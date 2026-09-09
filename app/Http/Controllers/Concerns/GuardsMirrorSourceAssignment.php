<?php

namespace App\Http\Controllers\Concerns;

use App\Enums\PackageType;
use App\Models\MirrorSource;

/**
 * The one check every write that assigns a MirrorSource to a package must make, shared
 * between Admin\PackageController (store/updateMirror) and Api\V1\PackageController
 * (store) so the two surfaces cannot drift apart on what a "usable" mirror source means.
 */
trait GuardsMirrorSourceAssignment
{
    /**
     * Aborts 403 unless $organizationId may use $source — a MirrorSource is never shared
     * across organizations (see its docblock), so this is a plain ownership check, unlike the
     * git credential equivalent. Aborts 422 when the source's type does not match the
     * package's — MirrorImporter::import() dispatches on the package's type alone and would
     * otherwise read (say) an npm packument as if it were Composer's p2 feed.
     */
    protected function assertMirrorSourceUsable(MirrorSource $source, string $organizationId, PackageType $type): void
    {
        abort_unless($source->organization_id === $organizationId, 403);
        abort_unless($source->type === $type, 422);
    }
}
