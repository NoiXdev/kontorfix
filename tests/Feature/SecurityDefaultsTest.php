<?php

// Three defaults the audit left as backlog items. None of them was a live vulnerability —
// every shipped .env template already sets the safe value — but a default is what an
// instance gets when nobody reads the template, and "documented" is not the same as
// "enforced".

use Illuminate\Support\Facades\Route;

it('serves nothing from the local disk', function () {
    // Laravel's `serve => true` registers GET and PUT on /storage/{path}. Both are
    // signature-gated and the application mints no signed URL for this disk, so they are
    // dead — but a dead PUT is still a write surface whose only guard is APP_KEY.
    $names = collect(Route::getRoutes()->getRoutes())
        ->map(fn ($route): ?string => $route->getName())
        ->filter()
        ->values();

    expect($names)->not->toContain('storage.local');
});

/**
 * What `config/session.php` resolves to when the variable is NOT set — which is the
 * property that matters here. Asserting the effective config would only measure whatever
 * the local .env happens to say, and a developer setting SESSION_ENCRYPT=false for their
 * own convenience must not be able to turn this guard green or red.
 *
 * @return array<string, mixed>
 */
function sessionConfigWithout(string $variable): array
{
    $previous = $_ENV[$variable] ?? null;
    putenv($variable);
    unset($_ENV[$variable], $_SERVER[$variable]);

    try {
        return require base_path('config/session.php');
    } finally {
        if ($previous !== null) {
            putenv("{$variable}={$previous}");
            $_ENV[$variable] = $previous;
            $_SERVER[$variable] = $previous;
        }
    }
}

it('encrypts the session payload unless an operator opts out', function () {
    // The sessions table carries one-time secrets in flash data. Whoever can read that
    // table already holds every live session id, so this is defence in depth rather than a
    // fix — but enabling it LATER logs everybody out once, which is a worse moment to
    // discover it than the first deploy.
    expect(sessionConfigWithout('SESSION_ENCRYPT')['encrypt'])->toBeTrue();
});

it('marks the session cookie secure outside local development', function () {
    // A flat `true` would break a plain-http `php artisan serve` (DDEV terminates TLS, a
    // bare PHP server does not). A flat `false` — today's effective value when the variable
    // is unset — ships the session cookie over cleartext on any deployment whose operator
    // never read the template. So the default follows APP_ENV.
    expect(sessionConfigWithout('SESSION_SECURE_COOKIE')['secure'])
        ->toBe(config('app.env') !== 'local');
});
