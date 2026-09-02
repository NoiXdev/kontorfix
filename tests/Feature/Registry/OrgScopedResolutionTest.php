<?php

use App\Models\Group;
use App\Models\Package;

it('does not serve one organization a package owned by another', function () {
    $mine = Group::factory()->create(['public' => true]);
    $theirs = Group::factory()->create(['public' => true]);

    $foreign = Package::factory()->inOrgOf($theirs)->create([
        'type' => 'composer', 'name' => 'acme/tools',
    ]);
    // Attached to my registry by a pre-invariant row: resolution must still refuse it.
    $mine->packages()->attach($foreign);

    $this->get("/r/{$mine->slug}/p2/acme/tools.json")->assertNotFound();
});

it('serves a package owned by the addressed organization', function () {
    $group = Group::factory()->create(['public' => true]);
    $package = Package::factory()->inOrgOf($group)->create([
        'type' => 'composer', 'name' => 'acme/tools',
    ]);
    $group->packages()->attach($package);

    $this->get("/r/{$group->slug}/p2/acme/tools.json")->assertOk();
});
