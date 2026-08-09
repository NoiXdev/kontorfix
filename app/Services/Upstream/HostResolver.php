<?php

namespace App\Services\Upstream;

/**
 * The single DNS lookup the outbound address policy is built on.
 *
 * It exists as a container binding rather than as a static hook on UrlSafety because it
 * is the one decision point for every outbound sink in the application — upstream
 * fetches, git transports, webhook deliveries, the OIDC discovery document. A security
 * control of that reach must not have a public setter that any service provider, package
 * or future "let's make DNS configurable" refactor could install a permissive
 * implementation into. A container binding is swapped in a test and torn down with the
 * application instance, and production code has nothing global to mutate.
 */
interface HostResolver
{
    /**
     * Every A and AAAA address of a hostname, empty when it does not resolve.
     *
     * An empty result is meaningful: UrlSafety refuses a host it cannot resolve rather
     * than assuming it has no target, so an implementation must not invent an answer.
     *
     * @return list<string>
     */
    public function resolve(string $host): array;
}
