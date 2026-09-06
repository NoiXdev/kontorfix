<?php

namespace App\Http\Controllers;

use App\Enums\SetupGateState;
use App\Enums\UserRole;
use App\Http\Requests\StoreSetupRequest;
use App\Http\Requests\TestMailRequest;
use App\Models\Group;
use App\Models\MailSetting;
use App\Models\Organization;
use App\Models\StorageSetting;
use App\Models\User;
use App\Services\Mail\MailManager;
use App\Services\Registry\RegistryUrl;
use App\Services\Setup\SetupGate;
use App\Services\Setup\SetupStatus;
use App\Services\Setup\SetupToken;
use App\Services\Slugs\SlugClaimGuard;
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
    /**
     * Presents the first-run token.
     *
     * A POST, because the token is a takeover secret and a query string is copied into
     * proxy logs, APM traces and browser history. Reachable while the gate is locked —
     * it is how one gets unlocked — but throttled, and it never echoes the value back.
     */
    public function unlock(Request $request, SetupGate $gate): RedirectResponse
    {
        if ($gate->unlock($request)) {
            return redirect()->route('setup.show');
        }

        return redirect()->route('setup.show')
            ->withErrors(['token' => 'Das Setup-Token ist ungültig. Es steht in den Startup-Logs des Containers.']);
    }

    public function show(Request $request, SetupGate $gate, RegistryUrl $url): Response
    {
        // One of the two routes in the setup group that EnsureSetupTokenPresented lets
        // through while locked, because this page *is* the token prompt (the other is
        // the unlock POST it submits to). All that is left here is to render the right
        // view for the state the gate is in.
        $locked = $gate->state($request) === SetupGateState::Locked;

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
            // The registry URL form, with both slugs left open. The wizard mints the
            // organization and its first registry in one go and derives the organization's
            // slug from its name (uniqueOrganizationSlug below), so the mask cannot know
            // the first segment — but it must still show the right shape, from here.
            'registryUrlTemplate' => $url->template(),
        ]);
    }

    /**
     * Lets the installer prove the mail backend works before committing to it.
     *
     * Unauthenticated by necessity — there is no account yet — but bounded the same way
     * as the wizard itself: EnsureSetupIncomplete makes it unreachable once any user
     * exists, and the route is rate limited, so it cannot be used as an open relay.
     * Without the token gate it would additionally be an anonymous arbitrary-host,
     * arbitrary-port TCP connect with the transport error echoed back — hence the
     * belt-and-braces assertion on top of the route middleware.
     */
    public function testMail(TestMailRequest $request, MailManager $manager, SetupGate $gate): JsonResponse
    {
        $gate->assertUnlocked($request);

        $setting = $manager->fromInput($request->validated());

        return response()->json($manager->sendTest($setting, (string) $request->validated('recipient')));
    }

    public function store(StoreSetupRequest $request, SetupStatus $status, SetupToken $token, SetupGate $gate, SlugClaimGuard $slugs): RedirectResponse
    {
        // Redundant with EnsureSetupTokenPresented on the route, deliberately: this is
        // the action that hands out the instance, so it does not rely on its routing.
        $gate->assertUnlocked($request);

        $data = $request->validated();

        $user = DB::transaction(function () use ($data, $status, $slugs): User {
            // The middleware already checked this, but two concurrent submissions
            // could both pass it and race to create "the" first admin. Re-asserting
            // inside the transaction closes that window.
            if ($status->isComplete()) {
                throw ValidationException::withMessages([
                    'admin_email' => 'Diese Instanz wurde inzwischen bereits eingerichtet.',
                ]);
            }

            // This is the one write path that mints an organization slug *and* a registry
            // slug in the same request, so it cannot use SlugClaimGuard's one-slug
            // convenience methods — it uses the lock/re-assert primitives directly. The
            // registry slug is user-typed (StoreSetupRequest's UnclaimedSlug rule already
            // gave it a first pass); locking and re-asserting it here is the same
            // race-proofing every other registry-slug write path gets.
            $slugs->lock((string) $data['registry_slug']);
            $slugs->assertNotRegisteredAsOrganization((string) $data['registry_slug'], 'registry_slug');

            $organization = Organization::create([
                'name' => $data['organization_name'],
                'slug' => $this->uniqueOrganizationSlug($slugs, $data['organization_name'], (string) $data['registry_slug']),
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

    /**
     * Derives an unused organization slug and, before returning it, claims it under the
     * same advisory lock every other slug write goes through — the caller is already
     * inside the outer transaction, so the lock survives until that transaction commits or
     * rolls back and covers the Organization::create() that follows.
     *
     * Locking (and then discarding) a rejected candidate is harmless: a PostgreSQL
     * xact-scoped advisory lock cannot be released mid-transaction anyway (only at
     * COMMIT/ROLLBACK), several of them simply stack, and nobody else can be legitimately
     * racing for a slug this method is about to reject as already taken.
     *
     * @param  string  $reserved  a slug this organization may not take — the registry being
     *                            created alongside it. Organization and registry slugs share
     *                            one namespace in the registry URL (see App\Rules\UnclaimedSlug),
     *                            and the wizard is the one place that mints both at once. The
     *                            organization yields, because its slug is derived and the
     *                            registry's is what the installer typed.
     */
    private function uniqueOrganizationSlug(SlugClaimGuard $slugs, string $name, string $reserved = ''): string
    {
        // Slugs are derived rather than asked for — one less field in the wizard.
        // A name of only non-latin characters slugs to '', hence the fallback.
        $base = Str::slug($name) ?: 'operator';
        $slug = $base;
        $suffix = 2;

        while (true) {
            // The reserved slug (the registry being created alongside) is deliberately
            // never even locked here — it is the registry_slug the caller already locked
            // and re-asserted, and doing it again under a candidate that this loop would
            // reject anyway would just be a second lock for no new information.
            if ($slug !== $reserved) {
                $slugs->lock($slug);

                // Both tables, not just `organizations` and the registry being created
                // alongside: the wizard reopens whenever the instance holds no users, which
                // an operator can reach with registries still in place (purged users, a
                // dump restored without them). Checking only `organizations` there lets the
                // derived organization slug land on an *existing* registry's slug — the one
                // collision App\Rules\UnclaimedSlug and 2026_09_03_100000 exist to prevent,
                // minted by the one path that guarded neither, and silently: nothing in the
                // wizard would report it.
                if (! Organization::query()->where('slug', $slug)->exists()
                    && ! Group::query()->where('slug', $slug)->exists()) {
                    return $slug;
                }
            }

            $slug = "{$base}-{$suffix}";
            $suffix++;
        }
    }
}
