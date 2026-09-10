<?php

use App\Enums\UserRole;
use App\Models\NotificationRecipient;
use App\Models\Organization;
use App\Models\User;

function recipientScopeOperatorAdmin(): User
{
    return User::factory()->for(Organization::factory()->create(['is_operator' => true]))->create(['role' => UserRole::Admin]);
}

it('lists only the active scope organization\'s recipients', function () {
    $admin = recipientScopeOperatorAdmin();
    $orgA = Organization::factory()->create(['name' => 'Org A']);
    $orgB = Organization::factory()->create(['name' => 'Org B']);

    NotificationRecipient::create(['organization_id' => $orgA->id, 'email' => 'a@example.test', 'events' => [], 'enabled' => true]);
    NotificationRecipient::create(['organization_id' => $orgB->id, 'email' => 'b@example.test', 'events' => [], 'enabled' => true]);

    $this->actingAs($admin)
        ->withSession(['admin.scope_org_id' => $orgA->id])
        ->get(route('admin.notification-recipients.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('recipients', 1)
            ->where('recipients.0.email', 'a@example.test')
            ->where('recipients.0.organization', 'Org A'));
});

it('shows every organization\'s recipients when unscoped', function () {
    $admin = recipientScopeOperatorAdmin();
    $orgA = Organization::factory()->create();
    $orgB = Organization::factory()->create();

    NotificationRecipient::create(['organization_id' => $orgA->id, 'email' => 'a@example.test', 'events' => [], 'enabled' => true]);
    NotificationRecipient::create(['organization_id' => $orgB->id, 'email' => 'b@example.test', 'events' => [], 'enabled' => true]);

    $this->actingAs($admin)
        ->get(route('admin.notification-recipients.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->has('recipients', 2));
});

it('attributes a new recipient to the active scope organization', function () {
    $admin = recipientScopeOperatorAdmin();
    $otherOrg = Organization::factory()->create();

    $this->actingAs($admin)
        ->withSession(['admin.scope_org_id' => $otherOrg->id])
        ->post(route('admin.notification-recipients.store'), [
            'email' => 'scoped@example.test',
            'events' => ['sync.failed'],
        ])->assertRedirect();

    $recipient = NotificationRecipient::where('email', 'scoped@example.test')->firstOrFail();
    expect($recipient->organization_id)->toBe($otherOrg->id)
        ->and($recipient->organization_id)->not->toBe($admin->organization_id);
});

it('refuses to delete a recipient belonging to an organization outside the active scope', function () {
    $admin = recipientScopeOperatorAdmin();
    $ownOrg = Organization::factory()->create();
    $foreignOrg = Organization::factory()->create();
    $foreignRecipient = NotificationRecipient::create(['organization_id' => $foreignOrg->id, 'email' => 'foreign@example.test', 'events' => [], 'enabled' => true]);

    $this->actingAs($admin)
        ->withSession(['admin.scope_org_id' => $ownOrg->id])
        ->delete(route('admin.notification-recipients.destroy', $foreignRecipient))
        ->assertForbidden();

    expect(NotificationRecipient::find($foreignRecipient->id))->not->toBeNull();
});
