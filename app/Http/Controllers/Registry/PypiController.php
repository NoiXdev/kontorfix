<?php

namespace App\Http\Controllers\Registry;

use App\Enums\PackageType;
use App\Exceptions\VersionConflictException;
use App\Http\Controllers\Controller;
use App\Models\Group;
use App\Models\Organization;
use App\Models\Package;
use App\Models\PythonDist;
use App\Models\RegistryToken;
use App\Services\Licence\VersionEntitlement;
use App\Services\Python\PythonName;
use App\Services\Python\PythonPublishService;
use App\Services\Python\PythonSimpleIndexBuilder;
use App\Services\RegistryAccessService;
use App\Support\CredentialUrl;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * PyPI-compatible registry: twine upload (distutils "file_upload"), the PEP 503 / PEP 691
 * "simple" repository API that pip consumes, and file downloads. Serving is scoped to the
 * resolved group; unknown projects fall through to a configured Python upstream.
 *
 * The read paths (simpleRoot/simpleProject/download) additionally branch on
 * `registryOrganization` for the `/o/{orgSlug}` org-wide aggregate (see
 * ResolvesRegistryPackage::authorizeOrganization()): the union of every project visible
 * through any of the organization's groups, with NO upstream fallthrough — an org aggregate
 * spans several groups, each with its own (or no) Python upstream, so there is no single
 * "the upstream" an org-mode miss could redirect to. Uploads remain group-only; the org
 * mount answers a twine upload with a flat 405 (see routes/registry.php).
 */
class PypiController extends Controller
{
    use ResolvesRegistryPackage;

    private const JSON_ACCEPT = 'application/vnd.pypi.simple.v1+json';

    public function __construct(
        private readonly RegistryAccessService $access,
        private readonly PythonPublishService $publisher,
        private readonly PythonSimpleIndexBuilder $builder,
        private readonly VersionEntitlement $entitlement,
    ) {}

    protected function access(): RegistryAccessService
    {
        return $this->access;
    }

    /** twine upload — multipart POST to the registry root. */
    public function upload(Request $request): Response
    {
        $group = $this->registryGroup($request);

        /** @var RegistryToken|null $token */
        $token = $request->attributes->get('registryToken');
        abort_if($token === null, 401, 'Authentication required for this registry.');
        abort_unless($this->access->canPublishToGroup($token, $group), 403);

        $normalized = PythonName::normalize((string) $request->input('name', ''));
        // Own-organization only, mirroring NpmController::respondPublish(). The read paths
        // resolve a shared project from every registry it is assigned to; a customer's
        // publish token adding a distribution to it would put that file into every other
        // customer's builds. Sharing hands out reads, never writes — so the write path asks
        // the narrower question, and answers a shared name the same way it answers an
        // unknown one.
        $pkg = $this->pythonPackagesOfGroup($group)
            ->first(fn (Package $p): bool => $p->organization_id === $group->organization_id
                && PythonName::normalize($p->name) === $normalized);
        abort_if($pkg === null, 404, 'Unknown project for this registry.');
        // A git-mirror or foreign-registry-mirror project derives its files from somewhere
        // else (git tags, or a MirrorSource) — reject uploads into it. isPublishSourced()
        // rather than isGitSourced() now that a third mode exists: both non-publish modes
        // must be refused here, not only git.
        abort_if(! $pkg->isPublishSourced(), 409, 'This project mirrors a git repository or a foreign registry and cannot be uploaded to.');

        $file = $request->file('content');
        abort_if(! $file instanceof UploadedFile || ! $file->isValid(), 400, 'Missing distribution file.');

        try {
            $this->publisher->publish($pkg, $file, $request->all());
        } catch (VersionConflictException) {
            abort(409, 'This distribution file already exists.');
        } catch (InvalidArgumentException $e) {
            abort(400, $e->getMessage());
        }

        return response('', 200);
    }

    /** Root simple index: every Python project readable in this registry. */
    public function simpleRoot(Request $request): Response
    {
        /** @var Organization|null $organization */
        $organization = $request->attributes->get('registryOrganization');
        if ($organization !== null) {
            $this->authorizeOrganization($request, $organization);

            // The org aggregate's package pool spans every PackageType, unlike
            // pythonPackagesOfGroup() below, which is scoped to Python by its own query —
            // packagesForOrganization() has no type argument, so the filter happens here.
            // unique()->sort() lives inside rootHtml() itself (see PythonSimpleIndexBuilder),
            // the same builder both branches call, so this list is not de-duplicated twice —
            // it mirrors simpleRoot()'s existing "not de-duplicated here" comment below for
            // the group branch: two rows (a customer's own project and a shared one of the
            // same PEP 503 name) may still normalise to one name, and the builder's own
            // unique() is what collapses that, not a second one added here.
            $names = $this->access->packagesForOrganization($organization)
                ->filter(fn (Package $p): bool => $p->type === PackageType::Python)
                ->map(fn (Package $p): string => PythonName::normalize($p->name))
                ->values();

            return response(
                $this->builder->rootHtml($names, $this->registryBaseUrlForOrganization($request, $organization)),
                200,
                ['Content-Type' => 'text/html; charset=utf-8'],
            );
        }

        $group = $this->registryGroup($request);
        $this->authorizeGroup($request, $group);

        /** @var RegistryToken|null $token */
        $token = $request->attributes->get('registryToken');
        // NOT de-duplicated here, unlike ComposerController::root(): with both a customer's
        // own project and a shared one of that name assigned, this pool carries two rows that
        // normalise to one PEP 503 name — but PythonSimpleIndexBuilder::rootHtml() takes
        // `unique()` over the names it is given, and Composer's list has no such builder. A
        // second `unique()` here would be a line no mutation could redden. The property is
        // pinned end-to-end by the last case in
        // tests/Feature/Registry/SharedPackageResolutionTest.php.
        $names = $this->pythonPackagesOfGroup($group)
            ->filter(fn (Package $p): bool => $this->access->canAccessPackage($token, $group, $p))
            ->map(fn (Package $p): string => PythonName::normalize($p->name))
            ->values();

        return response(
            $this->builder->rootHtml($names, $this->registryBaseUrl($request, $group)),
            200,
            ['Content-Type' => 'text/html; charset=utf-8'],
        );
    }

    /** Project detail page (files), or a redirect to the upstream for unknown projects. */
    public function simpleProject(Request $request, string $project): Response|RedirectResponse|JsonResponse
    {
        /** @var Organization|null $organization */
        $organization = $request->attributes->get('registryOrganization');
        if ($organization !== null) {
            $this->authorizeOrganization($request, $organization);
            $this->assertProxyableName($project);

            $normalized = PythonName::normalize($project);

            // organizationPackagesQuery(), not organizationPackage(): the latter matches
            // `packages.name` exactly, but a stored Python project name is not guaranteed
            // to already be in PEP 503 canonical form (twine uploads "My.Package" as-is —
            // see PypiController::upload()/PythonName::normalize()) — exactly the reason
            // pythonPackagesOfGroup() below filters in PHP rather than in SQL. The `type`
            // filter itself narrows in SQL rather than in PHP (unlike simpleRoot() above,
            // which needs every PackageType and so cannot) — this runs once per project
            // page, but there is no reason to load every Composer/npm row of the
            // organization just to discard them here. The query's own ordering (own
            // organization before shared, see organizationPackagesQuery()) makes this
            // `first()` prefer the organization's own project over a shared one of the
            // same normalised name, mirroring findLocal()'s tie-break.
            $pkg = $this->access->organizationPackagesQuery($organization)
                ->where('packages.type', PackageType::Python)
                ->get()
                ->first(fn (Package $p): bool => PythonName::normalize($p->name) === $normalized);

            // No upstream fallthrough here, unlike the group branch below: a Python upstream
            // is a row on ONE group's Upstream table, and the org aggregate spans every group
            // of the organization, each with its own (possibly different, possibly absent)
            // upstream — there is no single "the upstream" an org-mode miss could redirect
            // to. Mirrors ComposerController::metadata()'s and NpmController's org branches,
            // which reach the same conclusion for their own upstream fallthroughs. Spec's
            // error table treats "not visible to the org" the same as the group path's "not
            // accessible": a plain 404.
            if ($pkg === null) {
                abort(404);
            }

            // Out-of-licence rows are HIDDEN — absent from both representations entirely,
            // never listed-but-refused — mirroring ComposerController::metadata()'s and
            // NpmController's org branches. Filtered per-row on that row's own stored
            // `version`, since a single PythonDist is filename-keyed (several files can
            // share one version), unlike Composer/npm which filter a version list directly.
            $windows = $this->entitlement->windowsForOrganization($organization, $pkg);
            $dists = $pkg->pythonDists()->orderBy('filename')->get()
                ->filter(fn (PythonDist $d): bool => $this->entitlement->permitsAny($windows, PackageType::Python, $d->version))
                ->values();
            $base = $this->registryBaseUrlForOrganization($request, $organization);

            if (str_contains((string) $request->header('Accept'), self::JSON_ACCEPT)) {
                return response()->json($this->builder->projectJson($pkg, $dists, $base))
                    ->header('Content-Type', self::JSON_ACCEPT);
            }

            return response($this->builder->projectHtml($pkg, $dists, $base), 200, ['Content-Type' => 'text/html; charset=utf-8']);
        }

        $group = $this->registryGroup($request);
        $this->authorizeGroup($request, $group);

        // The third ecosystem, closed to match the other two. `[A-Za-z0-9._-]+` admits `.`
        // and `..`, neither of which can name a project. Measured before this line: PEP 503
        // normalisation already collapsed them to `-`, so the outbound path was
        // `/simple/-/` rather than a traversal — the impact the sibling routes had does not
        // reach here. Refused anyway, and BEFORE normalisation, so that the guarantee rests
        // on an explicit refusal rather than on a side effect of the normaliser.
        $this->assertProxyableName($project);

        $normalized = PythonName::normalize($project);

        /** @var RegistryToken|null $token */
        $token = $request->attributes->get('registryToken');
        $pkg = $this->pythonPackagesOfGroup($group)
            ->first(fn (Package $p): bool => PythonName::normalize($p->name) === $normalized
                && $this->access->canAccessPackage($token, $group, $p));

        if ($pkg !== null) {
            // Same hiding rule as the org branch above, through the group's own bounds
            // rather than a windowed union. findLocal()/pythonExistsLocally() below stay
            // unfiltered on purpose: a name assigned at any window must still suppress the
            // dependency-confusion guard's upstream fallthrough — only individual dists are
            // hidden here, never the project's existence.
            $bounds = $this->entitlement->boundsFor($group, $pkg);
            $dists = $pkg->pythonDists()->orderBy('filename')->get()
                ->filter(fn (PythonDist $d): bool => $this->entitlement->permits($bounds, PackageType::Python, $d->version))
                ->values();
            $base = $this->registryBaseUrl($request, $group);

            if (str_contains((string) $request->header('Accept'), self::JSON_ACCEPT)) {
                return response()->json($this->builder->projectJson($pkg, $dists, $base))
                    ->header('Content-Type', self::JSON_ACCEPT);
            }

            return response($this->builder->projectHtml($pkg, $dists, $base), 200, ['Content-Type' => 'text/html; charset=utf-8']);
        }

        // A private name that exists locally must never be forwarded upstream (dependency
        // confusion protection) — mirror the Composer/npm behaviour.
        if ($this->pythonExistsLocally($normalized, $group)) {
            abort(404);
        }

        $upstream = $group->upstreams()
            ->where('type', PackageType::Python)
            ->where('enabled', true)
            ->orderBy('priority')
            ->first();

        if ($upstream !== null) {
            // A 302 hands its `Location` to the client, and this endpoint is readable by
            // a pull token — or, on a public group, by nobody at all. `upstreams.url` is
            // the only place a Basic-auth mirror credential can live (UpstreamClient
            // sends the dedicated `auth_token` as a Bearer header and nothing else), so
            // concatenating it into a redirect published the mirror's password in
            // cleartext to the lowest tier the product has, and onward into every CI log
            // and proxy on the path.
            //
            // Redaction is not the fix here: `***@host` is a credential the client would
            // dial, and it still says a credential exists. The credential is removed —
            // and where one exists at all, no redirect is emitted. Sending pip to a
            // private mirror unauthenticated only trades the disclosure for a 401 while
            // still naming the internal host and the project being resolved. The
            // condition is reported on the operator health page (HealthService) rather
            // than failing silently.
            if (CredentialUrl::carries($upstream->url)) {
                Log::warning('PyPI simple-index fallthrough declined: upstream URL carries a credential.', [
                    'upstream_id' => $upstream->id,
                    'group_id' => $group->id,
                ]);

                abort(404);
            }

            $target = rtrim((string) CredentialUrl::strip($upstream->url), '/');

            return redirect()->away($target.'/simple/'.$normalized.'/', 302);
        }

        abort(404);
    }

    /** Stream a stored distribution file. */
    public function download(Request $request, string $package, string $filename): StreamedResponse
    {
        // Same reasoning as ProxyDownloadController::resolveUpstream(): whereKey() on a
        // Postgres `uuid` column raises SQLSTATE[22P02] for anything that is not one,
        // and an unrendered QueryException is a 500 plus a logged stack trace. The route
        // pattern refuses those already; this does not rely on it. Checked before either
        // branch below, since neither's package lookup is safe to run on a non-UUID value.
        abort_unless(Str::isUuid($package), 404);

        /** @var Organization|null $organization */
        $organization = $request->attributes->get('registryOrganization');
        if ($organization !== null) {
            $this->authorizeOrganization($request, $organization);

            // organizationPackagesQuery() rather than packagesForOrganization(): the latter
            // loads every visible package of the organization as full models and would be
            // filtered down to one row in PHP — `pip install` calls this once per
            // distribution file, so a full-type scan here is a hot-path cost with no
            // purpose once the id is known. whereKey() also keeps the comparison a SQL
            // `uuid` comparison, matching the group branch below: `$p->id === $package`
            // would compare case-sensitively in PHP, while Postgres' `uuid` type compares
            // case-insensitively, so an uppercase-hex id (the route pattern allows
            // `[0-9a-fA-F]`) resolved here but not there.
            $pkg = $this->access->organizationPackagesQuery($organization)
                ->where('packages.type', PackageType::Python)
                ->whereKey($package)
                ->first();
            abort_if($pkg === null, 404);
        } else {
            $group = $this->registryGroup($request);
            $this->authorizeGroup($request, $group);

            /** @var RegistryToken|null $token */
            $token = $request->attributes->get('registryToken');

            // Scoped like every other read path — own-organization, or shared. A UUID cannot
            // collide across organizations the way a name can, so this is not closing a
            // guessing attack — it closes the same cross-organization pivot row the index and
            // project page refuse, which would otherwise still stream its distributions
            // through the foreign registry. The shared clause is what lets the files of a
            // project this registry does serve actually be fetched; without it the project
            // page would link to a 404.
            //
            // Redundant since RegistryAccessService::availablePackages() states the same
            // rule, so canAccessPackage() below already refuses this row. Kept: it costs one
            // predicate, and it lets this handler be read on its own without tracing into the
            // access service.
            $pkg = Package::where('type', PackageType::Python)
                ->where(fn ($q) => $q
                    ->where('packages.organization_id', $group->organization_id)
                    ->orWhere('packages.shared', true))
                ->whereKey($package)
                ->first();
            abort_if($pkg === null || ! $this->access->canAccessPackage($token, $group, $pkg), 404);
        }

        $dist = $pkg->pythonDists()->where('filename', $filename)->firstOrFail();

        // The licence bounds ARE enforced here: a version outside the caller's window must
        // 404 exactly like an unknown file (same shape, checked BEFORE any disk access
        // below) — refused, not merely hidden from the index while still downloadable.
        // Mirrors ComposerController::dist()/NpmController's tarball endpoint.
        $permitted = $organization !== null
            ? $this->entitlement->permitsAny(
                $this->entitlement->windowsForOrganization($organization, $pkg),
                PackageType::Python,
                $dist->version,
            )
            : $this->entitlement->permits(
                $this->entitlement->boundsFor($group, $pkg),
                PackageType::Python,
                $dist->version,
            );

        if (! $permitted) {
            abort(404);
        }

        $disk = Storage::disk('artifacts');
        abort_unless($disk->exists($dist->path), 404);

        $dist->increment('download_count');

        return response()->streamDownload(function () use ($disk, $dist) {
            $stream = $disk->readStream($dist->path);
            if ($stream !== null) {
                fpassthru($stream);
                fclose($stream);
            }
        }, $filename, ['Content-Type' => 'application/octet-stream']);
    }

    /**
     * The Python projects this registry serves (unfiltered by group access — callers refine).
     *
     * assignedPackages(), not packages(): an assignment past its `available_until` serves
     * nothing, and this method is the *only* statement of that for upload(), which never
     * reaches canAccessPackage(). The read paths were already filtered — both re-ask through
     * canAccessPackage(), which reads the same relation — so the behaviour that changes here
     * is upload()'s, which now refuses a publish into a lapsed assignment. That is what npm
     * already did (packageBelongsToGroup() reads the same relation), so the two publish paths
     * no longer disagree about whether an expired row counts.
     *
     * Own-organization, or shared, for the same reason findLocal() is: the pivot row records
     * assignment, and canAccessPackage() checks assignment and group access — neither compares
     * the package's organization to the registry's. A cross-organization pivot row would
     * therefore be served here, and the enforcement migration does not rule that out: it refuses
     * on ANY cross-organization row, shared or not, but it runs once — before `packages.shared`
     * exists — so it constrains the data at that one moment and not what is written afterwards
     * (argued in full on RegistryAccessService::availablePackages(), together with what a
     * rollback past the column's migration then does). The non-shared half is held by the write
     * paths, and stated here as well, because this is the only ecosystem where ownership was
     * left implied by the access check rather than written into the query, and an invariant that
     * only one of three read paths spells out is one edit from being lost.
     *
     * A shared package is the cross-organization row that is legitimate: owned by the
     * operator organization (spec §1) and deliberately offered to others. Without this
     * clause the PyPI half of the feature would not exist — worse, once the dependency-
     * confusion guard counts an assigned shared name as hosted, pip would be answered with
     * a flat 404 for a project the operator did assign.
     *
     * Not made redundant by the same predicate now living in
     * RegistryAccessService::availablePackages(): the publish path (upload()) resolves the
     * target project through this method *without* canAccessPackage(), so here this is still
     * the only statement of the rule — and upload() narrows it back to own-organization
     * itself, because sharing hands out reads and never writes.
     *
     * Ordered own-organization-first, then by id, for the reasons given on
     * ResolvesRegistryPackage::findLocal(): simpleProject() takes the first match by
     * normalised name, spec §5 says the customer's own project wins over a shared one of that
     * name, and where the spec settles nothing the answer should at least be reproducible.
     *
     * @return Collection<int, Package>
     */
    private function pythonPackagesOfGroup(Group $group): Collection
    {
        return $group->assignedPackages()
            ->where('type', PackageType::Python)
            ->where(fn ($q) => $q
                ->where('packages.organization_id', $group->organization_id)
                ->orWhere('packages.shared', true))
            ->orderByRaw('(packages.organization_id = ?) desc, packages.id', [$group->organization_id])
            ->get();
    }

    /**
     * The Python half of the dependency-confusion guard: the addressed organization owns
     * the name, or a shared package of that name is assigned to this registry. Both halves
     * and their different scopes are argued on
     * ResolvesRegistryPackage::packageExistsLocally(); this is the same predicate, filtered
     * in PHP because PEP 503 normalisation happens outside SQL.
     *
     * NOT pythonPackagesOfGroup(), and the resemblance is the trap. That method answers
     * "what is assigned to *this registry*"; this one answers "is the name hosted", and the
     * organization half must stay assignment-free — a private project attached to no
     * registry still must not have its name asked about at pypi.org. Reusing that method
     * here would drop the organization half's unassigned rows and leak those names upstream;
     * reusing this one there would serve projects no operator assigned. Two questions, two
     * predicates, and only the shared half of this one is registry-scoped.
     *
     * The two also read different relations, and that difference is deliberate: that method
     * reads assignedPackages(), this one packages(), so a project whose share has lapsed
     * stops being served while its name keeps suppressing the fallthrough. Without that, the
     * first `pip install` after an assignment expires resolves the project from pypi.org —
     * from whoever registered the name there — with no act by anyone. See
     * ResolvesRegistryPackage::packageExistsLocally() for the full argument.
     *
     * @see tests/Feature/Registry/SharedPackageUpstreamTest.php — as with the Composer and
     * npm guard, the positive direction of the shared clause is reachable through HTTP only
     * via a lapsed assignment; for a live one, pythonPackagesOfGroup() answers first, so the
     * direct predicate test in that file is its only coverage. The negative direction is
     * covered end-to-end too.
     */
    private function pythonExistsLocally(string $normalized, Group $group): bool
    {
        return Package::where('type', PackageType::Python)
            ->where(fn ($q) => $q
                ->where('packages.organization_id', $group->organization_id)
                ->orWhere(fn ($q2) => $q2
                    ->where('packages.shared', true)
                    ->whereIn('packages.id', $group->packages()->select('packages.id'))))
            ->get()
            ->contains(fn (Package $p): bool => PythonName::normalize($p->name) === $normalized);
    }
}
