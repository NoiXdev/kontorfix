<?php

namespace App\Services\Scanner;

use App\Enums\VulnerabilitySeverity;
use App\Exceptions\ScannerException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * An HTTP client for the Harbor Pluggable Scanner Adapter API — the only class in this
 * feature that knows the wire format.
 *
 * We read ONLY the normalised report media type. An adapter is free to also offer its own
 * richer format; parsing it would bind this registry to one scanner, which is the opposite
 * of what a pluggable interface is for, so an unrecognised type is refused by name rather
 * than parsed on the hope that it looks similar enough.
 *
 * Every failure that leaves this class is a ScannerException — never a bare
 * ConnectionException. `PendingRequest::throw(closure)` only fires on an HTTP status; a
 * failure to connect at all propagates straight past it. So `request()` builds the client
 * WITHOUT `->throw()`, and each of the three call sites below wraps its `->get()`/`->post()`
 * in a try/catch that turns a ConnectionException into ScannerException::unreachable().
 * ScanRunner and the health check both rely on that being exhaustive.
 */
final class HarborAdapterScanner implements VulnerabilityScanner
{
    private const METADATA_TYPE = 'application/vnd.scanner.adapter.metadata+json; version=1.1';

    private const SCAN_REQUEST_TYPE = 'application/vnd.scanner.adapter.scan.request+json; version=1.1';

    private const REPORT_TYPE = 'application/vnd.security.vulnerability.report; version=1.1';

    public function metadata(): ScannerMetadata
    {
        try {
            $response = $this->request()
                ->withHeaders(['Accept' => self::METADATA_TYPE])
                ->get($this->endpoint('/api/v1/metadata'));
        } catch (ConnectionException $e) {
            throw ScannerException::unreachable($this->baseUrl(), $e->getMessage());
        }

        if (! $response->successful()) {
            throw ScannerException::rejected('/api/v1/metadata', $response->status());
        }

        $body = $this->decode($response, '/api/v1/metadata');

        /** @var array<string, mixed> $scanner */
        $scanner = (array) ($body['scanner'] ?? []);

        return new ScannerMetadata(
            name: (string) ($scanner['name'] ?? 'Unbekannt'),
            version: isset($scanner['version']) ? (string) $scanner['version'] : null,
        );
    }

    public function requestScan(ScanTarget $target, RegistryCredential $registry): string
    {
        try {
            $response = $this->request()
                ->withHeaders([
                    'Accept' => self::SCAN_REQUEST_TYPE,
                    'Content-Type' => self::SCAN_REQUEST_TYPE,
                ])
                ->post($this->endpoint('/api/v1/scan'), [
                    'registry' => [
                        // Our own in-network address, not APP_URL — the adapter pulls the
                        // layers from us, and from inside the compose network the public URL
                        // either does not resolve or leaves the network and comes back.
                        'url' => $registry->url,
                        'authorization' => 'Bearer '.$registry->bearer,
                    ],
                    'artifact' => array_filter([
                        'repository' => $target->repository,
                        'digest' => $target->digest,
                        'tag' => $target->tag,
                        'mime_type' => 'application/vnd.docker.distribution.manifest.v2+json',
                    ], fn ($value): bool => $value !== null),
                ]);
        } catch (ConnectionException $e) {
            throw ScannerException::unreachable($this->baseUrl(), $e->getMessage());
        }

        // 202 is the specified answer; anything else is a refusal we must not poll on.
        if ($response->status() !== 202) {
            throw ScannerException::rejected('/api/v1/scan', $response->status());
        }

        $body = $this->decode($response, '/api/v1/scan');
        $id = $body['id'] ?? null;

        if (! is_string($id) || $id === '') {
            throw ScannerException::rejected('/api/v1/scan', $response->status());
        }

        return $id;
    }

    public function fetchReport(string $scanId): ?AdapterScanReport
    {
        try {
            $response = $this->request()
                ->withHeaders(['Accept' => self::REPORT_TYPE])
                // The spec answers "not finished yet" with 302 + Location and no body: without
                // `request()`'s own `->withoutRedirecting()`, following it would turn a normal
                // in-progress poll into a request for a resource that does not exist yet.
                ->get($this->endpoint("/api/v1/scan/{$scanId}/report"));
        } catch (ConnectionException $e) {
            throw ScannerException::unreachable($this->baseUrl(), $e->getMessage());
        }

        if ($response->status() === 302 || $response->status() === 404) {
            return null;
        }

        if (! $response->successful()) {
            throw ScannerException::rejected("/api/v1/scan/{$scanId}/report", $response->status());
        }

        $mediaType = strtolower((string) $response->header('Content-Type'));

        if (! str_contains($mediaType, 'application/vnd.security.vulnerability.report')) {
            throw ScannerException::unreadableReport((string) $response->header('Content-Type'));
        }

        $body = $this->decode($response, "/api/v1/scan/{$scanId}/report");

        /** @var array<int, array<string, mixed>> $rows */
        $rows = (array) ($body['vulnerabilities'] ?? []);

        return new AdapterScanReport(array_values(array_map(
            fn (array $row): ScannedVulnerability => new ScannedVulnerability(
                id: (string) ($row['id'] ?? 'unbekannt'),
                severity: VulnerabilitySeverity::fromAdapter(isset($row['severity']) ? (string) $row['severity'] : null),
                packageName: (string) ($row['package'] ?? 'unbekannt'),
                installedVersion: self::nullableString($row['version'] ?? null),
                // Absent AND empty both mean "no fix published". Kept as null so a template
                // can say so, rather than rendering a blank cell that reads as a data bug.
                fixedVersion: self::nullableString($row['fix_version'] ?? null),
            ),
            array_filter($rows, 'is_array'),
        )));
    }

    private function request(): PendingRequest
    {
        $url = $this->baseUrl();

        if ($url === '') {
            throw ScannerException::notConfigured();
        }

        // Checked before a single byte leaves the process, so a misconfigured scanner
        // address is refused rather than dialled and then judged.
        if (! ScannerAddress::isReachable($url)) {
            throw ScannerException::addressRefused($url);
        }

        return Http::timeout((int) config('kontorfix.scanner.request_timeout', 30))
            ->connectTimeout(5)
            // Every outbound hop is judged in this codebase (UrlSafety, AddressPin,
            // UpstreamClient::follow), and `request()`'s check above only judges the
            // pre-redirect, operator-configured URL. Rather than re-running that judgement on
            // a redirect target, the adapter is simply not allowed to redirect at all: it has
            // no legitimate reason to send a scan request or a metadata/report read anywhere
            // but its own configured host. A 3xx therefore surfaces as an ordinary non-success
            // status and falls into the same ScannerException::rejected() path as any other
            // refusal, never as a followed hop.
            ->withoutRedirecting();
    }

    private function baseUrl(): string
    {
        return (string) config('kontorfix.scanner.url', '');
    }

    private function endpoint(string $path): string
    {
        return rtrim($this->baseUrl(), '/').$path;
    }

    /**
     * The response body as an array, or a refusal.
     *
     * Illuminate's Response::json() decodes without JSON_THROW_ON_ERROR and answers with the
     * default it was given, so an undecodable body is indistinguishable from an empty one. For
     * this client that difference is the whole point: an empty `vulnerabilities` list is
     * persisted as a verdict and rendered as "keine bekannten Schwachstellen", so a garbled
     * body silently becomes a clean bill of health. Decoded strictly here instead, because the
     * only safe reading of "we could not understand the scanner" is that we do not know.
     *
     * @return array<string, mixed>
     */
    private function decode(Response $response, string $endpoint): array
    {
        $decoded = json_decode($response->body(), true);

        // `is_array()` alone is not enough: it is true for a JSON LIST as well, so a body of
        // `[]` would decode cleanly and then answer every `$body['…'] ?? default` lookup with
        // the default — which for the report endpoint means an empty findings list, i.e. a
        // clean bill of health produced by a body that is not a report at all. All three
        // endpoints of this API answer with an object, so requiring one refuses nothing real.
        if (! is_array($decoded) || array_is_list($decoded)) {
            throw ScannerException::unreadableBody($endpoint);
        }

        return $decoded;
    }

    private static function nullableString(mixed $value): ?string
    {
        $value = is_scalar($value) ? trim((string) $value) : '';

        return $value === '' ? null : $value;
    }
}
