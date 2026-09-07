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
 * for ociRepository() and every other ecosystem's read path.
 *
 * TWO questions, asked in this order, because the endpoint answers to two audiences:
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
 *  2. **May this caller see the registry that was named?** canAccessGroup() decides,
 *     unchanged — the paragraph above is entirely about this. Only asked when a registry was
 *     named at all: in path mode the bare `/v2/` on the instance's own host carries no
 *     `{org}/{registry}` yet (see ResolveOciContext), so there is no group to ask about and
 *     the credential from step 1 is the whole answer.
 *
 * The resulting table is the same in both addressing modes: anonymous is 200 unless a named
 * private registry refuses it, a valid token is 200 unless the named registry refuses it,
 * and a credential that resolves to nothing is always 401 with the Basic challenge.
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

        // Only when a registry was actually named. Without a group there is nothing to ask
        // about and the credential above was the whole question.
        if ($group instanceof Group && ! $this->access->canAccessGroup($token, $group)) {
            throw OciException::unauthorized();
        }

        return $this->acknowledge();
    }

    private function acknowledge(): JsonResponse
    {
        return response()->json((object) [], 200, ['Docker-Distribution-Api-Version' => 'registry/2.0']);
    }
}
