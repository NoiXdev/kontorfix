<?php

namespace App\Http\Controllers\Registry\Oci;

use App\Exceptions\OciException;
use App\Http\Controllers\Controller;
use App\Models\Group;
use App\Models\RegistryToken;
use App\Services\RegistryAccessService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * GET /v2/ — the OCI distribution spec's version check. A Docker client calls this FIRST,
 * always, anonymously if it has no credentials yet, to discover whether the server speaks
 * the API at all and whether it needs to authenticate — and it gives up right there if the
 * answer is anything other than 200 or a 401 it can react to by sending Basic credentials
 * on the retry. That makes this endpoint's anonymous behaviour a genuine correctness
 * constraint, not a preference: whatever ResolvesOciRepository::ociRepository() and
 * BlobController::show() actually allow an anonymous caller to reach further in, a real
 * client can only ever get there if THIS check does not turn it away first.
 *
 * This used to disagree with them: an earlier version threw 401 for every null token
 * unconditionally, while ociRepository() already implemented and documented anonymous
 * reads for a PUBLIC group (mirroring npm/composer/pypi). The two were never exercised
 * together — curl against a manifest endpoint directly worked, but no real docker/skopeo/
 * crane client could ever reach it, because every one of them asks GET /v2/ first and
 * stops on a 401 that never offers a way back in for an anonymous caller. A public Docker
 * registry was therefore unusable by any actual OCI client, silently, since nothing in
 * this codebase drives a request through a real client end-to-end the way bin/e2e does for
 * the other three ecosystems. Made to agree with the already-implemented, already-
 * documented model instead of the reverse: canAccessGroup() decides, exactly as it does
 * for ociRepository() and every other ecosystem's read path — but only once a registry has
 * actually been named (see the host-root rule below).
 *
 * THREE questions, asked in this order, because the endpoint answers to three audiences:
 *
 *  1. **Were credentials sent that resolved to nothing?** Then 401, whatever else is true —
 *     including on a PUBLIC registry's own domain. This is not an access decision, it is a
 *     failed login: `docker login` reports success on whatever this endpoint answers 200 to,
 *     so a wrong password answered with 200 tells the user they are logged in and only fails
 *     much later, on the push, with nothing pointing at the credential. That was the case
 *     for a public group until this check moved ABOVE the group branch — canAccessGroup()
 *     short-circuits true for a null token there, which makes a wrong password
 *     indistinguishable from an anonymous caller. Anonymous callers send nothing at all and
 *     never reach this, which is what keeps a public registry pullable.
 *  2. **Was a registry even named?** In path mode the bare `/v2/` on the instance's own
 *     host carries no `{org}/{registry}` yet (see ResolveOciContext) — this is the HOST ROOT
 *     ping, the exact request a classic Docker engine (the overlay2/graphdriver store — see
 *     tests/E2E/DockerTest.php's docblock for why CI still runs one) sends before it has
 *     picked a repository, and the ONE response it uses to configure auth for the whole
 *     push/pull: 200 means it will never send credentials again, 401+`WWW-Authenticate:
 *     Basic` means it will. There is no group here whose `public` flag could excuse an
 *     anonymous caller, so this answers 401 unless step 1 already resolved a real
 *     credential — the same rule Docker Hub's own host root applies, and the reason a
 *     classic engine can authenticate against a path address at all. The trade-off this
 *     accepts: an anonymous PUBLIC pull through the PATH address on a classic engine no
 *     longer works, because Basic has no anonymous grant to fall back to. Anonymous
 *     consumption of a public registry belongs on that registry's OWN domain (step 3),
 *     where anonymous still answers 200.
 *  3. **May this caller see the registry that was named?** Only reached once a registry WAS
 *     named — a domain-mode host. Path mode's own bare `/v2/` never carries a name (this
 *     controller is registered ONLY at that bare route — see routes/registry.php — every
 *     path-mode address past the host root is a repository-scoped route that never reaches
 *     this class at all), so this branch is domain-mode only here. canAccessGroup() decides,
 *     unchanged: a public group still answers anonymous with 200 here.
 *
 * The resulting table: a credential that resolves to nothing is always 401 with the Basic
 * challenge, regardless of addressing mode. Past that, the host root (no registry named) is
 * 401 for anonymous and 200 for a valid credential; a named registry is 200 for anonymous
 * only if it is public, 200 for a valid credential unless the named registry refuses it, and
 * 401 for anonymous otherwise.
 */
class VersionController extends Controller
{
    public function __construct(private readonly RegistryAccessService $access) {}

    public function __invoke(Request $request): JsonResponse
    {
        /** @var RegistryToken|null $token */
        $token = $request->attributes->get('registryToken');

        $group = $request->attributes->get('registryGroup');

        // AuthenticateRegistry sets `registryToken` to null for "sent nothing" and for
        // "sent something that resolved to no token" alike, so the request itself is what
        // separates the two: `getUser()` covers HTTP Basic (what docker actually sends,
        // and what `docker login` verifies against this very endpoint) and `bearerToken()`
        // the header every other client of this registry uses.
        //
        // ABOVE the group check deliberately, and not inside the no-group branch where it
        // started: on a public registry's domain canAccessGroup(null, $group) is true, so a
        // wrong password would otherwise be answered 200 and `docker login` would report a
        // success the user only discovers was false on the next push.
        if ($token === null && ($request->getUser() !== null || $request->bearerToken() !== null)) {
            throw OciException::unauthorized();
        }

        // No registry was named at all: this is the HOST ROOT ping, path mode's equivalent
        // of Docker Hub's own `/v2/`. A classic Docker engine (the overlay2 store CI runners
        // still have — see this class's docblock) sends this ping BEFORE it has picked a
        // repository, and configures authentication for the entire push/pull from this one
        // response: 200 means it will never send credentials again, 401+Basic means it will.
        // There is no group here whose `public` flag could excuse an anonymous caller — so a
        // credential is the only thing that can turn this 200, exactly like Docker Hub's own
        // host root, which never grants an anonymous host-root ping either.
        if (! $group instanceof Group) {
            if ($token === null) {
                throw OciException::unauthorized();
            }

            return $this->acknowledge();
        }

        // A registry WAS named — domain mode only, since this controller is registered
        // solely at path mode's bare host-root route (see routes/registry.php): canAccessGroup()
        // decides, unchanged — a public group still answers anonymous with 200 here.
        if (! $this->access->canAccessGroup($token, $group)) {
            throw OciException::unauthorized();
        }

        return $this->acknowledge();
    }

    private function acknowledge(): JsonResponse
    {
        return response()->json((object) [], 200, ['Docker-Distribution-Api-Version' => 'registry/2.0']);
    }
}
