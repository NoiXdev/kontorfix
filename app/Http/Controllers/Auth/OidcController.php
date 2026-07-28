<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\OidcProvider;
use App\Services\Auth\Oidc\OidcService;
use App\Services\Auth\Oidc\OidcUserResolver;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Throwable;

class OidcController extends Controller
{
    public function __construct(private OidcService $service, private OidcUserResolver $resolver) {}

    public function redirect(Request $request, string $slug): RedirectResponse
    {
        $provider = OidcProvider::where('slug', $slug)->where('enabled', true)->firstOrFail();

        $state = Str::random(40);
        $nonce = Str::random(40);
        $verifier = Str::random(64);
        $challenge = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');

        $request->session()->put('oidc', ['state' => $state, 'nonce' => $nonce, 'verifier' => $verifier, 'provider' => $slug]);

        return redirect()->away($this->service->authorizationUrl($provider, $this->callbackUri($slug), $state, $nonce, $challenge));
    }

    public function callback(Request $request, string $slug): RedirectResponse
    {
        $session = $request->session()->pull('oidc', []);

        if (($session['provider'] ?? null) !== $slug
            || ! is_string($request->query('state'))
            || ! hash_equals((string) ($session['state'] ?? ''), (string) $request->query('state'))
            || ! is_string($request->query('code'))) {
            return redirect()->route('login')->withErrors(['email' => __('Anmeldung über den Identity-Provider fehlgeschlagen.')]);
        }

        $provider = OidcProvider::where('slug', $slug)->where('enabled', true)->firstOrFail();

        try {
            $tokens = $this->service->exchangeCode($provider, (string) $request->query('code'), (string) $session['verifier'], $this->callbackUri($slug));
            $claims = $this->service->verifyIdToken($provider, $tokens['id_token'], (string) $session['nonce']);
            $user = $this->resolver->resolve($provider, $claims);
        } catch (Throwable $e) {
            report($e);

            return redirect()->route('login')->withErrors(['email' => __('Anmeldung über den Identity-Provider fehlgeschlagen.')]);
        }

        abort_if($user->isRobot(), 403, 'Robot-Accounts können sich nicht interaktiv anmelden.');

        Auth::login($user);
        $request->session()->regenerate();

        return redirect()->intended(route('dashboard', absolute: false));
    }

    private function callbackUri(string $slug): string
    {
        return url("/auth/oidc/{$slug}/callback");
    }
}
