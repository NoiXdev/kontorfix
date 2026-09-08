<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\MeResource;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\Request;

#[Group('Eigenes Konto')]
class MeController extends Controller
{
    /** Eigenes Benutzerprofil samt Organisation abrufen. */
    public function show(Request $request): MeResource
    {
        return new MeResource($request->user()->loadMissing('organization'));
    }
}
