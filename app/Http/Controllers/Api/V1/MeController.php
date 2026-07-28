<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\MeResource;
use Illuminate\Http\Request;

class MeController extends Controller
{
    public function show(Request $request): MeResource
    {
        return new MeResource($request->user()->loadMissing('organization'));
    }
}
