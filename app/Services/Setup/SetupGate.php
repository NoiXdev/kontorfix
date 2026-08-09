<?php

namespace App\Services\Setup;

use App\Enums\SetupGateState;
use Illuminate\Http\Request;

/**
 * The single implementation of "may this request use the first-run wizard?".
 *
 * The wizard creates the operator organization and the first super-admin with no
 * authentication at all, so this decision is the only thing between an anonymous
 * request and instance takeover. Two rules follow from that:
 *
 *  1. It fails closed. Anything other than a positively verified token — no token
 *     stored, a store that cannot be read, a wrong token — is Locked wherever a token
 *     is demanded. The old gate collapsed "no token stored" into "no gate", which made
 *     a lost cache key silently equivalent to no protection at all.
 *  2. It cannot raise. SetupToken::current() swallows store failures, so no caller can
 *     turn an exception into an accidental bypass or a 500.
 *
 * Whether a token is demanded at all is configuration, not a side effect of the store's
 * contents: `kontorfix.setup.require_token`, defaulting to "yes" everywhere except
 * local development and tests, where no entrypoint ever printed one.
 */
class SetupGate
{
    /**
     * Where a verified token is remembered, so the multi-step wizard does not need it
     * on every request.
     */
    private const SESSION_KEY = 'setup.token_ok';

    public function __construct(private readonly SetupToken $token) {}

    /**
     * Resolves the gate for this request from the session alone.
     *
     * It deliberately does NOT read a `token` parameter. The token used to be consumed
     * from `?token=`, which put a 40-character instance-takeover secret into every
     * reverse-proxy access log, APM trace and browser history entry along the way.
     * Presenting it is now a separate, explicit POST — see unlock().
     */
    public function state(Request $request): SetupGateState
    {
        $stored = $this->token->current();

        // A stored token always has to be presented, whatever the config says — that is
        // what the existing production flow relies on. The config only decides what
        // happens when there is nothing stored.
        if ($stored === null && ! $this->tokenRequired()) {
            return SetupGateState::Open;
        }

        // A request without a session cannot have presented anything, and asking for one
        // that isn't there would raise — which this gate must never do.
        if (! $request->hasSession()) {
            return SetupGateState::Locked;
        }

        return $request->session()->get(self::SESSION_KEY, false) === true
            ? SetupGateState::Unlocked
            : SetupGateState::Locked;
    }

    /**
     * Consumes a token presented in the request body and remembers a match in the
     * session. Returns whether the gate is open afterwards.
     *
     * The single place a token is ever read from a request, so the transport is one
     * decision in one file rather than a property of whichever parameter bag a caller
     * happens to reach for.
     *
     * `post()` and not `input()`: `input()` reads the query bag too, so
     * `POST /setup/unlock?token=…` kept working and kept writing a 40-character
     * instance-takeover secret into every reverse-proxy access log, APM trace and browser
     * history entry on the way — the exact exposure moving the transport to a body was
     * meant to end, and the exact opposite of what this docblock claimed.
     */
    public function unlock(Request $request): bool
    {
        if (! $request->hasSession()) {
            return false;
        }

        $stored = $this->token->current();
        $presented = $request->post('token');

        if ($stored !== null && is_string($presented) && $this->token->matches($presented)) {
            $request->session()->put(self::SESSION_KEY, true);
        }

        return $this->state($request) !== SetupGateState::Locked;
    }

    /**
     * Refuses a request that has not satisfied the gate. Used by the write endpoints in
     * addition to the route middleware, so that a controller action reached by any
     * other path is still gated.
     */
    public function assertUnlocked(Request $request): void
    {
        if ($this->state($request) === SetupGateState::Locked) {
            abort(403, 'Setup token required.');
        }
    }

    /**
     * A null config value means "decide from the environment": demand the token
     * everywhere a deployment could be exposed, and only stand down for local
     * development and the test suite.
     */
    private function tokenRequired(): bool
    {
        $configured = config('kontorfix.setup.require_token');

        return $configured === null
            ? ! app()->environment(['local', 'testing'])
            : (bool) $configured;
    }
}
