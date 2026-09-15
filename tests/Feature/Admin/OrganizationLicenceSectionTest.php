<?php

use App\Enums\PackageType;
use App\Models\Organization;
use App\Models\Package;
use Illuminate\Support\Collection;
use Inertia\Testing\AssertableInertia;
use PHPUnit\Framework\Assert;

it('sends this organization licences to the customer page', function () {
    $operator = Organization::factory()->create(['is_operator' => true]);
    $customer = Organization::factory()->create();
    $package = Package::factory()->for($operator)->create(['shared' => true, 'type' => PackageType::Composer, 'name' => 'acme/lizenz']);
    $customer->licensedPackages()->attach($package->id, ['version_min' => '1.0.0']);

    $this->actingAs(superAdmin())->get(route('admin.organizations.show', $customer))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('licences.0.package_name', 'acme/lizenz')
            ->where('licences.0.version_min', '1.0.0'));
});

/**
 * Regression for the two licence payloads disagreeing on the date shape (see
 * PackageLicencePayloadTest.php's identical test on the OTHER host for the full story):
 * `OrganizationController::show()` used to send the pivot's raw `datetime`-cast value (an
 * ISO instant, e.g. "2026-12-31T23:59:59.000000Z") while `PackageController` sent
 * `->toDateString()`. Every other test in this file sends `available_until => null`, which
 * both shapes agree on, so this is the one that sends a REAL date, reads it back through
 * THIS page's own payload, edits only a version bound (resubmitting the same date, since the
 * frontend always does — see LizenzEditor.vue), and asserts the date survives unchanged and
 * in the same `Y-m-d` shape the package page also sends.
 */
it('round-trips a real available_until date through the customer page payload, surviving an edit to only a version bound', function () {
    $operator = Organization::factory()->create(['is_operator' => true]);
    $customer = Organization::factory()->create();
    $package = Package::factory()->for($operator)->create(['shared' => true, 'type' => PackageType::Composer, 'name' => 'acme/lizenz']);

    $this->actingAs(superAdmin())->post(route('admin.organizations.licences.store', $customer), [
        'package_id' => $package->id,
        'available_until' => '2026-12-31',
        'version_min' => '1.0.0',
        'version_max' => '2.9.9',
    ])->assertSessionHasNoErrors();

    $this->actingAs(superAdmin())->get(route('admin.organizations.show', $customer))
        ->assertInertia(fn (AssertableInertia $page) => $page->where('licences.0.available_until', '2026-12-31'));

    $this->actingAs(superAdmin())->put(route('admin.organizations.licences.update', [$customer, $package]), [
        'available_until' => '2026-12-31',
        'version_max' => '1.9.9',
    ])->assertSessionHasNoErrors();

    $this->actingAs(superAdmin())->get(route('admin.organizations.show', $customer))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('licences.0.available_until', '2026-12-31')
            ->where('licences.0.version_max', '1.9.9'));
});

it('offers only shared packages for licensing', function () {
    $operator = Organization::factory()->create(['is_operator' => true]);
    $customer = Organization::factory()->create();
    Package::factory()->for($operator)->create(['shared' => true, 'type' => PackageType::Composer, 'name' => 'acme/geteilt']);
    Package::factory()->for($operator)->create(['shared' => false, 'type' => PackageType::Composer, 'name' => 'acme/privat']);

    $this->actingAs(superAdmin())->get(route('admin.organizations.show', $customer))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('licensablePackages', fn (Collection $packages) => $packages->pluck('name')->contains('acme/geteilt')
                && ! $packages->pluck('name')->contains('acme/privat')));
});

it('excludes docker packages from the licensable list', function () {
    $operator = Organization::factory()->create(['is_operator' => true]);
    $customer = Organization::factory()->create();
    Package::factory()->for($operator)->create(['shared' => true, 'type' => PackageType::Docker, 'name' => 'acme/image']);

    $this->actingAs(superAdmin())->get(route('admin.organizations.show', $customer))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('licensablePackages', fn (Collection $packages) => ! $packages->pluck('name')->contains('acme/image')));
});

it('shows a licence created from the package page on the customer page, and vice versa', function () {
    $operator = Organization::factory()->create(['is_operator' => true]);
    $customer = Organization::factory()->create();
    $package = Package::factory()->for($operator)->create([
        'shared' => true, 'type' => PackageType::Composer, 'name' => 'acme/lizenz',
    ]);

    // Created through the one write surface both hosts submit to…
    $this->actingAs(superAdmin())->post(route('admin.organizations.licences.store', $customer), [
        'package_id' => $package->id,
        'available_until' => null,
        'version_max' => '2.9.9',
    ])->assertSessionHasNoErrors();

    // …and visible from both, because it is one row and not two.
    $this->actingAs(superAdmin())->get(route('admin.organizations.show', $customer))
        ->assertInertia(fn (AssertableInertia $page) => $page->where('licences.0.version_max', '2.9.9'));

    // `organization_licences` sits at the top level of the payload, a sibling of `package`
    // (see PackageLicencePayloadTest) — not nested inside it.
    $this->actingAs(superAdmin())->get(route('admin.packages.show', $package))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('organization_licences.0.version_max', '2.9.9'));
});

/**
 * The coupling guard fix-round 1 asked for: `OrganizationController::show()` sent
 * `licensable_packages` (snake_case) while `Show.vue`'s `defineProps<{...}>()` declared
 * `licensablePackages` (camelCase). Vue's prop resolution camelizes a HYPHENATED attribute
 * name, never an UNDERSCORED one, so the two silently disagreed: the prop resolved to
 * `undefined` and the raw key landed in `$attrs`, and every `props.licensablePackages...`
 * read in the template threw on page load. Nothing above caught it — `assertInertia()`
 * only ever sees the JSON the controller genuinely sent (so a test asserting the WRONG key
 * name, matching the controller's mistake, passes); `vue-tsc`/eslint only ever see the name
 * the Vue author typed, never whether the two sides actually agree.
 *
 * This test reads BOTH sides for real — the actual rendered page's own JSON payload (parsed
 * out of the `@inertia` directive's `<script data-page="…" type="application/json">` tag,
 * the literal bytes the browser would parse, not a re-derivation of them) and the actual
 * `Show.vue` source's declared prop names — and fails loudly if a future rename touches only
 * one side.
 *
 * What this covers: every top-level payload key `OrganizationController::show()` sends has
 * an identically-spelled prop declared in `Show.vue`. What it does NOT cover: casing
 * agreement one level down (e.g. `licences.0.package_name`'s own field names — those are
 * plain data, not bound as Vue props, so a mismatch there is invisible either way and is a
 * different class of bug), or that the binding actually RENDERS correctly (this project has
 * no Vue component-mounting test infrastructure — no `@vue/test-utils` dependency — so a
 * true "render the page and assert the section works" test was not available cheaply; this
 * is the next-cheapest guard that still reads both real artifacts rather than one hand-typed
 * list asserted against itself).
 */
it('names every top-level prop the customer page controller sends identically to how Show.vue declares it', function () {
    $customer = Organization::factory()->create();

    $response = $this->actingAs(superAdmin())->get(route('admin.organizations.show', $customer))->assertOk();

    // The literal JSON Inertia embedded in the response — see Inertia\Directive::compile():
    // `<script data-page="app" type="application/json">{!! json_encode($page) !!}</script>`,
    // unescaped (it is a script body, not an HTML attribute), so this is decoded directly.
    preg_match('#<script data-page="[^"]*" type="application/json">(.*?)</script>#s', $response->getContent(), $matches);
    expect($matches)->toHaveCount(2, 'Could not find the @inertia script tag in the response — has its markup changed?');

    $page = json_decode($matches[1], associative: true, flags: JSON_THROW_ON_ERROR);
    $payloadKeys = array_keys($page['props']);

    // Keys HandleInertiaRequests shares on every page (errors, auth, flash, …) have no
    // single Vue page component to check against — every page either declares them as a
    // prop or reads them via a shared composable, and this test is about ONE page's own
    // controller, not the global middleware. Excluded by name rather than by guessing which
    // page-specific keys happen not to be props (there are none on this page: every
    // `OrganizationController::show()` key below is bound in Show.vue's defineProps).
    $globallyShared = ['errors', 'name', 'appVersion', 'registryTypeMeta', 'notificationEventMeta', 'quote', 'registrationEnabled', 'auth', 'scope', 'portal', 'flash'];
    $pageOwnKeys = array_values(array_diff($payloadKeys, $globallyShared));

    $vueSource = file_get_contents(resource_path('js/pages/admin/organizations/Show.vue'));

    // `toContain()` is variadic-needles-only (Pest\Mixins\Expectation::toContain()) and does
    // not accept a custom failure message, so this reaches for PHPUnit's own assertion
    // directly — the only way to keep a message that names WHICH key drifted, rather than a
    // generic "string contains" failure someone then has to map back to a key by hand.
    foreach ($pageOwnKeys as $key) {
        Assert::assertStringContainsString(
            "{$key}:",
            $vueSource,
            "OrganizationController::show() sends a top-level `{$key}` prop that Show.vue's defineProps<{...}>() does not declare under that exact name — a rename on one side without the other. Silent at runtime: Vue's prop resolution camelizes a hyphenated attribute name, never an underscored one, so a mismatched pair resolves to `undefined` rather than raising.",
        );
    }

    // And the reverse direction: every key Show.vue actually reads off `props.` must be
    // something the controller genuinely sends — otherwise the SAME class of drift (a
    // stale prop nobody feeds any more) would go unnoticed the other way.
    preg_match_all('/\bprops\.(\w+)/', $vueSource, $propReads);
    $readKeys = array_unique($propReads[1]);

    foreach ($readKeys as $key) {
        Assert::assertContains(
            $key,
            $payloadKeys,
            "Show.vue reads `props.{$key}`, but OrganizationController::show() sends no such top-level prop.",
        );
    }
});
