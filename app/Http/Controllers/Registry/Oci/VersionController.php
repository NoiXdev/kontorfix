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
 * TWO branches, because the endpoint answers two different questions depending on how the
 * instance was addressed (see ResolveOciContext):
 *
 *  - **A registry was named** (domain mode: the host itself identifies one). canAccessGroup()
 *    decides, unchanged — the paragraph above is entirely about this branch.
 *  - **No registry was named** (path mode: the bare `/v2/` on the instance's own host
 *    carries no `{org}/{registry}` yet). There is no group for canAccessGroup() to ask
 *    about, so the credential alone decides: anonymous is 200, a valid token is 200, and
 *    credentials that resolved to no token are 401 with the Basic challenge. Anonymous must
 *    be 200 or a public registry is unpullable by every real client — the bug this
 *    docblock records having shipped once. Bad credentials must be 401 or `docker login`
 *    reports success for a wrong password, which is worse than a refusal because the
 *    failure then surfaces later, on the push, with no hint that the credential was the
 *    cause.
 */
class VersionController extends Controller
{
    public function __construct(private readonly RegistryAccessService $access) {}

    public function __invoke(Request $request): JsonResponse
    {
        /** @var RegistryToken|null $token */
        $token = $request->attributes->get('registryToken');

        $group = $request->attributes->get('registryGroup');

        if ($group instanceof Group) {
            if (! $this->access->canAccessGroup($token, $group)) {
                throw OciException::unauthorized();
            }

            return $this->acknowledge();
        }

        // AuthenticateRegistry sets `registryToken` to null for "sent nothing" and for
        // "sent something that resolved to no token" alike, so the request itself is what
        // separates the two: `getUser()` covers HTTP Basic (what docker actually sends,
        // and what `docker login` verifies against this very endpoint) and `bearerToken()`
        // the header every other client of this registry uses.
        if ($token === null && ($request->getUser() !== null || $request->bearerToken() !== null)) {
            throw OciException::unauthorized();
        }

        return $this->acknowledge();
    }

    private function acknowledge(): JsonResponse
    {
        return response()->json((object) [], 200, ['Docker-Distribution-Api-Version' => 'registry/2.0']);
    }
}
