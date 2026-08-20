<?php

use App\Enums\UserRole;
use App\Models\NotificationRecipient;
use App\Models\Organization;
use App\Models\User;

// Distinctly named from PythonPackageCreateTest's file-local operatorAdmin() — Pest test
// files share a global function namespace, so redeclaring that name would fatal.
function notificationOperatorAdmin(): User
{
    return User::factory()->for(Organization::factory()->create(['is_operator' => true]))->create(['role' => UserRole::Admin]);
}

it('lists notification recipients for an operator admin', function () {
    $admin = notificationOperatorAdmin();
    NotificationRecipient::create([
        'organization_id' => $admin->organization_id,
        'email' => 'ops@example.test',
        'events' => ['sync.failed'],
        'enabled' => true,
    ]);

    $this->actingAs($admin)
        ->get(route('admin.notification-recipients.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('admin/notification-recipients/Index')->has('recipients', 1));
});

it('creates a recipient subscribed to the selected events', function () {
    $admin = notificationOperatorAdmin();

    $this->actingAs($admin)
        ->post(route('admin.notification-recipients.store'), [
            'email' => 'ops@example.test',
            'name' => 'Ops Team',
            'events' => ['sync.failed'],
        ])
        ->assertRedirect();

    $recipient = NotificationRecipient::where('email', 'ops@example.test')->firstOrFail();
    expect($recipient->organization_id)->toBe($admin->organization_id)
        ->and($recipient->name)->toBe('Ops Team')
        ->and($recipient->events)->toBe(['sync.failed'])
        ->and($recipient->enabled)->toBeTrue();
});

it('rejects a second recipient with the same address in the same organization', function () {
    $admin = notificationOperatorAdmin();
    NotificationRecipient::create([
        'organization_id' => $admin->organization_id,
        'email' => 'dup@example.test',
        'events' => [],
        'enabled' => true,
    ]);

    $this->actingAs($admin)
        ->post(route('admin.notification-recipients.store'), [
            'email' => 'dup@example.test',
            'events' => ['sync.failed'],
        ])
        ->assertSessionHasErrors('email');
});

it('rejects an event value that is not in the enum', function () {
    $admin = notificationOperatorAdmin();

    $this->actingAs($admin)
        ->post(route('admin.notification-recipients.store'), [
            'email' => 'ops@example.test',
            'events' => ['sync.failed', 'nicht.echt'],
        ])
        ->assertSessionHasErrors('events.1');
});

it('forbids a non-operator admin', function () {
    $admin = User::factory()->for(Organization::factory()->create(['is_operator' => false]))->create(['role' => UserRole::Admin]);

    $this->actingAs($admin)
        ->get(route('admin.notification-recipients.index'))
        ->assertForbidden();

    $this->actingAs($admin)
        ->post(route('admin.notification-recipients.store'), [
            'email' => 'ops@example.test',
            'events' => ['sync.failed'],
        ])
        ->assertForbidden();
});

it('deletes a recipient', function () {
    $admin = notificationOperatorAdmin();
    $recipient = NotificationRecipient::create([
        'organization_id' => $admin->organization_id,
        'email' => 'ops@example.test',
        'events' => ['sync.failed'],
        'enabled' => true,
    ]);

    $this->actingAs($admin)
        ->delete(route('admin.notification-recipients.destroy', $recipient))
        ->assertRedirect();

    expect(NotificationRecipient::find($recipient->id))->toBeNull();
});
