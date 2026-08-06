<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Concerns\ScopesApiToUser;
use App\Http\Controllers\Controller;
use App\Http\Resources\Api\PackageResource;
use App\Models\Group;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

class GroupPackageController extends Controller
{
    use ScopesApiToUser;

    public function index(Group $group): AnonymousResourceCollection
    {
        $this->assertCanReadGroup($group);

        return PackageResource::collection($group->packages()->orderBy('name')->get());
    }

    public function update(Request $request, Group $group): AnonymousResourceCollection
    {
        $this->assertCanWriteGroup($group);

        $validated = $request->validate([
            'package_ids' => ['array'],
            'package_ids.*' => ['uuid', Rule::exists('packages', 'id')],
        ]);

        $group->packages()->sync($validated['package_ids'] ?? []);

        return PackageResource::collection($group->packages()->orderBy('name')->get());
    }
}
