<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Auth\TwoFactorAuthenticator;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class TwoFactorChallengeController extends Controller
{
    public function __construct(private TwoFactorAuthenticator $tfa) {}

    public function create(Request $request): Response|RedirectResponse
    {
        if (! $request->session()->has('login.id')) {
            return redirect()->route('login');
        }

        return Inertia::render('auth/TwoFactorChallenge');
    }

    public function store(Request $request): RedirectResponse
    {
        $userId = $request->session()->get('login.id');
        if ($userId === null) {
            return redirect()->route('login');
        }

        /** @var User $user */
        $user = User::findOrFail($userId);

        $valid = false;
        $field = 'code';
        if ($request->filled('code')) {
            $valid = $this->tfa->verify($user->two_factor_secret, (string) $request->string('code'));
            $field = 'code';
        } elseif ($request->filled('recovery_code')) {
            $field = 'recovery_code';
            $submitted = (string) $request->string('recovery_code');
            if (in_array($submitted, $user->recoveryCodes(), true)) {
                $user->replaceRecoveryCode($submitted);
                $valid = true;
            }
        }

        if (! $valid) {
            throw ValidationException::withMessages([$field => __('Der Code ist ungültig.')]);
        }

        $remember = (bool) $request->session()->pull('login.remember', false);
        $request->session()->forget('login.id');

        Auth::login($user, $remember);
        $request->session()->regenerate();

        return redirect()->intended(route('dashboard', absolute: false));
    }
}
