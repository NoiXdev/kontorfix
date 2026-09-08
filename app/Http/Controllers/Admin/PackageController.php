<?php

namespace App\Http\Controllers\Admin;

use App\Enums\PackageSourceMode;
use App\Enums\PackageType;
use App\Enums\RetentionRuleType;
use App\Http\Controllers\Concerns\ScopesToAdministeredOrgs;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StorePackageRequest;
use App\Http\Requests\Admin\UpdatePackageAbandonmentRequest;
use App\Jobs\SyncPackage;
use App\Models\GitCredential;
use App\Models\Group;
use App\Models\OciManifest;
use App\Models\OciTag;
use App\Models\Organization;
use App\Models\Package;
use App\Models\PackageVersion;
use App\Models\PythonDist;
use App\Models\RetentionPolicy;
use App\Rules\NotRedactedCredentialUrl;
use App\Services\Oci\BlobStore;
use App\Services\Oci\Retention\RetentionRunner;
use App\Services\Package\PackageDependencies;
use App\Services\Package\SharedAssignment;
use App\Services\Registry\RegistryTypeService;
use App\Services\Registry\RegistryUrl;
use App\Services\Registry\SetupSnippetBuilder;
use App\Services\Scope\OrgScope;
use App\Services\Vcs\RepositoryProbe;
use App\Support\ActivityPresenter;
use App\Support\CredentialUrl;
use App\Support\RepositoryAuthority;
use App\Support\RepositoryUrlRules;
use App\Support\Retention\RetentionRuleSetValidator;
use App\Support\VersionOrder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class PackageController extends Controller
{
    use ScopesToAdministeredOrgs;

    // The sort column never comes from the request — only a key that selects one. This
    // route takes untrusted query-string values and is not throttled, and this controller
    // already carries the scar from that: a malformed `group` value reached a Postgres
    // uuid comparison, raised SQLSTATE[22P02], and because nothing rendered the error
    // every request appended a stack trace *with its bound parameters* to an unrotated
    // log. An unknown key here falls back to the existing default order rather than
    // raising. `groups_count` is sortable because `withCount('groups')` below puts a real
    // SQL alias on the query.
    private const SORTABLE = [
        'name' => 'name',
        'type' => 'type',
        'sync_status' => 'sync_status',
        'groups_count' => 'groups_count',
        'synced_at' => 'synced_at',
    ];

    public function index(Request $request): Response
    {
        $q = trim((string) $request->query('q', ''));
        $type = $request->query('type');
        $status = $request->query('status');
        $group = $request->query('group');
        $sort = (string) $request->query('sort', '');
        $direction = $request->query('direction') === 'desc' ? 'desc' : 'asc';

        $packages = $this->scopePackageQuery(Package::query())
            ->withCount('groups')
            ->when($q !== '', fn ($query) => $query->where('name', 'ilike', '%'.addcslashes($q, '%_\\').'%'))
            // PackageType::tryFrom(), not a hardcoded list: the enum's own docblock
            // promises that adding a type means editing PackageType, not chasing every
            // hardcoded copy of its case list — this one silently left Docker unfilterable
            // (selecting it in the enum-driven dropdown returned the UNFILTERED list,
            // rather than an empty or Docker-only one) until it was found.
            ->when(is_string($type) && PackageType::tryFrom($type) !== null, fn ($query) => $query->where('type', $type))
            ->when(in_array($status, ['pending', 'syncing', 'synced', 'failed'], true), fn ($query) => $query->where('sync_status', $status))
            // `group` is a plain query-string value on a route with no throttle, and it
            // lands on a Postgres `uuid` comparison: a malformed one raised
            // SQLSTATE[22P02], which nothing renders, so every request appended a stack
            // trace with its bound parameters to an unrotated log. Str::isUuid decides
            // this — not a character-class pattern; the two attempts at one on this branch
            // were both satisfied by 36 dashes. A value that cannot be a group id matches
            // no group, so the answer is an empty list, not an unfiltered one.
            ->when(is_string($group) && $group !== '', fn ($query) => Str::isUuid($group)
                ? $query->whereHas('groups', fn ($g) => $g->whereKey($group))
                : $query->whereRaw('1 = 0'))
            ->when(
                isset(self::SORTABLE[$sort]),
                fn ($query) => $query->orderBy(self::SORTABLE[$sort], $direction),
                fn ($query) => $query->latest(),
            )
            ->paginate(25)
            ->withQueryString()
            ->through(fn (Package $p) => [
                'id' => $p->id,
                'type' => $p->type,
                'source_mode' => $p->source_mode->value,
                'name' => $p->name,
                'sync_status' => $p->sync_status,
                'sync_error' => $p->sync_error,
                'groups_count' => $p->groups_count,
                'synced_at' => $p->synced_at?->diffForHumans(),
                'is_abandoned' => $p->isAbandoned(),
                'shared' => $p->shared,
            ]);

        return Inertia::render('admin/packages/Index', [
            'packages' => $packages,
            'groups' => $this->scopeGroupQuery(Group::query())->orderBy('name')->get(['id', 'name', 'slug']),
            'filters' => [
                'q' => $q,
                'type' => $type,
                'status' => $status,
                'group' => $group,
                'sort' => isset(self::SORTABLE[$sort]) ? $sort : null,
                'direction' => $direction,
            ],
            // Only instance-enabled registry types are offered in the filter.
            'registryTypes' => app(RegistryTypeService::class)->globalTypes(),
            // Source-mode options per package type. Kept here too (not just on create()):
            // NpmSourceModeTest asserts on this prop directly against `GET /admin/packages`,
            // independent of whether the current Index.vue happens to render it — removing it
            // would break that pre-existing, unrelated-to-this-task coverage.
            'sourceModes' => $this->sourceModesPayload(),
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('admin/packages/Create', [
            'groups' => $this->scopeGroupQuery(Group::query())->orderBy('name')->get(['id', 'name', 'slug']),
            // Only instance-enabled registry types are offered when creating a package.
            'registryTypes' => app(RegistryTypeService::class)->globalTypes(),
            // Source-mode options per package type. npm has exactly one, so the create page
            // hides the selector for it rather than offering a rejected choice.
            'sourceModes' => $this->sourceModesPayload(),
            // Managed git credentials the user may assign (never exposes the token).
            'gitCredentials' => $this->gitCredentialOptions(),
        ]);
    }

    /**
     * Source-mode options per package type, keyed by type value. Shared by index() (kept for
     * NpmSourceModeTest, see above) and create() (which actually renders it).
     *
     * @return array<string, array<int, array{value: string, label: string}>>
     */
    private function sourceModesPayload(): array
    {
        return collect(PackageType::cases())
            ->mapWithKeys(fn (PackageType $t): array => [$t->value => array_map(
                fn (PackageSourceMode $m): array => ['value' => $m->value, 'label' => $m->label()],
                PackageSourceMode::allowedFor($t)
            )])
            ->all();
    }

    public function show(Request $request, Package $package, PackageDependencies $deps, RegistryUrl $registryUrl, SetupSnippetBuilder $snippets): Response
    {
        $this->assertCanTouchPackage($package);

        // A Docker repository has nothing in common with the other three types' detail
        // page: no versions, no git source, no sync job (isPublishBased() covers it, but
        // there is no PackageVersion row to point "Versionen" at either) — and it needs a
        // page the other three have no use for at all, plate 2's tag table with its
        // occupied/shared size composition. Branching here, before any of the generic
        // payload below is assembled, keeps that composition logic (and the size rule
        // that goes with it) out of a method that would otherwise carry every type's
        // concerns at once.
        if ($package->type === PackageType::Docker) {
            return $this->showDocker($request, $package, $registryUrl);
        }

        // `groups.organization:id,slug` on top of the group's own columns: the registry list
        // below prints each registry's URL, and RegistryUrl reads the organization's slug for
        // the first segment. Without the relation this would be one lazy load per row; without
        // `slug` in it, a silent null and a /r//{groupSlug} on screen.
        // `groups.domains` on top: the install command below is built for one of these
        // registries, and RegistryUrl reads the domain rows to decide whether that registry is
        // addressed on its own host or on the instance host with a path prefix. Without the
        // relation it would be one lazy load per group inside that decision.
        $package->load(['versions', 'groups:id,name,slug,organization_id', 'groups.organization:id,slug', 'groups.domains']);
        $package->setRelation('versions', VersionOrder::sort($package->versions));

        // `assertCanTouchPackage()` asserts that the package's OWNER is in the active scope
        // (it compares `organization_id` directly since Task 7); it says nothing about the
        // registries the package is attached to. Serialising the whole relation therefore
        // handed a customer-org admin the id, name and slug of every *other* organization's
        // registry the package is shared into. Such a row can no longer be created — the
        // attach guard and the enforcement migration both refuse it — but this filter stays:
        // it is defensive against pre-invariant data, and the caller has no business reading
        // those rows either way, so they get a count instead.
        $scope = app(OrgScope::class);
        $visibleGroups = $scope->spansAllOrganizations()
            ? $package->groups
            : $package->groups->whereIn('organization_id', $this->scopedOrgIds());

        // ONE SOURCE FOR THE COMMAND, ON THE OPERATOR'S SIDE TOO. This tab used to assemble
        // `{composer: …, npm: …, python: `pip install ${name}`}` in the `.vue` file — the exact
        // registry-less pip command this whole change removed from the portal, still being
        // printed one page over. `pip install kernmodul` does not fail: it resolves against
        // PyPI and installs whatever a stranger published under that name.
        //
        // A command needs a registry, and a package can be in several. The rule is
        // showDocker()'s, so the two halves of this controller pick the same one: a registry
        // with a custom domain first, because its address is the shorter one, then the first
        // visible registry, addressed on the instance host. Deterministic either way —
        // Collection::first() preserves the `groups` query's own order.
        //
        // Null when the package is in NO registry this viewer can see. There is no address to
        // build a command from then, and the tab says so instead of printing a registry-less
        // one. Docker never reaches this: show() redirects to showDocker() above.
        $installGroup = $visibleGroups->first(fn (Group $g): bool => $g->domains->isNotEmpty())
            ?? $visibleGroups->first();

        // Python is file-centric (multiple dists per version), so its "versions" and stats
        // come from the python_dists table rather than package_versions.
        $isPython = $package->type === PackageType::Python;
        $dists = $isPython ? $package->pythonDists()->orderByDesc('uploaded_at')->get() : collect();

        return Inertia::render('admin/packages/Show', [
            'package' => [
                'id' => $package->id,
                'type' => $package->type->value,
                'source_mode' => $package->source_mode->value,
                'is_git_sourced' => $package->isGitSourced(),
                'name' => $package->name,
                'description' => $package->description,
                'readme_html' => $package->readme_html,
                // The one read path of this column that did not redact. An inline
                // `https://x-access-token:<PAT>@…` is a supported shape here, and the
                // reader may be an admin of a *different* tenant sharing the registry —
                // the prior-#10 shared-pool state. Aligned with the five siblings that
                // already redact; NotRedactedCredentialUrl on the update route refuses the
                // marker on its way back in, so a withheld value cannot silently destroy
                // the credential it was withheld from.
                'repository_url' => CredentialUrl::redact($package->repository_url),
                'git_credential_id' => $package->git_credential_id,
                'has_repository_token' => $package->repository_token !== null,
                'sync_status' => $package->sync_status->value,
                'sync_error' => $package->sync_error,
                'synced_at' => $package->synced_at?->diffForHumans(),
                'abandoned_at' => $package->abandoned_at?->toDateString(),
                'replacement_package' => $package->replacement_package,
                'abandonment_reason' => $package->abandonment_reason,
                'shared' => $package->shared,
            ],
            // Whether the viewer holds the share-packages ability at all — passed rather
            // than re-derived in Vue so the front end never restates the gate's rule (the
            // instance setting it reads is not itself exposed to the client).
            'canSharePackages' => (bool) $request->user()?->can('share-packages'),
            // Managed credentials assignable to this package: own, global, or explicitly
            // shared to the package's owning organization (never exposes the token).
            'gitCredentials' => GitCredential::usableBy($package->organization)
                ->orderBy('name')->get(['id', 'name', 'provider'])
                ->map(fn (GitCredential $c) => ['id' => $c->id, 'name' => $c->name, 'provider' => $c->provider->value]),
            'versions' => $package->versions->map(fn (PackageVersion $v) => [
                'version' => $v->version_pretty ?? $v->version,
                'released_at' => $v->released_at?->toDateString(),
                'reference' => $v->source_reference,
                'dependencies' => $deps->for($package->type, $v->metadata ?? []),
                'download_count' => $v->download_count,
                'dist_size' => $v->dist_size,
            ]),
            // Python distribution files (empty for other types).
            'pythonDists' => $dists->map(fn (PythonDist $d) => [
                'filename' => $d->filename,
                'version' => $d->version,
                'filetype' => $d->filetype,
                'size' => $d->size,
                'download_count' => $d->download_count,
                'uploaded_at' => $d->uploaded_at?->toDateString(),
            ]),
            'groups' => $visibleGroups->map(fn (Group $g) => ['id' => $g->id, 'name' => $g->name, 'slug' => $g->slug, 'url_path' => $registryUrl->path($g)])->values(),
            'sharedElsewhere' => $package->groups->count() - $visibleGroups->count(),
            // The Installation tab's whole content — see $installGroup above.
            'install' => $installGroup === null
                ? null
                : $snippets->installCommand($installGroup, $package->type, $package->name),
            'stats' => $isPython ? [
                'downloads' => (int) $dists->sum('download_count'),
                'storage_bytes' => (int) $dists->sum('size'),
                'versions' => $dists->pluck('version')->unique()->count(),
            ] : [
                'downloads' => (int) $package->versions->sum('download_count'),
                'storage_bytes' => (int) $package->versions->sum('dist_size'),
                'versions' => $package->versions->count(),
            ],
            'activities' => ActivityPresenter::recentFor($package),
        ]);
    }

    /**
     * A Docker repository's detail page (plate 2 of the approved mockups): the tag table,
     * with its own size composition, plus the same ownership/assignment/sharing/activity
     * surfaces every other package type gets from show() above.
     *
     * The size composition is the point of this method, not an afterthought, and it
     * measures actual DISK bytes — the blobs (layers and config) a manifest references —
     * never the manifest DOCUMENT's own byte size. `OciManifest::size` is
     * `strlen($payload)`: the JSON that names a layer, typically a few hundred bytes,
     * regardless of whether that layer is 4 KiB or 800 MiB. Summing THAT into an "occupied"
     * total (an earlier version of this method did exactly that) displays a
     * multi-hundred-megabyte image as a few hundred bytes — wrong by six orders of
     * magnitude, not a rounding difference.
     *
     * Two tags can point at the same manifest (a `latest` alias next to the version it
     * currently means), and a manifest is counted once regardless of how many tags name
     * it — an occupied total that counted every tag's manifest in full would double real
     * disk usage for every alias. `shared_bytes` is the subset of `occupied_bytes` that
     * belongs to a manifest more than one tag points at (the SAME manifest under two
     * names) — deliberately narrower than "any blob two different images happen to share",
     * which `occupied_bytes` itself already dedupes away (see reachableBlobDigests()) but
     * which no single tag ROW could sensibly be credited or blamed for. A tag on such a
     * manifest reports `size_bytes` null (never a share of the total, which would be
     * inventing a number the template cannot state a source for) and the template renders
     * that as "geteilt".
     *
     * A manifest no tag points to directly — the per-platform child manifests of a
     * multi-arch index, which buildx pushes BY DIGEST, never by tag (spec §2) — is still
     * counted, but only when it is reachable from a manifest that IS tagged:
     * reachableBlobDigests() walks an index's own `manifests[]` entries to the child
     * OciManifest rows they name, so an index's real multi-platform disk footprint is
     * counted under the ONE tag that names the index, not left out because the children
     * themselves carry no tag of their own. A manifest reachable from NO tag at all — a
     * fully orphaned digest push, or an index whose own tag was later moved elsewhere — is
     * not counted anywhere on this page, the same as before this fix: "occupied" here means
     * what this repository's tags currently serve, not an audit of everything the database
     * still holds a row for. That is the blob sweeper's job (Plan B), not this page's.
     */
    private function showDocker(Request $request, Package $package, RegistryUrl $registryUrl): Response
    {
        // `groups.domains`: since ResolveOciContext every one of this package's registries
        // can address it, so the panel below asks a narrower question — which of them has a
        // hostname of its own, because that is the SHORTER address and the absence of one is
        // what puts the note under the snippet. See the comment on `$dockerGroup`.
        $package->load(['groups:id,name,slug,organization_id', 'groups.organization:id,slug', 'groups.domains']);

        // Same visibility rule show() applies above: a cross-organization row for a shared
        // package is not this caller's business beyond a count. See that method's comment.
        $scope = app(OrgScope::class);
        $visibleGroups = $scope->spansAllOrganizations()
            ? $package->groups
            : $package->groups->whereIn('organization_id', $this->scopedOrgIds());

        $tags = $package->ociTags()->with('manifest')->orderByDesc('updated_at')->get();

        // How many tags point at each manifest — the one fact both the header total and
        // every row's "own bytes or shared" answer are built from.
        $tagsPerManifest = $tags->groupBy('manifest_id')->map->count();

        // Each manifest counted once, by id, however many tags name it — not once per tag.
        $uniqueManifests = $tags->pluck('manifest')->filter()->unique('id');

        $blobStore = app(BlobStore::class);
        $organizationId = (string) $package->organization_id;

        // One BlobStore lookup per DISTINCT digest referenced by any tagged manifest,
        // memoized so a base layer shared across many tags/manifests costs one query, not
        // one per reference — the same read-count discipline platformFor() below already
        // applies to config blobs (Task 8's own fix round: platform used to be read once
        // per TAG rather than once per unique manifest).
        $blobSizeCache = [];
        $sizeOf = function (string $digest) use (&$blobSizeCache, $blobStore, $organizationId): int {
            if (! array_key_exists($digest, $blobSizeCache)) {
                $blob = $blobStore->find($organizationId, $digest);
                $blobSizeCache[$digest] = $blob === null ? 0 : $blob->size;
            }

            return $blobSizeCache[$digest];
        };

        $digestsByManifest = $uniqueManifests->mapWithKeys(
            fn (OciManifest $m): array => [$m->id => array_values(array_unique($this->reachableBlobDigests($m, $package)))]
        );
        $bytesByManifest = $digestsByManifest->map(
            fn (array $digests): int => array_sum(array_map($sizeOf, $digests))
        );

        // Repository-wide total: every distinct blob digest reachable from ANY tagged
        // manifest, counted once — not the sum of $bytesByManifest, which would
        // double-count a base layer two DIFFERENT (non-aliased) tags both build on. The
        // real disk holds one copy of that layer regardless of how many images reference
        // it, so the total does too.
        $occupiedBytes = (int) $digestsByManifest->flatten()->unique()->sum($sizeOf);

        $sharedBytes = (int) $uniqueManifests
            ->filter(fn (OciManifest $m): bool => ($tagsPerManifest->get($m->id) ?? 0) > 1)
            ->sum(fn (OciManifest $m): int => $bytesByManifest->get($m->id) ?? 0);

        // Computed once per UNIQUE manifest (over `$uniqueManifests`, the same collection
        // the size composition above already deduplicated to), never once per tag. Two
        // tags aliasing one manifest — `latest` next to the version it currently means, the
        // exact shape the shared-bytes test below sets up — share one BlobStore read of the
        // config blob, not two: the read count scales with distinct manifests, not with how
        // many names point at them.
        $platformByManifest = $uniqueManifests->mapWithKeys(
            fn (OciManifest $m): array => [$m->id => $this->platformFor($m, $organizationId, $blobStore)]
        );

        $tagRows = $tags->map(function (OciTag $tag) use ($tagsPerManifest, $platformByManifest, $bytesByManifest): array {
            $manifest = $tag->manifest;
            $shared = $manifest !== null && ($tagsPerManifest->get($tag->manifest_id) ?? 0) > 1;

            return [
                'name' => $tag->name,
                'digest' => $manifest?->digest,
                'platform' => $manifest !== null ? $platformByManifest->get($manifest->id) : null,
                // Null exactly when the manifest is shared — never the manifest's full size
                // repeated for every tag that names it, and never a fabricated fraction of
                // it either (see this method's doc comment).
                'size_bytes' => $shared || $manifest === null ? null : $bytesByManifest->get($manifest->id),
                'shared' => $shared,
                'pushed_at' => $tag->updated_at?->diffForHumans(),
            ];
        });

        // A Docker repository can be assigned to more than one registry, and since
        // ResolveOciContext every one of them is a working `docker pull` address — so this
        // is a choice between addresses, not between an address and nothing. A registry with
        // a custom domain is preferred because its reference is the shorter one; failing
        // that, the first visible registry, addressed on the instance host. Deterministic
        // either way (Collection::first() preserves the `groups` query's own order); a
        // repository shared across several domained registries is a real but rare shape this
        // page does not need to disambiguate further.
        //
        // Null only when the repository is in NO registry this viewer can see — the one
        // remaining case with no address at all, which dockerSetup.ts's
        // dockerNoRegistryMessage() states.
        $dockerGroup = $visibleGroups->first(fn (Group $g): bool => $g->domains->isNotEmpty())
            ?? $visibleGroups->first();

        return Inertia::render('admin/packages/DockerTags', [
            'package' => [
                'id' => $package->id,
                'type' => $package->type->value,
                'name' => $package->name,
                'description' => $package->description,
                'abandoned_at' => $package->abandoned_at?->toDateString(),
                'replacement_package' => $package->replacement_package,
                'abandonment_reason' => $package->abandonment_reason,
                'shared' => $package->shared,
            ],
            'canSharePackages' => (bool) $request->user()?->can('share-packages'),
            'groups' => $visibleGroups->map(fn (Group $g) => ['id' => $g->id, 'name' => $g->name, 'slug' => $g->slug, 'url_path' => $registryUrl->path($g)])->values(),
            'sharedElsewhere' => $package->groups->count() - $visibleGroups->count(),
            // Plate 1's access panel, package-scoped: where a Docker client reaches THIS
            // repository. The same three facts SetupSnippetBuilder states for the
            // registry-level tab, from the same two RegistryUrl methods, so the two surfaces
            // cannot disagree about one registry's address.
            //
            // `host` used to be null for a registry without a domain, and the page rendered
            // an empty state saying images were impossible there. Both were made false by
            // path addressing. Null now means only "this repository is in no registry this
            // viewer can see", which is the one case with genuinely nothing to print.
            'access' => [
                'host' => $dockerGroup !== null ? $registryUrl->dockerHost($dockerGroup) : null,
                'repository_prefix' => $dockerGroup !== null ? $registryUrl->dockerRepositoryPrefix($dockerGroup) : null,
                'has_domain' => $dockerGroup !== null && $dockerGroup->domains->isNotEmpty(),
            ],
            'tags' => $tagRows->values(),
            'stats' => [
                'tag_count' => $tags->count(),
                'occupied_bytes' => $occupiedBytes,
                'shared_bytes' => $sharedBytes,
            ],
            'activities' => ActivityPresenter::recentFor($package),
            'retention' => $this->retentionCard($request, $package),
        ]);
    }

    /**
     * The retention card's payload: which policy (or inline rule set) resolves for this
     * repository, from which tier, what the next run would remove — and, for whoever
     * administers the owning organization, the selector and the inline-rule editor.
     *
     * `policies` carries only PUBLISHED (is_global) policies for a non-super caller: which
     * other rule sets exist on the instance is operator configuration, and the card must not
     * double as a catalogue of it. A super-admin sees every policy.
     *
     * @return array<string, mixed>
     */
    private function retentionCard(Request $request, Package $package): array
    {
        $runner = app(RetentionRunner::class);
        $report = $runner->dryRun($package);
        $resolution = $report?->resolution;

        $user = $request->user();
        $isSuper = (bool) $user?->isSuperAdmin();
        // The owning organization's admin may set the package tier — the operator decision
        // that made the instance default a default. administers() short-circuits true for
        // a super-admin, so one check covers both.
        $canAssign = (bool) $user?->administers((string) $package->organization_id);

        return [
            'policy' => $resolution?->policy === null ? null : ['id' => $resolution->policy->id, 'name' => $resolution->policy->name],
            'tier' => $resolution?->tier,
            'label' => $resolution?->label(),
            'rules' => $resolution === null ? [] : $resolution->describedRules(),
            'dry_run' => $report?->toArray(),
            'can_assign' => $canAssign,
            'selected_policy_id' => $package->retention_policy_id,
            'inline_rules' => $package->retention_rules,
            'rule_types' => RetentionRuleType::options(),
            // A non-super caller sees only PUBLISHED policies — which other rule sets exist
            // on the instance is operator configuration.
            'policies' => $canAssign
                ? RetentionPolicy::query()
                    ->when(! $isSuper, fn ($query) => $query->where('is_global', true))
                    ->orderBy('name')
                    ->get(['id', 'name'])
                    ->map(fn (RetentionPolicy $policy): array => ['id' => (string) $policy->id, 'name' => $policy->name])
                    ->all()
                : [],
        ];
    }

    /**
     * A repository's retention: the named policy it selects, and/or its anonymous inline
     * rules. In the ORG-SCOPED group since the operator decision that the package tier
     * wins unconditionally — the owning organization's admin sets it, and the instance
     * default became a default rather than a mandate. Two guards remain:
     *
     *   - assertCanTouchPackage(): only the owning organization (or a super-admin).
     *   - A non-super caller may select only PUBLISHED (is_global) policies. Naming an
     *     existing but unpublished policy is refused 403 — the same abort_unless() shape
     *     assertCanTouchPackage() itself uses — not folded into validation: this is an
     *     authorization decision (who this policy is for), not a "does this id exist" one,
     *     and the two must not share a status code.
     */
    public function updateRetention(Request $request, Package $package): RedirectResponse
    {
        $this->assertCanTouchPackage($package);

        // 409, not validation: the route resolved a real package, but retention operates
        // on tags and only Docker repositories have any — the same reason the resolver
        // answers null for every other type.
        abort_if($package->type !== PackageType::Docker, 409, 'Aufbewahrungsrichtlinien gibt es nur für Image-Repositories.');

        $data = $request->validate([
            'retention_policy_id' => ['sometimes', 'nullable', 'uuid', 'exists:retention_policies,id'],
            'retention_rules' => ['sometimes', 'nullable', 'array', 'min:1', RetentionRuleSetValidator::rule()],
        ]);

        $isSuper = (bool) $request->user()?->isSuperAdmin();

        if (! $isSuper && array_key_exists('retention_policy_id', $data) && $data['retention_policy_id'] !== null) {
            $isGlobal = RetentionPolicy::whereKey($data['retention_policy_id'])->value('is_global');
            abort_unless((bool) $isGlobal, 403, 'Diese Richtlinie ist nicht veröffentlicht.');
        }

        $update = [];
        if (array_key_exists('retention_policy_id', $data)) {
            $update['retention_policy_id'] = $data['retention_policy_id'];
        }
        if (array_key_exists('retention_rules', $data)) {
            $update['retention_rules'] = $data['retention_rules'] === null
                ? null
                : RetentionRuleSetValidator::normalise($data['retention_rules']);
        }

        abort_if($update === [], 422, 'Nichts zu ändern.');

        $package->update($update);

        return back()->with('success', 'Aufbewahrung aktualisiert.');
    }

    /**
     * The card's "Anwenden": one repository, re-resolved at apply time, with the caller as
     * causer. Super-only for the same reason assignment is — applying is the other half of
     * the same lever.
     */
    public function applyRetention(Request $request, Package $package, RetentionRunner $runner): RedirectResponse
    {
        // Whoever may edit the package's retention may run it — the same boundary as
        // updateRetention(), for the same reason.
        $this->assertCanTouchPackage($package);

        abort_if($package->type !== PackageType::Docker, 409, 'Aufbewahrungsrichtlinien gibt es nur für Image-Repositories.');

        $report = $runner->apply($package, $request->user());

        return back()->with('success', sprintf(
            '%d Tag(s) entfernt. Speicherplatz wird erst von der Speicherbereinigung freigegeben, nach Ablauf der Schonfrist.',
            $report === null ? 0 : count($report->removed()),
        ));
    }

    /**
     * Every blob digest reachable from $manifest within $package: its own config and
     * layer digests for an image manifest, plus — recursively — the same for every
     * per-platform CHILD manifest a multi-arch index names, matched by digest against
     * this package's own oci_manifests rows (buildx pushes each platform's manifest by
     * digest, untagged, before the index itself — see showDocker()'s own doc comment on
     * why an untagged child still counts here even though it counts nowhere as its OWN
     * top-level row). A child digest with no matching row (never pushed, or a database
     * restore older than the manifest) is skipped rather than guessed at, the same
     * "display nicety, not an audit" stance platformFor() below takes.
     *
     * $visited guards a pathological index naming itself, or a cycle across two indexes
     * naming each other, as one of its own children — neither is valid OCI, but this
     * method must not infinite-loop on a malformed payload a client managed to push.
     *
     * @param  array<string, true>  $visited
     * @return list<string>
     */
    private function reachableBlobDigests(OciManifest $manifest, Package $package, array &$visited = []): array
    {
        if (isset($visited[$manifest->id])) {
            return [];
        }
        $visited[$manifest->id] = true;

        $payload = json_decode($manifest->payload, true);
        if (! is_array($payload)) {
            return [];
        }

        $digests = [];

        $configDigest = $payload['config']['digest'] ?? null;
        if (is_string($configDigest)) {
            $digests[] = $configDigest;
        }

        foreach ($payload['layers'] ?? [] as $layer) {
            $layerDigest = is_array($layer) ? ($layer['digest'] ?? null) : null;
            if (is_string($layerDigest)) {
                $digests[] = $layerDigest;
            }
        }

        foreach ($payload['manifests'] ?? [] as $child) {
            $childDigest = is_array($child) ? ($child['digest'] ?? null) : null;
            if (! is_string($childDigest)) {
                continue;
            }

            $childManifest = OciManifest::where('package_id', $package->id)->where('digest', $childDigest)->first();
            if ($childManifest !== null) {
                array_push($digests, ...$this->reachableBlobDigests($childManifest, $package, $visited));
            }
        }

        return $digests;
    }

    /**
     * Best-effort platform ("os/architecture") for one manifest, or null when it cannot be
     * answered — never a guess. Two shapes are understood:
     *
     *   - A multi-arch index: its own payload lists one descriptor per platform directly,
     *     no blob read needed. Summarised as "multi-arch" once more than one distinct
     *     platform is named, since the column has room for one answer, not a list.
     *   - A single image manifest: the OCI Image Manifest carries no platform field of its
     *     own — it lives in the config blob the manifest points at (`config.digest`), the
     *     Image Configuration spec's `os`/`architecture` fields — so this reads that blob
     *     via BlobStore, the one class that touches the artifacts disk for OCI content.
     *
     * A missing blob, unparsable JSON, or an unrecognised shape all fall through to null
     * rather than raising: this is a display nicety on an admin table, and a config blob
     * genuinely can be absent (a database restore older than the artifacts volume) without
     * that being this page's problem to surface as an error.
     */
    private function platformFor(OciManifest $manifest, string $organizationId, BlobStore $blobStore): ?string
    {
        $payload = json_decode($manifest->payload, true);
        if (! is_array($payload)) {
            return null;
        }

        if (isset($payload['manifests']) && is_array($payload['manifests'])) {
            $platforms = collect($payload['manifests'])
                ->map(function ($entry) {
                    $platform = is_array($entry) ? ($entry['platform'] ?? null) : null;
                    if (! is_array($platform)) {
                        return null;
                    }
                    $os = $platform['os'] ?? null;
                    $arch = $platform['architecture'] ?? null;

                    return (is_string($os) && is_string($arch)) ? "{$os}/{$arch}" : null;
                })
                ->filter()
                ->unique()
                ->values();

            return match (true) {
                $platforms->isEmpty() => null,
                $platforms->count() === 1 => $platforms->first(),
                default => 'multi-arch',
            };
        }

        $configDigest = $payload['config']['digest'] ?? null;
        if (! is_string($configDigest)) {
            return null;
        }

        $blob = $blobStore->find($organizationId, $configDigest);
        if ($blob === null) {
            return null;
        }

        $stream = $blobStore->readStream($blob);
        if ($stream === null) {
            return null;
        }

        $config = json_decode(stream_get_contents($stream) ?: '', true);
        fclose($stream);

        if (! is_array($config)) {
            return null;
        }

        $os = $config['os'] ?? null;
        $arch = $config['architecture'] ?? null;

        return (is_string($os) && is_string($arch)) ? "{$os}/{$arch}" : null;
    }

    /**
     * The package's sync status alone, for the detail page to reconcile itself against.
     *
     * `PackageSynced` is broadcast on the `operator` channel the moment the job finishes.
     * Creating a package dispatches `SyncPackage` and redirects straight to this package's
     * detail page, so the browser still has to load the page, open the WebSocket,
     * authenticate at `/broadcasting/auth` and subscribe — and for a small repository the
     * job routinely wins that race. Reverb does not replay to a channel nobody had joined,
     * so the badge stayed on "Wartet" until somebody reloaded by hand. Realtime is also
     * optional here: `resources/js/echo.ts` only builds `window.Echo` when a Reverb key was
     * present at asset-build time, and with none the page would never have been corrected
     * at all.
     *
     * Deliberately not the `show` action with a partial reload: that one loads versions,
     * registries with their organizations, Python distributions and the activity log to
     * answer a question about two columns. Same guard as every other read of this package.
     */
    public function syncStatus(Package $package): JsonResponse
    {
        $this->assertCanTouchPackage($package);

        return response()->json([
            'status' => $package->sync_status->value,
            // Same exposure as `show` above, behind the same guard — the detail page
            // prints this text next to the failed badge.
            'error' => $package->sync_error,
        ]);
    }

    public function probe(Request $request, RepositoryProbe $probe): JsonResponse
    {
        // Same URL shape — and the same German messages — as StorePackageRequest, from the
        // one shared definition. The create mask makes a successful probe a precondition
        // for saving a git-sourced package, so a URL this endpoint rejects for a reason the
        // operator cannot read is a package that can never be created.
        $data = $request->validate([
            'type' => ['required', Rule::enum(PackageType::class)],
            'repository_url' => array_merge(['required'], RepositoryUrlRules::shape()),
            'repository_token' => ['nullable', 'string', 'max:500'],
            'git_credential_id' => ['nullable', 'uuid', 'exists:git_credentials,id'],
        ], RepositoryUrlRules::messages());

        // A managed credential (if referenced and administered) takes precedence over an
        // inline token; otherwise the inline token is treated as a GitHub token.
        $token = $data['repository_token'] ?? null;
        $provider = null;
        $username = null;
        if (! empty($data['git_credential_id'])) {
            $credential = GitCredential::findOrFail($data['git_credential_id']);
            // Refuse loudly rather than silently probing without the credential: a
            // reference to a credential no organization in the active scope may use is
            // never a legitimate request. Own, global and shared all qualify — the same
            // set the create page's dropdown offers.
            $this->assertCredentialUsableInScope($credential);
            // A stored token is bound to one host and may not be probed against another.
            $this->assertCredentialPermits($credential, $data['repository_url']);

            $token = $credential->token;
            $provider = $credential->provider;
            $username = $credential->username;
        }

        $result = $probe->probe(PackageType::from($data['type']), $data['repository_url'], $token, $provider, $username);

        // This endpoint makes the instance dial an address the caller names, and it left no
        // trace at all — so an org Maintainer sweeping hosts behind the address policy was
        // indistinguishable from nobody having used the console. The URL is redacted because
        // an inline `https://x-access-token:<PAT>@…` is a supported shape here, and a token
        // in the log survives the rotation that is the response to leaking it.
        Log::info('Repository probe.', [
            'user_id' => $request->user()?->id,
            'repository_url' => CredentialUrl::redact($data['repository_url']),
            'git_credential_id' => $data['git_credential_id'] ?? null,
            'ok' => $result['ok'],
        ]);

        return response()->json($result);
    }

    public function store(StorePackageRequest $request, SharedAssignment $sharedAssignment): RedirectResponse|JsonResponse
    {
        // A package may only be attached to registries the user administers, so it can
        // never be slipped into another organization's registry.
        $groupIds = $request->validated('group_ids', []);
        foreach ($groupIds as $groupId) {
            $this->assertAdministersGroup(Group::findOrFail($groupId));
        }

        // A package must not claim a name one of these registries already serves through a
        // shared package: the end state would be one registry serving a shared and an own
        // package under one name, which SharedAssignment refuses from the assignment side.
        // `shared` is fillable but StorePackageRequest has no rule for it, so it never
        // reaches $request->safe() and the row below is always created non-shared — if that
        // ever changes, this call has to grow the assignment-side check too.
        $sharedAssignment->assertNameUnclaimedIn(
            $groupIds,
            PackageType::from($request->validated('type')),
            (string) $request->validated('name'),
        );

        // A referenced credential must be usable by the package's owning organization —
        // its own, global, or explicitly shared to it (see GitCredential::isUsableBy()) —
        // and may only be paired with a repository on the host it is bound to.
        if ($request->filled('git_credential_id')) {
            $credential = GitCredential::findOrFail($request->validated('git_credential_id'));
            $this->assertCredentialUsableBy($credential, $request->ownerOrganizationId());
            $this->assertCredentialPermits($credential, $request->validated('repository_url'));
        }

        // The source mode is authoritative (Composer is always git; npm/Python honour the
        // submitted mode) so the stored column is truthful regardless of what was sent.
        $attributes = $request->safe()->except('group_ids');
        $attributes['source_mode'] = $request->effectiveSourceMode(PackageType::from($request->validated('type')))->value;
        // The owner is the organization the selected registries belong to; the request has
        // already refused a selection spanning more than one.
        $attributes['organization_id'] = $request->ownerOrganizationId();

        $package = Package::create($attributes);
        $package->groups()->sync($groupIds);

        // Only git-sourced packages have something to sync; publish-based packages (npm,
        // Python) are filled by pushing artifacts — skip the (doomed) sync job.
        if ($package->isGitSourced() && $package->repository_url !== null) {
            SyncPackage::dispatch($package);
        }

        // The PackagePicker creates packages inline via fetch and needs the
        // created package back to add it directly to the selection.
        if ($request->expectsJson()) {
            return response()->json([
                'id' => $package->id,
                'name' => $package->name,
                'type' => $package->type,
                // Always false — creation never marks a package shared, whatever the
                // request says (see the attribute assembly above, and its regression test).
                // Stated rather than omitted so the picker's selection chips carry the same
                // shape as a searched row and the marker is decided by data, not by which
                // way the entry got into the list.
                'shared' => $package->shared,
            ], 201);
        }

        // Explicitly to the detail page, not back(): the form now lives on its own
        // `admin/packages/create` page, and back() would return there — to a freshly emptied
        // form that renders no `flash.success`, so the operator saw a blank mask and no
        // confirmation. The detail page (not the index) is the right destination because
        // creation dispatches SyncPackage: sync status, sync errors and the resync action
        // all live there, and it renders the flash.
        return redirect()->route('admin.packages.show', $package)
            ->with('success', "Paket {$package->name} angelegt — Sync gestartet.");
    }

    public function update(Request $request, Package $package): RedirectResponse
    {
        $this->assertCanTouchPackage($package);

        // The detail page is now shown the redacted URL like every other reader, and its
        // form posts back whatever it was given. A redacted value byte-identical to the
        // redaction of what is stored is that echo — it means "unchanged", not "erase the
        // credential" — so it is resolved back to the stored value before validation.
        // Anything else redacted is still refused by NotRedactedCredentialUrl: an operator
        // who edited the path around a `***` has to supply the credential for the new URL,
        // and a client inventing a marker cannot overwrite a secret with it.
        $submitted = $request->input('repository_url');
        if (is_string($submitted)
            && CredentialUrl::isRedacted($submitted)
            && $submitted === CredentialUrl::redact($package->repository_url)) {
            $request->merge(['repository_url' => $package->repository_url]);
        }

        $data = $request->validate([
            'repository_url' => array_merge(
                [Rule::requiredIf($package->isGitSourced()), 'nullable'],
                RepositoryUrlRules::shape(),
                [new NotRedactedCredentialUrl],
            ),
            'repository_token' => ['nullable', 'string', 'max:500'],
            'git_credential_id' => ['nullable', 'uuid', 'exists:git_credentials,id'],
            'remove_token' => ['sometimes', 'boolean'],
        ], RepositoryUrlRules::messages());

        // `??` could not express "clear it". An emptied field arrives as null (the global
        // ConvertEmptyStringsToNull), which the null-coalesce read as "not submitted" and
        // answered by keeping the old URL — so removing a stale repository_url, the remedy
        // SyncPackage recommends to the operator of a publish-mode package, silently did
        // nothing. An absent key still means "unchanged".
        $url = array_key_exists('repository_url', $data) ? $data['repository_url'] : $package->repository_url;

        if (! empty($data['git_credential_id'])) {
            $credential = GitCredential::findOrFail($data['git_credential_id']);
            $this->assertCredentialUsableBy($credential, $package->organization_id);
            $this->assertCredentialPermits($credential, $url);
        }

        // Moving the repository to another host while silently keeping the stored inline
        // token would ship that token to the new host on the next sync. The operator must
        // supply a token for the new host or drop the old one explicitly.
        $keepsInlineToken = $package->repository_token !== null
            && empty($data['repository_token'])
            && empty($data['remove_token']);
        if ($keepsInlineToken && ! $this->sameHost($url, $package->repository_url)) {
            throw ValidationException::withMessages([
                'repository_url' => 'Beim Wechsel des Repository-Hosts muss der gespeicherte Token neu gesetzt oder entfernt werden.',
            ]);
        }

        $update = [
            'repository_url' => $url,
            // An absent/empty credential clears the assignment.
            'git_credential_id' => $data['git_credential_id'] ?? null,
        ];
        if (! empty($data['repository_token'])) {
            $update['repository_token'] = $data['repository_token'];
        } elseif (! empty($data['remove_token'])) {
            $update['repository_token'] = null;
        }

        $package->update($update);

        // Re-sync git-sourced packages so a changed URL/token takes effect immediately.
        if ($package->isGitSourced() && $package->repository_url !== null) {
            SyncPackage::dispatch($package);
        }

        return back()->with('success', 'Paket aktualisiert.');
    }

    /**
     * Marks or unmarks a package as abandoned. Kept separate from update() rather than
     * folding into it: update() backs the narrow repository form and carries
     * credential-retarget logic that has nothing to do with abandonment, and widening it
     * would drag that logic into an unrelated write path.
     */
    public function abandonment(UpdatePackageAbandonmentRequest $request, Package $package): RedirectResponse
    {
        $this->assertCanTouchPackage($package);

        $abandoned = $request->boolean('abandoned');

        $package->update([
            // Re-marking an already-abandoned package must not reset the date — the banner
            // says "since", and an edit to the reason is not a new abandonment.
            'abandoned_at' => $abandoned ? ($package->abandoned_at ?? now()) : null,
            'replacement_package' => $abandoned ? $request->validated('replacement_package') : null,
            'abandonment_reason' => $abandoned ? $request->validated('abandonment_reason') : null,
        ]);

        return back()->with('success', $abandoned ? 'Paket als verwaist markiert.' : 'Markierung als verwaist entfernt.');
    }

    /**
     * Marks or unmarks a package as shared, gated by the `share-packages` ability
     * (super-admin always; a maintainer of the operator organization only once the
     * instance setting says so — see AppServiceProvider's gate definition).
     *
     * Deliberately not guarded by assertCanTouchPackage(): that helper checks the
     * package's organization against the caller's *active console scope*
     * (`OrgScope::ids()`, see the "active scope" vocabulary two methods above), which is
     * the wrong tool for an action whose entire purpose is to act across organization
     * scope — a super-admin (or a multi-org maintainer) who has the console scoped to one
     * customer organization is still meant to share a package the operator organization
     * owns, and `assertCanTouchPackage()` would reject that call on scope alone even
     * though `share-packages` authorizes it unconditionally. The ownership check below
     * (`$package->organization->is_operator`) is the actual security boundary for this
     * action: it is what refuses a customer-owned package regardless of who is calling,
     * and a caller-scope check adds nothing beyond it.
     *
     * The two directions are not symmetric. Setting the flag is what makes cross-organization
     * assignments legal, so nothing can be outstanding when it is set; clearing it can strand
     * assignments that were legal a moment earlier, so the clear is refused while any exist.
     */
    public function shared(Request $request, Package $package): RedirectResponse
    {
        abort_unless($request->user()?->can('share-packages'), 403);

        $data = $request->validate(['shared' => ['required', 'boolean']]);

        // Only a package the operator organization owns may be shared. A customer-owned
        // shared package would let that customer delete a dependency other customers'
        // builds resolve through it.
        if ($data['shared'] && ! $package->organization->is_operator) {
            throw ValidationException::withMessages([
                'shared' => 'Nur Pakete der Betreiber-Organisation können geteilt werden.',
            ]);
        }

        // Un-sharing is the reverse of an attach, and it can invalidate assignments that
        // were legitimate while the flag was set. The invariant at stake is the ownership
        // rule this application has held since packages became organization-owned: a
        // `group_package` row may only join a package to a registry of the organization
        // that owns it. `shared` is the single exception, so clearing it turns every
        // cross-organization row for this package back into a violation — one the ownership
        // enforcement refuses to migrate over — and, once resolution is scoped again, drops
        // the package out of those registries silently, so the name falls through to the
        // public index it was assigned there to pre-empt. Refuse and name the registries,
        // the way that enforcement does, rather than detaching rows on the operator's
        // behalf: the destructive step stays explicit.
        //
        // Every row counts here, expired or not — deliberately unlike SharedAssignment,
        // which asks what a registry SERVES and so skips expired assignments. The rule
        // above is about the row existing at all and says nothing about `available_until`,
        // so a lapsed row is exactly as much of a violation as a live one.
        if (! $data['shared']) {
            $foreign = $package->groups()
                ->where('groups.organization_id', '!=', $package->organization_id)
                ->orderBy('groups.name')
                ->pluck('groups.name');

            if ($foreign->isNotEmpty()) {
                throw ValidationException::withMessages([
                    'shared' => $foreign->count() === 1
                        ? "Dieses Paket ist noch der Registry {$foreign->first()} einer anderen Organisation "
                            .'zugewiesen. Entfernen Sie es dort zuerst.'
                        : 'Dieses Paket ist noch Registrys anderer Organisationen zugewiesen: '
                            .$foreign->implode(', ').'. Entfernen Sie es dort zuerst.',
                ]);
            }
        }

        $package->update(['shared' => $data['shared']]);

        return back()->with('success', $data['shared']
            ? 'Paket wird jetzt für andere Organisationen freigegeben.'
            : 'Freigabe für andere Organisationen aufgehoben.');
    }

    public function destroy(Package $package): RedirectResponse
    {
        $this->assertCanTouchPackage($package);

        $package->delete();

        return back()->with('success', 'Paket gelöscht.');
    }

    /**
     * Mirrors Api\V1\PackageController::resync(): dispatching IS the point of this action,
     * so a package that cannot be synced is refused outright rather than redirected with a
     * success message that describes something which did not happen.
     */
    public function resync(Package $package): RedirectResponse
    {
        $this->assertCanTouchPackage($package);

        abort_if(! $package->isGitSourced(), 409, 'Dieses Paket ist nicht git-basiert und kann nicht synchronisiert werden.');

        SyncPackage::dispatch($package);

        return back()->with('success', 'Synchronisierung wurde eingereiht.');
    }

    /**
     * A stored git token is bound to exactly one host — pairing it with a repository
     * elsewhere would transmit the secret to that host as an Authorization header.
     */
    private function assertCredentialPermits(GitCredential $credential, ?string $url): void
    {
        if (! $credential->permits($url)) {
            throw ValidationException::withMessages([
                'repository_url' => $credential->hostMismatchMessage(),
            ]);
        }
    }

    /**
     * Aborts 403 unless $organizationId may USE the credential — its own, global, or
     * explicitly shared to it (see GitCredential::isUsableBy()). Replaces a stricter "must
     * administer the credential's own organization" check: with shared/global credentials
     * the two organizations are allowed to differ, e.g. the operator's own credential
     * assigned to a customer's package. A plain foreign credential (neither global nor
     * shared) still refuses, exactly as it did before.
     */
    private function assertCredentialUsableBy(GitCredential $credential, string $organizationId): void
    {
        abort_unless($credential->isUsableBy(Organization::findOrFail($organizationId)), 403);
    }

    /**
     * The probe endpoint has no package (and so no owning organization) to check against
     * yet — it is reached from the create page before any registry has been submitted.
     * Refuses unless the credential is usable by at least one organization in the active
     * console scope, the same set the create page's dropdown offers via
     * gitCredentialOptions() below.
     */
    private function assertCredentialUsableInScope(GitCredential $credential): void
    {
        $scopedOrgIds = $this->scopedOrgIds();

        $usable = $credential->is_global
            || in_array($credential->organization_id, $scopedOrgIds, true)
            || $credential->sharedOrganizations()->whereIn('organizations.id', $scopedOrgIds)->exists();

        abort_unless($usable, 403);
    }

    /**
     * Credentials assignable from the create page's dropdown: own (within the active
     * scope) plus global/shared ones. Widened to every organization in scope, not just
     * one, because the package's eventual owner is not known until the registry selection
     * is submitted — mirrors GitCredential::scopeUsableBy() applied to each scoped
     * organization in turn rather than to one already-known package.
     *
     * @return array<int, array{id: string, name: string, provider: string}>
     */
    private function gitCredentialOptions(): array
    {
        $scopedOrgIds = $this->scopedOrgIds();

        return GitCredential::query()
            ->where(fn ($q) => $q->whereIn('organization_id', $scopedOrgIds)
                ->orWhere('is_global', true)
                ->orWhereHas('sharedOrganizations', fn ($s) => $s->whereIn('organizations.id', $scopedOrgIds)))
            ->orderBy('name')->get(['id', 'name', 'provider'])
            ->map(fn (GitCredential $c) => ['id' => $c->id, 'name' => $c->name, 'provider' => $c->provider->value])
            ->all();
    }

    /**
     * Whether two repository URLs point at the same place a token would be sent.
     *
     * `parse_url(..., PHP_URL_HOST)` discards the port by construction, so this compared
     * `https://gitlab.corp/x` and `https://gitlab.corp:9999/x` as equal — and
     * `GitAuth::origin()` scopes the Authorization header to scheme://host:port, so they are
     * not. A Maintainer who could bind a listener on another port of the repository host
     * therefore had the organization's PAT delivered to it, unchanged, on the next sync.
     * `GitCredential::permits()` was fixed for the managed column; this asks the same
     * question through the same helper so they cannot drift again.
     *
     * Two absent URLs are the same authority (nothing moved). One absent and one present is
     * not.
     */
    private function sameHost(?string $a, ?string $b): bool
    {
        return RepositoryAuthority::of($a) === RepositoryAuthority::of($b);
    }
}
