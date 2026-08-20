<?php

namespace App\Http\Middleware;

use App\Enums\NotificationEvent;
use App\Enums\PackageType;
use App\Models\SystemSetting;
use App\Models\User;
use App\Services\Scope\OrgScope;
use Illuminate\Foundation\Inspiring;
use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that's loaded on the first page visit.
     *
     * @see https://inertiajs.com/server-side-setup#root-template
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determines the current asset version.
     *
     * @see https://inertiajs.com/asset-versioning
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @see https://inertiajs.com/shared-data
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        [$message, $author] = str(Inspiring::quotes()->random())->explode('-');

        $user = $request->user();

        return array_merge(parent::share($request), [
            ...parent::share($request),
            'name' => config('app.name'),
            'appVersion' => config('app.version'),
            // Single source of truth for registry-type metadata (labels, publish-based).
            'registryTypeMeta' => PackageType::metadata(),
            // Single source of truth for the failure-digest event checkboxes, exactly as
            // registryTypeMeta above.
            'notificationEventMeta' => NotificationEvent::metadata(),
            'quote' => ['message' => trim($message), 'author' => trim($author)],
            // Lets the login page hide the "sign up" link when self-registration is off.
            'registrationEnabled' => fn (): bool => SystemSetting::current()->registration_enabled,
            'auth' => [
                'user' => $user,
                // Drives the navigation: which surfaces to show. `console` = any org
                // admin/maintainer (scoped registry surface); `super` = the global
                // super-admin (instance-wide administration).
                'can' => [
                    'console' => $user instanceof User && $user->canAdministerConsole(),
                    'super' => $user instanceof User && $user->isSuperAdmin(),
                ],
            ],
            // The sidebar organization scope switch. Null when not applicable (logged out
            // or a single-org admin with nothing to switch between).
            'scope' => fn () => $user instanceof User ? app(OrgScope::class)->share() : null,
            'flash' => [
                'success' => fn () => $request->session()->get('success'),
                'plainTextToken' => fn () => $request->session()->get('plainTextToken'),
                'plainApiKey' => fn () => $request->session()->get('plainApiKey'),
                'incomingWebhookSecret' => fn () => $request->session()->get('incomingWebhookSecret'),
                'incomingWebhookUrl' => fn () => $request->session()->get('incomingWebhookUrl'),
            ],
        ]);
    }
}
