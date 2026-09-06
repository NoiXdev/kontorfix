<?php

namespace App\Models;

use Database\Factories\OciTagFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A mutable name (e.g. "latest", "1.4.0") pointing at a manifest within one repository
 * (`Package`). Unique per `(package_id, name)`: the same tag name may exist in different
 * repositories independently.
 *
 * Points at its manifest by foreign key (`manifest_id`), not by digest. A digest is only
 * unique WITHIN a repository — the same bytes can legitimately exist in two repositories —
 * so a digest-keyed reference would need to also carry `package_id` to stay
 * repository-scoped. The manifest row already carries that pairing, so its id carries it
 * too, by construction. The OCI protocol still addresses manifests by digest; that is a
 * lookup against the package's manifests, not a schema shape this model needs to encode.
 *
 * `package_id` is denormalised onto this row (the unique tag-name constraint and "list
 * this repository's tags" both need it directly), and the migration's composite foreign
 * key on `(manifest_id, package_id)` is what keeps that copy honest: the database refuses
 * a tag whose `package_id` names one repository and whose `manifest_id` names a manifest
 * in another, rather than trusting every write path to agree.
 */
class OciTag extends Model
{
    /** @use HasFactory<OciTagFactory> */
    use HasFactory, HasUuids;

    protected $fillable = [
        'package_id',
        'name',
        'manifest_id',
    ];

    /**
     * The repository this tag belongs to.
     *
     * @return BelongsTo<Package, $this>
     */
    public function package(): BelongsTo
    {
        return $this->belongsTo(Package::class);
    }

    /**
     * The manifest this tag currently points at.
     *
     * @return BelongsTo<OciManifest, $this>
     */
    public function manifest(): BelongsTo
    {
        return $this->belongsTo(OciManifest::class);
    }
}
