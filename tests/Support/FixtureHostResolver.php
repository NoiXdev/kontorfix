<?php

namespace Tests\Support;

use App\Services\Upstream\HostResolver;
use App\Services\Upstream\SystemHostResolver;

/**
 * The resolver the test suite runs on.
 *
 * Its predecessor was a single rule — `ctype_alpha($tld) ? ['93.184.216.34'] : []` —
 * installed for every test. That made *every* internal hostname public:
 * `vault.internal`, `gitlab.corp.internal`, `metadata.google.internal`,
 * `kubernetes.default.svc.cluster.local`, `consul.service.consul`, `redis`, `db` all
 * came back as a public address, which is the exact opposite of what the class's own
 * docblock names as the reason `isSafeResolving()` exists. Every SSRF test in the suite
 * consequently targeted an IP literal, which short-circuits before the resolver, and
 * `GitSsrfGuardTest`'s allowlist case passed identically with the allowlist deleted.
 *
 * This one divides the fixture namespace into three, so a test can express which of the
 * three answers it needs by choosing a hostname:
 *
 *  - **internal** — the documented internal suffixes, and any single-label name
 *    (`redis`, `db`, `vault`), which in a containerised deployment is always a service
 *    on the local network. Resolves to a private address, so the address policy refuses
 *    it and an allowlist entry is the only thing that can let it through.
 *  - **public** — the documented example/test TLDs the fixtures use. Resolves to one
 *    public address, deterministically and offline.
 *  - **everything else** — delegated to the real SystemHostResolver. This is deliberate
 *    and load-bearing: numeric host encodings (`2130706433`, `127.1`, `0x7f000001`) must
 *    be judged by the C resolver, because how *it* decodes them is the property
 *    production depends on, and no stub should be allowed to invent that answer.
 */
class FixtureHostResolver implements HostResolver
{
    public const PUBLIC_IP = '93.184.216.34';

    public const INTERNAL_IP = '10.0.0.5';

    /**
     * Suffixes that stand for a target on the deployment's own network.
     *
     * @var list<string>
     */
    private const INTERNAL_SUFFIXES = [
        '.internal',
        '.local',
        '.localdomain',
        '.consul',
        '.lan',
        '.intranet',
        '.corp',
    ];

    /**
     * Last labels that stand for a public target. `test`, `example` and `invalid` are
     * RFC 2606 / RFC 6761 reserved; the rest are the real public TLDs the fixtures name
     * (github.com, packagist.org, ddev.site, …) and are resolved here rather than over
     * the network so the suite stays offline and deterministic.
     *
     * @var list<string>
     */
    private const PUBLIC_TLDS = ['test', 'example', 'com', 'org', 'net', 'io', 'dev', 'site'];

    /** @var array<string, list<string>> */
    private array $overrides = [];

    public function __construct(private readonly SystemHostResolver $system = new SystemHostResolver) {}

    /**
     * Pins one hostname to an exact answer, for a test that needs something the fixture
     * namespace does not express — a name with several addresses, or none at all.
     *
     * @param  list<string>  $ips
     */
    public function map(string $host, array $ips): static
    {
        $this->overrides[strtolower($host)] = $ips;

        return $this;
    }

    /**
     * @return list<string>
     */
    public function resolve(string $host): array
    {
        $host = strtolower(rtrim($host, '.'));

        if (array_key_exists($host, $this->overrides)) {
            return $this->overrides[$host];
        }

        // RFC 2606 guarantees this one never resolves.
        if (str_ends_with($host, '.invalid')) {
            return [];
        }

        foreach (self::INTERNAL_SUFFIXES as $suffix) {
            if (str_ends_with($host, $suffix)) {
                return [self::INTERNAL_IP];
            }
        }

        // A name with no dot is a container/service name on the local network.
        if (! str_contains($host, '.')) {
            return [self::INTERNAL_IP];
        }

        $labels = explode('.', $host);
        if (in_array((string) end($labels), self::PUBLIC_TLDS, true)) {
            return [self::PUBLIC_IP];
        }

        return $this->system->resolve($host);
    }
}
