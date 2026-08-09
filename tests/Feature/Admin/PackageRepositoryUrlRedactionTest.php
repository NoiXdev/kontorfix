<?php

// A02/A09 (carried partial C3) — `packages.repository_url` is redacted on every read path
// except two: the Inertia prop on the package detail page, and the activity log, which
// `Package::getActivitylogOptions()` fills from the raw column and ActivityPresenter renders.
// (The finding named `activity_log.properties`; in this package version the changed values
// land in `activity_log.attribute_changes`. Both are handled.) The column legitimately carries `https://x-access-token:<PAT>@github.com/…`, the
// reader may be an admin of a different tenant sharing the registry (the shared-pool state),
// and the activity copy PERSISTS AFTER ROTATION — which is the property that matters, since
// rotation is the response to a leak.

use App\Enums\UserRole;
use App\Models\Organization;
use App\Models\Package;
use App\Models\User;
use App\Support\ActivityPresenter;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Spatie\Activitylog\Models\Activity;

const LEAKED_PAT = 'ghp_leakedtokenvalue123';

function redactionAdmin(): User
{
    return User::factory()
        ->for(Organization::factory()->create(['is_operator' => true]))
        ->create(['role' => UserRole::Admin]);
}

it('withholds an inline credential from the package detail props', function () {
    $package = Package::factory()->create([
        'repository_url' => 'https://x-access-token:'.LEAKED_PAT.'@github.com/acme/tools.git',
    ]);

    $response = $this->actingAs(redactionAdmin())->get("/admin/packages/{$package->id}")->assertOk();

    expect($response->getContent())->not->toContain(LEAKED_PAT);
    $response->assertInertia(fn ($page) => $page
        ->where('package.repository_url', 'https://***@github.com/acme/tools.git'));
});

it('still shows a credential-free repository url in full — the anchor for the case above', function () {
    // Same route, same actor, same prop: so the case above is redaction of the userinfo
    // component and not the prop being blanked, dropped or gated away.
    $package = Package::factory()->create(['repository_url' => 'https://github.com/acme/tools.git']);

    $this->actingAs(redactionAdmin())->get("/admin/packages/{$package->id}")->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('package.repository_url', 'https://github.com/acme/tools.git'));
});

it('never writes an inline credential into the activity log', function () {
    Queue::fake();
    $package = Package::factory()->create(['repository_url' => 'https://github.com/acme/tools.git']);

    $package->update(['repository_url' => 'https://x-access-token:'.LEAKED_PAT.'@github.com/acme/tools.git']);
    $package->update(['repository_url' => 'https://github.com/acme/tools.git']);

    $rows = DB::table('activity_log')->get(['attribute_changes', 'properties'])
        ->map(fn ($row) => $row->attribute_changes.' '.$row->properties)->implode(' ');

    // Rotating the credential out of the live column must actually remove it — the old
    // value is the half that outlives rotation.
    expect($rows)->not->toContain(LEAKED_PAT)
        ->and(str_replace('\\/', '/', $rows))->toContain('https://***@github.com/acme/tools.git');
});

it('still records that the repository moved, and to which host', function () {
    Queue::fake();
    $package = Package::factory()->create(['repository_url' => 'https://github.com/acme/tools.git']);
    $package->update(['repository_url' => 'https://gitlab.corp/acme/tools.git']);

    $changes = Activity::query()->latest('id')->first()?->attribute_changes?->toArray() ?? [];

    expect($changes['attributes']['repository_url'] ?? null)->toBe('https://gitlab.corp/acme/tools.git')
        ->and($changes['old']['repository_url'] ?? null)->toBe('https://github.com/acme/tools.git');
});

it('withholds a credential a pre-existing activity row still carries', function () {
    $package = Package::factory()->create(['repository_url' => 'https://github.com/acme/tools.git']);

    // A row as the log held them before the write side redacted, written straight to the
    // table so the model cannot sanitise it on the way in.
    DB::table('activity_log')->insert([
        'log_name' => 'package',
        'description' => 'updated',
        'subject_type' => Package::class,
        'subject_id' => $package->id,
        'event' => 'updated',
        'attribute_changes' => json_encode([
            'attributes' => ['repository_url' => 'https://x-access-token:'.LEAKED_PAT.'@github.com/acme/tools.git'],
            'old' => ['repository_url' => 'https://github.com/acme/tools.git'],
        ]),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $rendered = ActivityPresenter::recentFor($package);

    expect(json_encode($rendered))->not->toContain(LEAKED_PAT)
        ->and($rendered[0]['changes']['attributes']['repository_url'])
        ->toBe('https://***@github.com/acme/tools.git');
});

it('scrubs a credential out of activity rows written before the fix', function () {
    $package = Package::factory()->create(['repository_url' => 'https://github.com/acme/tools.git']);

    DB::table('activity_log')->insert([
        'log_name' => 'package',
        'description' => 'updated',
        'subject_type' => Package::class,
        'subject_id' => $package->id,
        'event' => 'updated',
        'attribute_changes' => json_encode([
            'attributes' => ['repository_url' => 'https://x-access-token:'.LEAKED_PAT.'@github.com/acme/tools.git'],
        ]),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $migration = require database_path('migrations/2026_08_09_130000_redact_credentials_in_activity_log.php');
    $migration->up();

    $stored = DB::table('activity_log')->where('subject_id', $package->id)
        ->orderByDesc('id')->value('attribute_changes');

    expect($stored)->not->toContain(LEAKED_PAT)
        ->and(json_decode((string) $stored, true)['attributes']['repository_url'])
        ->toBe('https://***@github.com/acme/tools.git');
});
