<?php

namespace App\Models;

use Database\Factories\OciTagFactory;
use Illuminate\Database\Eloquent\Builder;
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
     * The order the portal reads this repository's tags in: the first row is the tag a
     * `docker pull` for the repository should name.
     *
     * THE PULL COMMAND AND THE TAG TABLE MUST BE ORDERED BY THIS ONE CLAUSE, and that is why
     * it lives on the model rather than in the controller. Portal\RegistryController prints a
     * `docker pull …:<tag>` above a table of the same repository's tags, and the page's claim
     * is that the tag in the command is the table's first row — a consistency the reader can
     * check at a glance, and the only one they can. Two separately written `order by` clauses
     * cannot make that claim: they held only while nobody edited one of them, and the moment
     * the `latest` preference below was added to the command's clause alone, the command read
     * `:latest` while the table's first row read `1.4.0`. One clause, three call sites.
     *
     * `latest` FIRST, if the repository has one, and not merely by convention: the command
     * printed for a repository with NO tags is `docker pull <host>/<repo>`, the untagged form,
     * which Docker itself resolves as `:latest`. Naming a different tag for the repository
     * beside it would have one page say `:latest` for one repository and `:1.4.0` for another,
     * for no reason a reader can see.
     *
     * Then newest `updated_at` — a tag row is touched every time the tag is RE-POINTED, at a
     * different manifest. Deliberately not "most recently pushed": ManifestStore::put() writes
     * the tag with `OciTag::updateOrCreate(..., ['manifest_id' => …])`, so re-pushing a tag
     * that already points at the same manifest changes no attribute and moves no timestamp.
     *
     * `name` descending last, so two tags sharing a timestamp to the second — the ordinary
     * outcome of one `docker push` of a multi-tag build — still order deterministically rather
     * than by insertion order.
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeInPullOrder(Builder $query): Builder
    {
        return $query
            ->orderByRaw('case when name = ? then 0 else 1 end', ['latest'])
            ->orderByDesc('updated_at')
            ->orderByDesc('name');
    }

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
