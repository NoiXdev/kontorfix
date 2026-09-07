<?php

namespace App\Http\Controllers\Registry\Oci;

use App\Exceptions\OciException;
use App\Http\Controllers\Controller;
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
 */
class VersionController extends Controller
{
    use ResolvesOciRepository;

    public function __construct(private readonly RegistryAccessService $access) {}

    protected function access(): RegistryAccessService
    {
        return $this->access;
    }

    public function __invoke(Request $request): JsonResponse
    {
        $group = $this->ociGroup($request);

        /** @var RegistryToken|null $token */
        $token = $request->attributes->get('registryToken');

        if (! $this->access()->canAccessGroup($token, $group)) {
            throw OciException::unauthorized();
        }

        return response()->json((object) [], 200, ['Docker-Distribution-Api-Version' => 'registry/2.0']);
    }
}
