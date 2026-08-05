<?php

namespace App\Http\Controllers;

use App\Enums\UserRole;
use App\Http\Requests\StoreSetupRequest;
use App\Http\Requests\TestMailRequest;
use App\Models\Group;
use App\Models\MailSetting;
use App\Models\Organization;
use App\Models\StorageSetting;
use App\Models\User;
use App\Services\Mail\MailManager;
use App\Services\Setup\SetupStatus;
use App\Services\Setup\SetupToken;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class SetupController extends Controller
{
    private const SESSION_KEY = 'setup.token_ok';

    public function show(Request $request, SetupToken $token): Response
    {
        // If a setup token is configured (production, via `setup:token` at boot), the
        // wizard stays locked until the correct token is presented — see class docblock
        // on SetupToken. A token passed as ?token= is verified once and remembered in
        // the session so the multi-step wizard doesn't need it on every request.
        $locked = false;

        if ($token->current() !== null) {
            if ($token->matches($request->query('token'))) {
                $request->session()->put(self::SESSION_KEY, true);
            }

            $locked = ! $request->session()->get(self::SESSION_KEY, false);
        }

        return Inertia::render('setup/Wizard', [
            'appName' => config('app.name'),
            // When locked, the wizard shows only a token prompt.
            'locked' => $locked,
            // Pre-fills the sender with whatever .env already provides, so the
            // common case is a confirm rather than a retype.
            'defaults' => [
                'from_address' => config('mail.from.address'),
                'from_name' => config('mail.from.name'),
            ],
        ]);
    }

    /**
     * Guards the writing endpoints (store/test) against a caller that never passed the
     * setup token, even though they somehow reached the routes.
     */
    private function assertTokenSatisfied(Request $request, SetupToken $token): void
    {
        if ($token->current() !== null && ! $request->session()->get(self::SESSION_KEY, false)) {
            abort(403, 'Setup token required.');
        }
    }

    /**
     * Lets the installer prove the mail backend works before committing to it.
     *
     * Unauthenticated by necessity — there is no account yet — but bounded the same way
     * as the wizard itself: EnsureSetupIncomplete makes it unreachable once any user
     * exists, and the route is rate limited, so it cannot be used as an open relay.
     */
    public function testMail(TestMailRequest $request, MailManager $manager, SetupToken $token): JsonResponse
    {
        $this->assertTokenSatisfied($request, $token);

        $setting = $manager->fromInput($request->validated());

        return response()->json($manager->sendTest($setting, (string) $request->validated('recipient')));
    }

    public function store(StoreSetupRequest $request, SetupStatus $status, SetupToken $token): RedirectResponse
    {
        $this->assertTokenSatisfied($request, $token);

        $data = $request->validated();

        $user = DB::transaction(function () use ($data, $status): User {
            // The middleware already checked this, but two concurrent submissions
            // could both pass it and race to create "the" first admin. Re-asserting
            // inside the transaction closes that window.
            if ($status->isComplete()) {
                throw ValidationException::withMessages([
                    'admin_email' => 'Diese Instanz wurde inzwischen bereits eingerichtet.',
                ]);
            }

            $organization = Organization::create([
                'name' => $data['organization_name'],
                'slug' => $this->uniqueOrganizationSlug($data['organization_name']),
                // The organization running the instance is the operator — this is what
                // grants access to the /admin surface via the `operator` middleware.
                'is_operator' => true,
            ]);

            $user = User::create([
                'name' => $data['admin_name'],
                'email' => $data['admin_email'],
                'password' => $data['admin_password'],
                'organization_id' => $organization->id,
                'role' => UserRole::Admin,
            ]);

            // The installer proves control of the instance, not of the mailbox — and
            // mail may not even be configured yet. Marking them verified avoids
            // locking the only admin out behind an undeliverable verification mail.
            $user->forceFill(['email_verified_at' => now()])->save();

            Group::create([
                'organization_id' => $organization->id,
                'name' => $data['registry_name'],
                'slug' => $data['registry_slug'],
                'public' => (bool) ($data['registry_public'] ?? false),
            ]);

            $this->persistMailSettings($data);
            $this->persistStorageSettings($data);

            return $user;
        });

        // Setup is done — the token has served its purpose.
        $token->clear();

        Auth::login($user);
        $request->session()->regenerate();

        return to_route('dashboard')->with('success', 'Einrichtung abgeschlossen.');
    }

    /** @param  array<string,mixed>  $data */
    private function persistMailSettings(array $data): void
    {
        MailSetting::current()->update([
            'mailer' => $data['mailer'],
            'from_address' => $data['from_address'] ?? null,
            'from_name' => $data['from_name'] ?? null,
            'smtp_host' => $data['smtp_host'] ?? null,
            'smtp_port' => $data['smtp_port'] ?? null,
            'smtp_username' => $data['smtp_username'] ?? null,
            'smtp_password' => $data['smtp_password'] ?? null,
            'smtp_encryption' => $data['smtp_encryption'] ?? null,
            'postal_domain' => $data['postal_domain'] ?? null,
            'postal_key' => $data['postal_key'] ?? null,
        ]);
    }

    /** @param  array<string,mixed>  $data */
    private function persistStorageSettings(array $data): void
    {
        StorageSetting::current()->update([
            'driver' => $data['storage_driver'],
            'key' => $data['storage_key'] ?? null,
            'secret' => $data['storage_secret'] ?? null,
            'region' => $data['storage_region'] ?? null,
            'bucket' => $data['storage_bucket'] ?? null,
            'endpoint' => $data['storage_endpoint'] ?? null,
            'url' => $data['storage_url'] ?? null,
            'use_path_style' => (bool) ($data['storage_use_path_style'] ?? false),
        ]);
    }

    private function uniqueOrganizationSlug(string $name): string
    {
        // Slugs are derived rather than asked for — one less field in the wizard.
        // A name of only non-latin characters slugs to '', hence the fallback.
        $base = Str::slug($name) ?: 'operator';
        $slug = $base;
        $suffix = 2;

        while (Organization::query()->where('slug', $slug)->exists()) {
            $slug = "{$base}-{$suffix}";
            $suffix++;
        }

        return $slug;
    }
}
