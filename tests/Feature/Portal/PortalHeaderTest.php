<?php

use App\Models\Organization;
use App\Models\User;

it('offers the organizations the user belongs to', function () {
    $home = Organization::factory()->create(['slug' => 'home', 'name' => 'Home']);
    $other = Organization::factory()->create(['slug' => 'other', 'name' => 'Other']);
    // A stranger, so the fixture can tell the two readings apart at all. Neither the count
    // NOR the whole list distinguishes "the viewer's own memberships" from "every
    // organization" while every organization in the instance is one of the viewer's own —
    // building `switchable` from Organization::query() survived both versions of this
    // assertion until this row existed. Named to sort FIRST, so a mutation that let it in
    // changes the head of the list and not only its length.
    Organization::factory()->create(['slug' => 'stranger', 'name' => 'Aaa Stranger']);
    $user = User::factory()->create(['organization_id' => $home->id]);
    $user->organizations()->attach($other->id, ['role' => 'member']);

    // The WHOLE list, in order, not `has(…, 2)`: a count cannot say WHICH two rows survived,
    // and swapping a membership for the stranger keeps the count at two.
    $this->actingAs($user)->get('/c/home')
        ->assertInertia(fn ($page) => $page->where('portal.switchable', [
            ['name' => 'Home', 'slug' => 'home'],
            ['name' => 'Other', 'slug' => 'other'],
        ]));
});

it('does not call a member of the addressed organization an operator', function () {
    // Split from the case above rather than a second assertion on it: a failure in the
    // switcher assertion would abort the test before this one could speak, and they are
    // different rules that happen to share a fixture.
    $home = Organization::factory()->create(['slug' => 'home', 'name' => 'Home']);
    $user = User::factory()->create(['organization_id' => $home->id]);

    $this->actingAs($user)->get('/c/home')
        ->assertInertia(fn ($page) => $page->where('portal.viewing_as_operator', false));
});

it('tells an operator account whose portal it is looking at', function () {
    // superAdmin() brings the operator organization with it; a second one would put the
    // instance in a state SetupController never produces.
    Organization::factory()->create(['slug' => 'acme', 'name' => 'Acme GmbH']);

    $this->actingAs(superAdmin())->get('/c/acme')
        ->assertInertia(fn ($page) => $page->where('portal.viewing_as_operator', true)
            ->where('portal.organization.name', 'Acme GmbH'));
});

it('hides the token form from an operator who may not mint', function () {
    Organization::factory()->create(['slug' => 'acme']);

    // Task 5 refuses the POST. Showing the form anyway would be the shown-and-refused
    // shape this codebase deliberately replaced with hiding on the admin registry page.
    $this->actingAs(superAdmin())->get('/c/acme')
        ->assertInertia(fn ($page) => $page->where('portal.may_mint_tokens', false));
});

it('offers the token form to a member', function () {
    $org = Organization::factory()->create(['slug' => 'acme']);
    $user = User::factory()->create(['organization_id' => $org->id]);

    $this->actingAs($user)->get('/c/acme')
        ->assertInertia(fn ($page) => $page->where('portal.may_mint_tokens', true));
});

it('does not offer an operator the customer list as a switcher', function () {
    $super = superAdmin();
    Organization::factory()->create(['slug' => 'acme']);
    Organization::factory()->count(3)->create();

    // The switcher is the viewer's own memberships. Feeding it the customer directory
    // would make that directory a by-product of navigation.
    //
    // Named, not counted: this is the disclosure assertion of this task, and "one row
    // survived" is also true of the inverse — dropping the operator's own organization and
    // keeping the addressed customer. The row has to be the operator's own.
    $this->actingAs($super)->get('/c/acme')
        ->assertInertia(fn ($page) => $page->where('portal.switchable', [
            ['name' => $super->organization->name, 'slug' => $super->organization->slug],
        ]));
});

it('shares no portal context on a page outside the portal', function () {
    // The ABSENT case for the whole prop. Without it every assertion in this file is
    // consistent with a `portal` key that is simply always there — and the header is a
    // shared prop, so it is rendered on request paths that have no addressed organization
    // at all. `portalOrganization` is set by ResolvePortalContext, which only runs under
    // /c/{orgSlug}; the dashboard is a page a console account really lands on.
    $org = Organization::factory()->create(['slug' => 'acme']);

    $this->actingAs(adminOf($org))->get('/dashboard')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('Dashboard')->where('portal', null));
});

it('leaves an organization whose portal is off out of the switcher', function () {
    // The switcher is navigation, and ResolvePortalContext answers 404 for an organization
    // whose portal is switched off. An entry for one would be a link the viewer can see and
    // cannot follow — the same dead end the /portal redirect used to produce. There is no
    // disclosure question either way: the viewer is a member of both.
    $home = Organization::factory()->create(['slug' => 'home', 'name' => 'Home']);
    $off = Organization::factory()->create(['slug' => 'off', 'name' => 'Off', 'portal_enabled' => false]);
    $user = User::factory()->create(['organization_id' => $home->id]);
    $user->organizations()->attach($off->id, ['role' => 'member']);

    // Whole, not `has(…, 1)`: a count says one row survived, not which one. The reversal —
    // keeping the disabled organization and dropping the addressed one — has the same count.
    $this->actingAs($user)->get('/c/home')
        ->assertInertia(fn ($page) => $page->where('portal.switchable', [
            ['name' => 'Home', 'slug' => 'home'],
        ]));
});

it('offers the publish ability to an admin of the addressed organization', function () {
    $org = Organization::factory()->create(['slug' => 'acme']);

    $this->actingAs(adminOf($org))->get('/c/acme')
        ->assertInertia(fn ($page) => $page->where('portal.may_publish_tokens', true));
});

it('does not offer the publish ability to a plain member', function () {
    // The ABSENT case: RegistryTokenPolicy::create() refuses a member the Publish ability,
    // so offering it would be shown-and-then-refused one level below the token form itself.
    $org = Organization::factory()->create(['slug' => 'acme']);
    $user = User::factory()->create(['organization_id' => $org->id]);

    $this->actingAs($user)->get('/c/acme')
        ->assertInertia(fn ($page) => $page->where('portal.may_publish_tokens', false));
});

it('does not offer an operator account the publish ability in a customer portal', function () {
    // administers() answers Admin EVERYWHERE for a super-admin, so the publish flag was true
    // here while `may_mint_tokens` was false — two flags disagreeing about one account. That
    // was harmless only while the publish flag was read inside the hidden form, which is a
    // fact about the template rather than a rule the code holds. The conjunction holds it.
    Organization::factory()->create(['slug' => 'acme']);

    // The publish flag FIRST: it is the subject here, and asserting the mint flag ahead of it
    // would abort the test on the mutation this case exists to catch before it could speak.
    $this->actingAs(superAdmin())->get('/c/acme')
        ->assertInertia(fn ($page) => $page->where('portal.may_publish_tokens', false)
            ->where('portal.may_mint_tokens', false));
});

it('offers an operator admin the publish ability inside their own portal', function () {
    // The half of the conjunction that must NOT be lost: this account is a member of the
    // organization it is looking at and administers it, so both flags are true and dropping
    // the membership clause has to leave this case green. Without it, "require membership"
    // is indistinguishable from "refuse operator accounts everywhere".
    $super = superAdmin();

    $this->actingAs($super)->get('/c/'.$super->organization->slug)
        ->assertInertia(fn ($page) => $page->where('portal.may_publish_tokens', true)
            ->where('portal.may_mint_tokens', true));
});

it('does not offer the publish ability to an admin of elsewhere who is only a member here', function () {
    // The population the page used to get wrong. `auth.can.console` is TRUE for this
    // account — it administers its home organization — and the page read that flag, so it
    // offered "Veröffentlichen" under a slug where the policy answers 403 on submit.
    // administers() is asked of the ADDRESSED organization, where this account is a member.
    $home = Organization::factory()->create();
    $addressed = Organization::factory()->create(['slug' => 'acme']);
    $user = adminOf($home);
    $user->organizations()->attach($addressed->id, ['role' => 'member']);

    // Both, because narrowing the wrong one would be just as broken: this account may still
    // mint a READ token here, and hiding the form outright would take that away.
    $this->actingAs($user)->get('/c/acme')
        ->assertInertia(fn ($page) => $page->where('portal.may_publish_tokens', false)
            ->where('portal.may_mint_tokens', true));
});
