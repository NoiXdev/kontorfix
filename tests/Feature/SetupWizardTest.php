<?php

use App\Enums\UserRole;
use App\Models\Group;
use App\Models\MailSetting;
use App\Models\Organization;
use App\Models\StorageSetting;
use App\Models\User;
use App\Services\Setup\SetupToken;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

/** @return array<string,mixed> */
function setupPayload(array $overrides = []): array
{
    return array_merge([
        'admin_name' => 'Ada Admin',
        'admin_email' => 'ada@example.com',
        'admin_password' => 'correct-horse-battery-staple',
        'admin_password_confirmation' => 'correct-horse-battery-staple',
        'organization_name' => 'Acme GmbH',
        'registry_name' => 'Interne Pakete',
        'registry_slug' => 'interne-pakete',
        'registry_public' => false,
        'mailer' => 'log',
        'storage_driver' => 'local',
    ], $overrides);
}

it('shows the wizard while no user exists', function () {
    $this->get('/setup')->assertOk();
});

it('creates the operator organization, admin and first registry', function () {
    $this->post('/setup', setupPayload())->assertRedirect(route('dashboard'));

    $org = Organization::sole();
    expect($org->name)->toBe('Acme GmbH');
    expect($org->is_operator)->toBeTrue();

    $user = User::sole();
    expect($user->email)->toBe('ada@example.com');
    expect($user->role)->toBe(UserRole::Admin);
    expect($user->organization_id)->toBe($org->id);
    // Nothing may gate the only admin behind a mail that cannot be delivered yet.
    expect($user->email_verified_at)->not->toBeNull();

    $group = Group::sole();
    expect($group->slug)->toBe('interne-pakete');
    expect($group->organization_id)->toBe($org->id);

    $this->assertAuthenticatedAs($user);
});

it('persists the chosen mail and storage settings', function () {
    $this->post('/setup', setupPayload([
        'mailer' => 'postal',
        'postal_domain' => 'https://postal.example.com',
        'postal_key' => 'secret-credential',
        'from_address' => 'noreply@acme.test',
        'from_name' => 'Acme',
        'storage_driver' => 's3',
        'storage_key' => 'AKIA',
        'storage_secret' => 's3cret',
        'storage_region' => 'eu-central-1',
        'storage_bucket' => 'artifacts',
    ]))->assertRedirect(route('dashboard'));

    $mail = MailSetting::sole();
    expect($mail->mailer)->toBe('postal');
    expect($mail->postal_domain)->toBe('https://postal.example.com');
    expect($mail->postal_key)->toBe('secret-credential');
    expect($mail->from_address)->toBe('noreply@acme.test');

    $storage = StorageSetting::sole();
    expect($storage->driver)->toBe('s3');
    expect($storage->bucket)->toBe('artifacts');
    expect($storage->secret)->toBe('s3cret');
});

it('stores the postal key encrypted rather than in plaintext', function () {
    $this->post('/setup', setupPayload([
        'mailer' => 'postal',
        'postal_domain' => 'https://postal.example.com',
        'postal_key' => 'secret-credential',
    ]))->assertRedirect(route('dashboard'));

    $raw = DB::table('mail_settings')->value('postal_key');
    expect($raw)->not->toContain('secret-credential');
});

it('redirects every web route to the wizard while no user exists', function () {
    $this->get('/')->assertRedirect(route('setup.show'));
    $this->get('/login')->assertRedirect(route('setup.show'));
    $this->get('/dashboard')->assertRedirect(route('setup.show'));
});

it('blocks the register POST so the first account cannot bypass the wizard', function () {
    // The dangerous case: registering would create a member with no organization,
    // which then counts as "setup complete" and seals the wizard for good — leaving
    // the instance with zero admins.
    $this->post('/register', [
        'name' => 'Squatter',
        'email' => 'squatter@example.com',
        'password' => 'correct-horse-battery-staple',
        'password_confirmation' => 'correct-horse-battery-staple',
    ])->assertRedirect(route('setup.show'));

    expect(User::query()->count())->toBe(0);
});

it('seals the wizard once a user exists', function () {
    User::factory()->create();

    $this->get('/setup')->assertRedirect(route('home'));

    $this->post('/setup', setupPayload(['admin_email' => 'second@example.com']))
        ->assertRedirect(route('home'));

    // No second admin, no second organization.
    expect(User::query()->count())->toBe(1);
    expect(Organization::query()->where('name', 'Acme GmbH')->exists())->toBeFalse();
});

it('rejects a mismatched password confirmation', function () {
    $this->post('/setup', setupPayload(['admin_password_confirmation' => 'something-else']))
        ->assertSessionHasErrors('admin_password');

    expect(User::query()->count())->toBe(0);
});

it('requires postal credentials when postal is selected', function () {
    $this->post('/setup', setupPayload(['mailer' => 'postal']))
        ->assertSessionHasErrors(['postal_domain', 'postal_key']);

    expect(User::query()->count())->toBe(0);
});

it('requires s3 credentials when s3 is selected', function () {
    $this->post('/setup', setupPayload(['storage_driver' => 's3']))
        ->assertSessionHasErrors(['storage_key', 'storage_secret', 'storage_region', 'storage_bucket']);

    expect(User::query()->count())->toBe(0);
});

it('rejects an invalid registry slug', function () {
    $this->post('/setup', setupPayload(['registry_slug' => 'Not A Slug']))
        ->assertSessionHasErrors('registry_slug');
});

it('derives a unique organization slug', function () {
    Organization::factory()->create(['name' => 'Acme GmbH', 'slug' => 'acme-gmbh']);

    $this->post('/setup', setupPayload())->assertRedirect(route('dashboard'));

    expect(Organization::query()->where('name', 'Acme GmbH')->where('is_operator', true)->sole()->slug)
        ->toBe('acme-gmbh-2');
});

it('lets the installer send a test mail before finishing setup', function () {
    Mail::fake();

    $this->postJson('/setup/mail-test', ['mailer' => 'log', 'recipient' => 'admin@acme.test'])
        ->assertOk()->assertJsonPath('ok', true);
});

it('rejects a wizard test mail without a recipient', function () {
    $this->postJson('/setup/mail-test', ['mailer' => 'log'])
        ->assertStatus(422)->assertJsonValidationErrors('recipient');
});

it('seals the wizard test-mail endpoint once a user exists', function () {
    // Otherwise it would stay a permanently open, unauthenticated mail sender.
    User::factory()->create();

    $this->post('/setup/mail-test', ['mailer' => 'log', 'recipient' => 'admin@acme.test'])
        ->assertRedirect(route('home'));
});

it('accepts an empty smtp encryption as "no encryption"', function () {
    // The dropdown submits '' for that choice; it must not trip the `in:` rule.
    $this->post('/setup', setupPayload([
        'mailer' => 'smtp',
        'smtp_host' => 'smtp.acme.test',
        'smtp_port' => 25,
        'smtp_encryption' => '',
    ]))->assertRedirect(route('dashboard'));

    expect(MailSetting::sole()->smtp_encryption)->toBeNull();
});

it('does not error on stale s3 values when the local driver is chosen', function () {
    // Reproduces the reported bug: pick s3, type a (here deliberately invalid) endpoint,
    // switch back to local. The hidden s3 fields must not fail validation.
    $this->post('/setup', setupPayload([
        'storage_driver' => 'local',
        'storage_endpoint' => 'not-a-valid-url',
        'storage_bucket' => '',
    ]))->assertRedirect(route('dashboard'))->assertSessionHasNoErrors();

    expect(StorageSetting::sole()->driver)->toBe('local');
});

it('locks the wizard when a setup token is configured', function () {
    app(SetupToken::class)->regenerate();

    $this->get('/setup')->assertInertia(fn ($page) => $page->where('locked', true));
});

it('unlocks the wizard with the correct setup token', function () {
    $token = app(SetupToken::class)->regenerate();

    $this->get('/setup?token='.$token)->assertInertia(fn ($page) => $page->where('locked', false));
});

it('refuses to complete setup without the token when one is configured', function () {
    app(SetupToken::class)->regenerate();

    $this->post('/setup', setupPayload())->assertForbidden();
    expect(User::query()->count())->toBe(0);
});

it('completes setup after unlocking with the token', function () {
    $token = app(SetupToken::class)->regenerate();

    // Unlock (stores the verification in the session), then submit.
    $this->get('/setup?token='.$token)->assertInertia(fn ($page) => $page->where('locked', false));
    $this->post('/setup', setupPayload())->assertRedirect(route('dashboard'));

    expect(User::query()->count())->toBe(1);
    // Token is cleared once setup is done.
    expect(app(SetupToken::class)->current())->toBeNull();
});

it('leaves the health check reachable during setup', function () {
    // The container healthcheck hits /up — a redirect there would make a fresh,
    // not-yet-configured deployment look unhealthy and get restart-looped.
    $this->get('/up')->assertOk();
});
