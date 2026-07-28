<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Models\OidcProvider;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;
use Inertia\Response;

class AuthenticatedSessionController extends Controller
{
    /**
     * Show the login page.
     */
    public function create(Request $request): Response
    {
        return Inertia::render('auth/Login', [
            'canResetPassword' => Route::has('password.request'),
            'status' => $request->session()->get('status'),
            'oidcProviders' => OidcProvider::where('enabled', true)->orderBy('name')->get(['slug', 'name'])
                ->map(fn ($p) => ['slug' => $p->slug, 'name' => $p->name]),
        ]);
    }

    /**
     * Handle an incoming authentication request.
     */
    public function store(LoginRequest $request): RedirectResponse
    {
        $user = $request->validateCredentials();

        // Confirmed 2FA ⇒ don't log in yet, trigger the challenge instead.
        if ($user->hasConfirmedTwoFactor()) {
            $request->session()->put('login.id', $user->id);
            $request->session()->put('login.remember', $request->boolean('remember'));

            return redirect()->route('two-factor.login');
        }

        abort_if($user->isRobot(), 403, 'Robot-Accounts können sich nicht interaktiv anmelden.');

        Auth::login($user, $request->boolean('remember'));
        $request->session()->regenerate();

        return redirect()->intended(route('dashboard', absolute: false));
    }

    /**
     * Destroy an authenticated session.
     */
    public function destroy(Request $request): RedirectResponse
    {
        Auth::guard('web')->logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect('/');
    }
}
