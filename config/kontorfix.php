<?php

return [

    /*
    |--------------------------------------------------------------------------
    | npm publish limits
    |--------------------------------------------------------------------------
    |
    | Upper bound for a single uploaded npm tarball. Protects against
    | memory/disk exhaustion by publish-token holders. In bytes.
    |
    */

    'npm_max_tarball_bytes' => (int) env('KONTORFIX_NPM_MAX_TARBALL_BYTES', 100 * 1024 * 1024),

    /*
    |--------------------------------------------------------------------------
    | PyPI upload limits
    |--------------------------------------------------------------------------
    |
    | Upper bound for a single uploaded Python distribution (sdist or wheel),
    | against memory/disk exhaustion by publish-token holders. In bytes.
    |
    */

    'python_max_dist_bytes' => (int) env('KONTORFIX_PYTHON_MAX_DIST_BYTES', 200 * 1024 * 1024),

    /*
    |--------------------------------------------------------------------------
    | Registry token default lifetime
    |--------------------------------------------------------------------------
    |
    | Default lifetime in days for newly issued registry tokens when the issuer does
    | not pick an expiry. 0 (the default) means open-ended, which is what every
    | existing install has today — raising it never touches tokens that already exist,
    | it only applies to tokens issued from then on.
    |
    */

    'registry_token_ttl_days' => (int) env('KONTORFIX_REGISTRY_TOKEN_TTL_DAYS', 0),

    /*
    |--------------------------------------------------------------------------
    | API key maximum lifetime
    |--------------------------------------------------------------------------
    |
    | Upper bound in days on the expiry a caller may request for an API key.
    | 0 (the default) means no ceiling, which is what every existing install has
    | today. It is a ceiling, not a default: a caller may still ask for less, and
    | keys that already exist are never touched.
    |
    | Independently of this knob, a key minted by presenting another key can never
    | outlive its parent — see StoreApiKeyRequest. Without that rule, revoking a
    | leaked key does not end the compromise, because its holder keeps re-growing
    | the list.
    |
    */

    'api_key_max_ttl_days' => (int) env('KONTORFIX_API_KEY_MAX_TTL_DAYS', 0),

    /*
    |--------------------------------------------------------------------------
    | Successor API key lifetime
    |--------------------------------------------------------------------------
    |
    | Upper bound in days on a key minted by presenting another key — the self-service
    | rotation route, which cannot carry `password.confirm` because /api/v1 is stateless.
    | A successor may never outlive its parent; this is what bounds the case where the
    | parent has no expiry at all, which otherwise let one leaked key renew itself forever
    | and made revoking it pointless.
    |
    | Unlike `api_key_max_ttl_days` this defaults to a finite value, because it can only
    | ever apply to a key being created right now: no existing credential is shortened by
    | it. A robot that keeps rotating keeps working. 0 opts out and restores the
    | self-renewing chain.
    |
    */

    'api_key_successor_max_ttl_days' => (int) env('KONTORFIX_API_KEY_SUCCESSOR_MAX_TTL_DAYS', 90),

    /*
    |--------------------------------------------------------------------------
    | Interactive API browser
    |--------------------------------------------------------------------------
    |
    | Whether `GET /docs/api` (and `GET /docs/api.json`) are registered at all.
    |
    | The browser is rendered from a vendor view that pulls Stoplight Elements from
    | unpkg.com and executes it on this origin without subresource integrity, and
    | its only permitted visitors are admins of the operator organization. Set this
    | to false on an instance that does not need it: the supply-chain and privacy
    | dependency on a third-party CDN then disappears with the route.
    |
    */

    'api_docs_enabled' => filter_var(env('KONTORFIX_API_DOCS_ENABLED', true), FILTER_VALIDATE_BOOL),

    /*
    |--------------------------------------------------------------------------
    | Dist build lock
    |--------------------------------------------------------------------------
    |
    | Seconds a request waits for another request that is already building the same
    | Composer dist archive. The first request for a version clones the repository
    | and zips it; without the wait, N concurrent requests for one cold version each
    | run their own clone.
    |
    | Waiting is bounded and never refuses the download: when the wait runs out the
    | request builds on its own, which is exactly the behaviour that predates the lock.
    | Raise it on an instance with large repositories, lower it to 0 to opt out.
    |
    */

    'dist_build_lock_wait' => (int) env('KONTORFIX_DIST_BUILD_LOCK_WAIT', 15),

    /*
    |--------------------------------------------------------------------------
    | Git mirror lock
    |--------------------------------------------------------------------------
    |
    | Seconds GitRepository::sync() waits for another sync() call already working on the
    | same mirror. Two versions of the same package, both cold, requested in parallel
    | (a normal parallel `composer install`) both reach sync() for one mirror; if it needs
    | repair, the second call's delete can remove the first call's directory mid-clone.
    |
    | Unlike the dist build lock above, a timed-out wait here still falls through and runs
    | unlocked (never hang a queued job or a download request on a stuck holder forever),
    | but the default equals the lock TTL (330s, comfortably above the 300s clone timeout)
    | rather than a short poll: falling through early would reintroduce the very race this
    | lock exists to prevent, so the timeout is meant to fire only for a genuinely stuck
    | holder, not as a routine degrade path. Lower it in tests that need to observe the
    | unlocked fallback without waiting out the real timeout.
    |
    */

    'mirror_lock_wait' => (int) env('KONTORFIX_MIRROR_LOCK_WAIT', 330),

    /*
    |--------------------------------------------------------------------------
    | Git transport and address policy
    |--------------------------------------------------------------------------
    |
    | Applies to every outbound git operation (probe, clone, fetch). The clone URL is
    | operator-supplied by design, so what is restricted is the transport and the
    | address, not the repository.
    |
    | `allowed_schemes` is an allowlist because git's transport surface is open-ended:
    | file:// reads the container filesystem, ext:: hands git an arbitrary shell command,
    | git:// is unauthenticated cleartext. Only widen it deliberately.
    |
    | `allowed_hosts` is the escape hatch for a self-hosted git server that genuinely
    | lives on a private network: hosts listed here (exact, or `*.suffix`) skip the
    | private/reserved-address check. Empty by default — every other host must resolve
    | to a public address, exactly as the upstream/OIDC/webhook fetchers already require.
    |
    */

    'vcs' => [
        'allowed_schemes' => array_values(array_filter(array_map(
            fn (string $scheme): string => strtolower(trim($scheme)),
            explode(',', (string) env('KONTORFIX_VCS_ALLOWED_SCHEMES', 'https,ssh')),
        ))),

        'allowed_hosts' => array_values(array_filter(array_map(
            fn (string $host): string => strtolower(trim($host)),
            explode(',', (string) env('KONTORFIX_VCS_ALLOWED_HOSTS', '')),
        ))),
    ],

    /*
    |--------------------------------------------------------------------------
    | Upstream cache TTL
    |--------------------------------------------------------------------------
    |
    | Validity period of cached upstream metadata in seconds, before a
    | fresh fetch from the upstream is triggered.
    |
    */

    'upstream_cache_ttl' => (int) env('KONTORFIX_UPSTREAM_CACHE_TTL', 300),

    /*
    |--------------------------------------------------------------------------
    | Upstream artifact cache budget
    |--------------------------------------------------------------------------
    |
    | Proxied upstream artifacts are written to the shared `artifacts` disk, and the
    | caller freely chooses which package and version to request — so without a bound,
    | one tenant looping over an upstream's catalogue fills the volume for every tenant
    | on the instance.
    |
    | The budget is instance-wide rather than per tenant, because the disk it protects is
    | instance-wide: an operator sizes it against the volume, not against a customer. N
    | per-tenant quotas would still add up to N times the disk.
    |
    | Reaching the budget stops the cache from GROWING; it never stops a package from
    | being served. A proxied download past the limit is streamed straight through from
    | the upstream, so a full cache costs latency, not availability. Both values are in
    | bytes; 0 disables that particular limit.
    |
    | `upstream_cache_prune_days` drives `upstream-cache:prune`, scheduled daily, which
    | is how the cache gets back under budget without an operator deleting files by hand.
    |
    */

    'upstream_cache_max_bytes' => (int) env('KONTORFIX_UPSTREAM_CACHE_MAX_BYTES', 5 * 1024 * 1024 * 1024),

    'upstream_cache_max_artifact_bytes' => (int) env('KONTORFIX_UPSTREAM_CACHE_MAX_ARTIFACT_BYTES', 100 * 1024 * 1024),

    'upstream_cache_prune_days' => (int) env('KONTORFIX_UPSTREAM_CACHE_PRUNE_DAYS', 30),

    /*
    |--------------------------------------------------------------------------
    | Upstream artifact fetch lock
    |--------------------------------------------------------------------------
    |
    | One upstream fetch per coordinate at a time. Without it, N concurrent requests for
    | the same uncached artifact are N concurrent upstream fetches — and for an artifact
    | over `upstream_cache_max_artifact_bytes` that state is permanent, because such an
    | artifact is served but never cached. The registry routes carry no request budget by
    | design (a cold `composer install` fires hundreds of requests from one address), so
    | duplicated work is what has to be bounded here, not requests.
    |
    | Waiting is bounded and never refuses the download: when the wait runs out the request
    | fetches on its own, which is exactly the behaviour that predates the lock. Set the
    | wait to 0 to opt out. The TTL is the ceiling for a lock orphaned by a client that
    | disconnected mid-relay, so it must comfortably exceed a slow large download.
    |
    */

    'upstream_fetch_lock_wait' => (int) env('KONTORFIX_UPSTREAM_FETCH_LOCK_WAIT', 15),

    'upstream_fetch_lock_ttl' => (int) env('KONTORFIX_UPSTREAM_FETCH_LOCK_TTL', 300),

    /*
    |--------------------------------------------------------------------------
    | Incoming webhook secret
    |--------------------------------------------------------------------------
    |
    | Shared secret for signature verification of incoming webhooks (e.g. from CI
    | systems) that trigger a sync.
    |
    */

    'incoming_webhook_secret' => env('KONTORFIX_INCOMING_WEBHOOK_SECRET'),

    /*
    |--------------------------------------------------------------------------
    | First-run setup
    |--------------------------------------------------------------------------
    |
    | Whether the first-run wizard demands the setup token printed by
    | `setup:token` at boot. The wizard creates the instance owner without
    | authentication, so this must fail closed: null means "decide from the
    | environment", which demands the token everywhere except local development
    | and the test suite. Set it to false only for a deployment you accept
    | anyone can claim.
    |
    */

    'setup' => [
        'require_token' => env('KONTORFIX_SETUP_REQUIRE_TOKEN') === null
            ? null
            : filter_var(env('KONTORFIX_SETUP_REQUIRE_TOKEN'), FILTER_VALIDATE_BOOL),
    ],

];
