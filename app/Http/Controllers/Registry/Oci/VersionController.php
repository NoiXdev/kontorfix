<?php

namespace App\Http\Controllers\Registry\Oci;

use App\Exceptions\OciException;
use App\Http\Controllers\Controller;
use App\Models\RegistryToken;
use App\Services\RegistryAccessService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * GET /v2/ — the OCI distribution spec's version check. A Docker client calls this first,
 * anonymously, to discover whether the server speaks the API at all and whether it needs
 * to authenticate. Answering 200 to an anonymous caller here would skip that discovery
 * and the client would never send credentials for anything that follows.
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
        $this->ociGroup($request);

        /** @var RegistryToken|null $token */
        $token = $request->attributes->get('registryToken');

        if ($token === null) {
            throw OciException::unauthorized();
        }

        return response()->json((object) [], 200, ['Docker-Distribution-Api-Version' => 'registry/2.0']);
    }
}
