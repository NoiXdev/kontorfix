<?php

use App\Models\Group;
use App\Models\Organization;
use App\Models\Package;
use Illuminate\Support\Facades\Queue;

// adminOf() lives in tests/Pest.php — see the note there.

it('owns a created package where its registries are owned', function () {
    // A real repository_url dispatches SyncPackage, which would otherwise try an actual
    // git clone against an unreachable host — the house style used by every sibling create
    // test (CreateFormRedirectTest, PackageCrudTest, PackageSourceModeCreateTest).
    Queue::fake();

    $admin = adminOf($org = Organization::factory()->create());
    $group = Group::factory()->for($org)->create();

    $this->actingAs($admin)->post(route('admin.packages.store'), [
        'type' => 'composer',
        'name' => 'acme/tools',
        'repository_url' => 'https://git.example.com/acme/tools.git',
        'group_ids' => [$group->id],
    ])->assertRedirect();

    expect(Package::where('name', 'acme/tools')->value('organization_id'))->toBe($org->id);
});

it('refuses registries spanning two organizations', function () {
    $admin = superAdmin();
    $a = Group::factory()->create();
    $b = Group::factory()->create();

    $this->actingAs($admin)->post(route('admin.packages.store'), [
        'type' => 'composer',
        'name' => 'acme/tools',
        'repository_url' => 'https://git.example.com/acme/tools.git',
        'group_ids' => [$a->id, $b->id],
    ])->assertSessionHasErrors('group_ids');
});

it('lets a second organization create the same name', function () {
    Queue::fake();

    $a = Group::factory()->create();
    Package::factory()->inOrgOf($a)->create(['type' => 'composer', 'name' => 'acme/tools']);

    $org = Organization::factory()->create();
    $admin = adminOf($org);
    $group = Group::factory()->for($org)->create();

    $this->actingAs($admin)->post(route('admin.packages.store'), [
        'type' => 'composer',
        'name' => 'acme/tools',
        'repository_url' => 'https://git.example.com/acme/tools.git',
        'group_ids' => [$group->id],
    ])->assertSessionHasNoErrors();
});
