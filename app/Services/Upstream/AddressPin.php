<?php

namespace App\Services\Upstream;

/**
 * The addresses one outbound URL may be dialled at, carried from the safety check to
 * the connection.
 *
 * UrlSafety answers "is this URL allowed". That verdict alone cannot be acted on: it is
 * reached by resolving the host, and the transport then resolves the same name again to
 * decide where to connect. An attacker who controls the authoritative zone answers the
 * two lookups differently — public for the check, internal for the connection — and the
 * whole address policy is bypassed without ever failing it. Closing that gap means the
 * caller must connect to the addresses that were judged, not to the name that was judged.
 *
 * This object is that hand-off, and it is deliberately the only way UpstreamClient names
 * a connection target: a pin is built per URL and per redirect hop, so a hop that
 * redirects to a second host is judged and pinned on its own terms rather than inheriting
 * the first hop's verdict.
 *
 * Two transports need two mechanisms, because Guzzle picks the handler by request option:
 *
 *  - libcurl (every non-streaming fetch) takes CURLOPT_RESOLVE, a genuine pre-connect
 *    pin — the name is never looked up, so there is no second answer to differ.
 *  - PHP's stream wrapper (`stream => true`, the artifact path) has no equivalent: the
 *    connect address comes from the URL and nothing may override it. There, permits()
 *    checks the peer AFTER the socket is open but BEFORE a single body byte is read,
 *    which is the point that matters — the bytes are what get persisted as an artifact
 *    and streamed back to a tenant.
 */
final class AddressPin
{
    /**
     * @param  list<string>  $addresses
     */
    private function __construct(
        public readonly string $host,
        public readonly int $port,
        public readonly array $addresses,
        private readonly bool $hostIsLiteral,
    ) {}

    /**
     * The pin for a URL, or null when the URL fails the address policy.
     */
    public static function for(string $url): ?self
    {
        $addresses = UrlSafety::safeAddressesFor($url);
        if ($addresses === null || $addresses === []) {
            return null;
        }

        $host = (string) parse_url($url, PHP_URL_HOST);
        $bare = self::stripBrackets($host);

        $port = parse_url($url, PHP_URL_PORT);
        if (! is_int($port)) {
            $port = strtolower((string) parse_url($url, PHP_URL_SCHEME)) === 'https' ? 443 : 80;
        }

        return new self($bare, $port, $addresses, filter_var($bare, FILTER_VALIDATE_IP) !== false);
    }

    /**
     * The curl options that pin this request, in Guzzle's `curl` request-option shape.
     *
     * Empty for an IP literal: curl is already being told an address rather than a name,
     * so there is no lookup to pin and CURLOPT_RESOLVE would be a no-op entry.
     *
     * All judged addresses are pinned, not just the first — every one of them passed the
     * policy, and pinning the whole set preserves curl's own failover for a multi-homed
     * upstream instead of turning one unreachable address into a hard failure.
     *
     * @return array<int, mixed>
     */
    public function curlOptions(): array
    {
        if ($this->hostIsLiteral) {
            return [];
        }

        return [CURLOPT_RESOLVE => [$this->host.':'.$this->port.':'.implode(',', $this->addresses)]];
    }

    /**
     * Whether a socket's peer is one of the judged addresses.
     *
     * Takes what stream_socket_get_name() returns — `93.184.216.34:443`, `[2606::1]:443`
     * — as well as a bare address, because the caller should not have to know which shape
     * its transport produced.
     *
     * Returns false for anything it cannot parse. That direction is deliberate: an
     * unreadable peer means the check could not be performed, and a security control that
     * cannot verify must refuse rather than assume.
     */
    public function permits(?string $peer): bool
    {
        $address = self::addressOf($peer);
        if ($address === null) {
            return false;
        }

        $needle = self::comparable($address);
        if ($needle === null) {
            return false;
        }

        foreach ($this->addresses as $allowed) {
            if (self::comparable($allowed) === $needle) {
                return true;
            }
        }

        return false;
    }

    /**
     * The address out of `host:port`, a bracketed IPv6 `[addr]:port`, or a bare address.
     *
     * Split on the LAST colon only when that leaves a valid address behind, so an
     * unbracketed IPv6 literal (which is all colons) is not truncated into nonsense.
     */
    private static function addressOf(?string $peer): ?string
    {
        if ($peer === null || $peer === '') {
            return null;
        }

        if (str_starts_with($peer, '[')) {
            $close = strpos($peer, ']');

            return $close === false ? null : self::validIp(substr($peer, 1, $close - 1));
        }

        $lastColon = strrpos($peer, ':');
        if ($lastColon !== false) {
            $candidate = self::validIp(substr($peer, 0, $lastColon));
            if ($candidate !== null) {
                return $candidate;
            }
        }

        return self::validIp($peer);
    }

    private static function validIp(string $candidate): ?string
    {
        $candidate = self::stripBrackets($candidate);

        return filter_var($candidate, FILTER_VALIDATE_IP) !== false ? $candidate : null;
    }

    /**
     * A byte form two addresses can be compared on, with IPv4-in-IPv6 folded down to the
     * IPv4 it embeds — otherwise a peer reported as `::ffff:93.184.216.34` would fail to
     * match the `93.184.216.34` that was judged, and a legitimate fetch on a dual-stack
     * host would be refused.
     */
    private static function comparable(string $ip): ?string
    {
        $packed = @inet_pton(self::stripBrackets($ip));
        if (! is_string($packed)) {
            return null;
        }

        if (strlen($packed) === 16 && str_starts_with($packed, str_repeat("\x00", 10)."\xff\xff")) {
            $packed = substr($packed, 12, 4);
        }

        return $packed;
    }

    private static function stripBrackets(string $host): string
    {
        $host = trim($host, '[]');

        $zone = strpos($host, '%');

        return $zone === false ? $host : substr($host, 0, $zone);
    }
}
