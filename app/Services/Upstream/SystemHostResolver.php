<?php

namespace App\Services\Upstream;

/**
 * The production resolver: whatever the C library and the configured nameservers say.
 *
 * Both halves matter. `gethostbynamel()` goes through getaddrinfo, so it also decodes
 * the numeric host forms the resolver accepts without ever asking DNS — `2130706433`
 * and `127.1` come back as 127.0.0.1, which is precisely how the address policy catches
 * them. `dns_get_record(…, DNS_AAAA)` adds the IPv6 answers, without which a name that
 * points at a private IPv6 target would be judged on its IPv4 addresses alone.
 */
class SystemHostResolver implements HostResolver
{
    /**
     * @return list<string>
     */
    public function resolve(string $host): array
    {
        $ips = [];

        $v4 = @gethostbynamel($host);
        if (is_array($v4)) {
            $ips = $v4;
        }

        $aaaa = @dns_get_record($host, DNS_AAAA);
        if (is_array($aaaa)) {
            foreach ($aaaa as $record) {
                if (isset($record['ipv6']) && is_string($record['ipv6'])) {
                    $ips[] = $record['ipv6'];
                }
            }
        }

        return $ips;
    }
}
