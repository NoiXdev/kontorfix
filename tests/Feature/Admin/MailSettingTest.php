<?php

use App\Enums\UserRole;
use App\Models\MailSetting;
use App\Models\Organization;
use App\Models\User;
use App\Services\Mail\MailManager;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

function mailAdmin(): User
{
    return User::factory()
        ->for(Organization::factory()->create(['is_operator' => true]))
        ->create(['role' => UserRole::Admin]);
}

it('lets an admin switch the mailer to smtp', function () {
    $this->actingAs(mailAdmin())->put('/admin/mail', [
        'mailer' => 'smtp',
        'from_address' => 'noreply@acme.test',
        'from_name' => 'Acme',
        'smtp_host' => 'smtp.acme.test',
        'smtp_port' => 587,
        'smtp_username' => 'apikey',
        'smtp_password' => 'sup3rs3cret',
        'smtp_encryption' => 'tls',
    ])->assertRedirect();

    $s = MailSetting::current();
    expect($s->mailer)->toBe('smtp');
    expect($s->smtp_host)->toBe('smtp.acme.test');
    expect($s->smtp_password)->toBe('sup3rs3cret');
});

it('keeps the stored secret when the field is submitted empty', function () {
    $admin = mailAdmin();

    $this->actingAs($admin)->put('/admin/mail', [
        'mailer' => 'smtp', 'smtp_host' => 'smtp.acme.test', 'smtp_port' => 587,
        'smtp_password' => 'first-secret',
    ])->assertRedirect();

    // Re-saving without touching the password field must not wipe it.
    $this->actingAs($admin)->put('/admin/mail', [
        'mailer' => 'smtp', 'smtp_host' => 'smtp2.acme.test', 'smtp_port' => 2525,
        'smtp_password' => '',
    ])->assertRedirect();

    $s = MailSetting::current();
    expect($s->smtp_host)->toBe('smtp2.acme.test');
    expect($s->smtp_password)->toBe('first-secret');
});

it('never exposes the stored secrets to the frontend', function () {
    mailAdmin();
    MailSetting::current()->update(['mailer' => 'postal', 'postal_domain' => 'https://postal.test', 'postal_key' => 'topsecret']);

    $this->actingAs(mailAdmin())->get('/admin/mail')
        ->assertOk()
        ->assertInertia(function ($page) {
            $settings = $page->toArray()['props']['settings'];

            expect($settings)->not->toHaveKey('postal_key');
            expect($settings)->not->toHaveKey('smtp_password');
            expect($settings['has_postal_key'])->toBeTrue();
        });
});

it('stores the smtp password encrypted at rest', function () {
    $this->actingAs(mailAdmin())->put('/admin/mail', [
        'mailer' => 'smtp', 'smtp_host' => 'smtp.acme.test', 'smtp_port' => 587,
        'smtp_password' => 'plaintext-would-be-bad',
    ])->assertRedirect();

    expect(DB::table('mail_settings')->value('smtp_password'))->not->toContain('plaintext-would-be-bad');
});

it('requires postal credentials before postal can be selected', function () {
    $this->actingAs(mailAdmin())->put('/admin/mail', ['mailer' => 'postal'])
        ->assertSessionHasErrors(['postal_domain', 'postal_key']);
});

it('sends a probe mail through the submitted config', function () {
    Mail::fake();

    $this->actingAs(mailAdmin())->postJson('/admin/mail/test', [
        'mailer' => 'log',
        'recipient' => 'admin@acme.test',
    ])->assertOk()->assertJsonPath('ok', true);
});

it('rejects a probe without a recipient', function () {
    $this->actingAs(mailAdmin())->postJson('/admin/mail/test', ['mailer' => 'log'])
        ->assertStatus(422)->assertJsonValidationErrors('recipient');
});

it('accepts an empty smtp encryption as "no encryption"', function () {
    // The dropdown submits '' for that choice; it must not trip the `in:` rule.
    $this->actingAs(mailAdmin())->put('/admin/mail', [
        'mailer' => 'smtp', 'smtp_host' => 'smtp.acme.test', 'smtp_port' => 25,
        'smtp_encryption' => '',
    ])->assertRedirect()->assertSessionHasNoErrors();

    expect(MailSetting::current()->smtp_encryption)->toBeNull();
});

it('probes with the stored secret when the password field is left blank', function () {
    Mail::fake();
    $admin = mailAdmin();

    $this->actingAs($admin)->put('/admin/mail', [
        'mailer' => 'smtp', 'smtp_host' => 'smtp.acme.test', 'smtp_port' => 587,
        'smtp_password' => 'stored-secret',
    ])->assertRedirect();

    $setting = app(MailManager::class)->fromInput([
        'mailer' => 'smtp', 'smtp_host' => 'smtp.acme.test', 'smtp_port' => 587,
        'smtp_password' => '', 'postal_key' => '',
    ]);

    expect($setting->smtp_password)->toBe('stored-secret');
});

it('applies the persisted mailer to the running config', function () {
    MailSetting::current()->update([
        'mailer' => 'smtp',
        'smtp_host' => 'smtp.acme.test',
        'smtp_port' => 2525,
        'from_address' => 'noreply@acme.test',
    ]);

    app(MailManager::class)->apply();

    expect(config('mail.default'))->toBe('smtp');
    expect(config('mail.mailers.smtp.host'))->toBe('smtp.acme.test');
    expect(config('mail.from.address'))->toBe('noreply@acme.test');
});

it('keeps the configured default mailer when the setting carries no mailer', function () {
    // A setting without a usable mailer is reachable in practice — a row left behind by
    // an older schema, or one read before a pending migration has run. Writing that blank
    // into `mail.default` hands Laravel's own MailManager a null driver name, which it
    // rejects with a TypeError naming neither this setting nor this app, so every mail in
    // the instance dies on an error that points nowhere near the cause.
    config(['mail.default' => 'array']);

    $config = app(MailManager::class)->configFor(new MailSetting);

    expect($config)->not->toHaveKey('mail.default');

    config($config);

    expect(config('mail.default'))->toBe('array');
});

it('still resolves a mailer after applying a blank persisted mailer', function () {
    config(['mail.default' => 'array']);
    // forceFill bypasses the validation that keeps the column populated — the point is
    // that whatever ends up stored, resolving a mailer must not take mail down.
    MailSetting::current()->forceFill(['mailer' => ''])->save();

    app(MailManager::class)->apply();

    expect(config('mail.default'))->toBe('array');
    expect(Mail::mailer())->not->toBeNull();
});

it('maps the postal setting onto the package config keys', function () {
    MailSetting::current()->update([
        'mailer' => 'postal',
        'postal_domain' => 'https://postal.acme.test',
        'postal_key' => 'credential',
    ]);

    app(MailManager::class)->apply();

    expect(config('mail.default'))->toBe('postal');
    expect(config('postal.domain'))->toBe('https://postal.acme.test');
    expect(config('postal.key'))->toBe('credential');
});

it('registers the postal transport as a usable mailer', function () {
    // Guards the config/mail.php wiring: without the 'postal' mailer entry, selecting
    // postal would only blow up at send time.
    expect(config('mail.mailers.postal.transport'))->toBe('postal');
});

it('denies mail settings to non-admins', function () {
    $maintainer = User::factory()
        ->for(Organization::factory()->create(['is_operator' => true]))
        ->create(['role' => UserRole::Maintainer]);

    $this->actingAs($maintainer)->get('/admin/mail')->assertForbidden();
    $this->actingAs($maintainer)->put('/admin/mail', ['mailer' => 'log'])->assertForbidden();
});

it('denies mail settings to a non-operator admin', function () {
    $outsider = User::factory()
        ->for(Organization::factory()->create(['is_operator' => false]))
        ->create(['role' => UserRole::Admin]);

    $this->actingAs($outsider)->get('/admin/mail')->assertForbidden();
});
