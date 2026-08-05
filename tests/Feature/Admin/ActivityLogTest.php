<?php

use App\Enums\UserRole;
use App\Models\Organization;
use App\Models\User;
use Spatie\Activitylog\Models\Activity;

function activityAdmin(): User
{
    return User::factory()->for(Organization::factory()->create(['is_operator' => true]))->create(['role' => UserRole::Admin]);
}

it('logs creation and updates of core models', function () {
    $org = Organization::factory()->create(['name' => 'Before']);
    $org->update(['name' => 'After']);

    $activities = Activity::where('subject_type', Organization::class)->where('subject_id', $org->id)->get();
    expect($activities->pluck('event'))->toContain('created')->toContain('updated');
});

it('records the acting user as the causer', function () {
    $admin = activityAdmin();

    $this->actingAs($admin)->post('/admin/organizations', ['name' => 'Acme', 'slug' => 'acme']);

    $activity = Activity::where('description', 'created')->where('subject_type', Organization::class)->latest('id')->first();
    expect($activity)->not->toBeNull();
    expect($activity->causer_id)->toBe($admin->id);
});

it('never logs the user password or 2fa columns', function () {
    $user = User::factory()->create();
    $user->update(['name' => 'Renamed', 'password' => bcrypt('brand-new-secret')]);

    $activity = Activity::where('subject_type', User::class)->where('subject_id', $user->id)->where('event', 'updated')->latest('id')->first();
    $json = json_encode($activity->attribute_changes);

    expect($json)->not->toContain('password');
    expect($json)->not->toContain('brand-new-secret');
    expect($json)->not->toContain('two_factor');
});

it('shows the global activity log to an operator admin', function () {
    Organization::factory()->create();

    $this->actingAs(activityAdmin())->get('/admin/activity')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('admin/activity/Index')->has('activities.data'));
});

it('scopes the activity log to a subject', function () {
    $admin = activityAdmin();
    $a = Organization::factory()->create(['name' => 'Alpha']);
    $b = Organization::factory()->create(['name' => 'Beta']);

    $this->actingAs($admin)->get('/admin/activity?subject_type=Organization&subject_id='.$a->id)
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('activities.data',
            fn ($rows) => collect($rows)->every(fn ($r) => $r['subject_id'] === $a->id)));
});

it('denies the activity log to non-admins', function () {
    $maintainer = User::factory()->for(Organization::factory()->create(['is_operator' => true]))->create(['role' => UserRole::Maintainer]);

    $this->actingAs($maintainer)->get('/admin/activity')->assertForbidden();
});
