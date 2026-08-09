<?php

// Carried over from the concurrent workstream: `90a485f` closed the relative-path-component
// class on the Composer `p2` and npm packument names and left the PyPI `{project}` route
// explicitly open. `[A-Za-z0-9._-]+` admits `.` and `..`.
//
// Measured before the fix, and recorded honestly: the traversal does NOT materialise here.
// PEP 503 normalisation collapses any run of `-`, `_` or `.` to a single `-`, so `..` was
// answered with `Location: https://pypi.org/simple/-/` — a pointless outbound redirect, not
// a chosen upstream path, and the PyPI fallthrough writes no cache row. What is closed is
// the inconsistency: one of three ecosystems accepted a value that cannot name a project and
// answered it with an outbound redirect, and a later change to the normaliser would have
// been the only thing standing between that and the sibling paths' behaviour.

use App\Enums\PackageType;
use App\Models\Group;
use App\Models\Organization;
use App\Models\Upstream;

function pypiNameGroup(): Group
{
    $group = Group::factory()->for(Organization::factory())->create(['slug' => 'kadenz', 'public' => true]);
    Upstream::factory()->for($group)->create([
        'type' => PackageType::Python, 'url' => 'https://pypi.org', 'enabled' => true,
    ]);

    return $group;
}

it('refuses a relative path component in a pypi project name', function () {
    pypiNameGroup();

    foreach (['..', '.', '%2E%2E'] as $project) {
        $this->get("/r/kadenz/simple/{$project}/")->assertNotFound();
    }
});

it('still forwards an ordinary unknown project — the anchor for the case above', function () {
    // Same route, same public group, same enabled upstream, and the fallthrough fires: so
    // the 404 above is the name guard and not a missing upstream, a route miss or the
    // access check answering first.
    pypiNameGroup();

    $this->get('/r/kadenz/simple/real-pkg/')
        ->assertRedirect('https://pypi.org/simple/real-pkg/');
});

it('keeps accepting the dots a real project name contains', function () {
    // `zope.interface` and friends: the refusal is of a segment that IS a relative
    // component, never of a name that merely contains a dot.
    pypiNameGroup();

    $this->get('/r/kadenz/simple/zope.interface/')
        ->assertRedirect('https://pypi.org/simple/zope-interface/');
});
