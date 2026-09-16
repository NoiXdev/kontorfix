<?php

namespace App\Services\Scanner;

use App\Enums\PackageType;
use App\Enums\TokenAbility;
use App\Exceptions\ScannerException;
use App\Models\Group;
use App\Models\OciManifest;
use App\Models\OciScanReport;
use App\Models\RegistryToken;
use Throwable;

/**
 * One artifact, start to finish: mint a credential, tell the adapter where to pull from,
 * wait for the answer, write it down, revoke the credential.
 *
 * Never throws for a scan that went wrong. Every failure becomes a recorded `failed` state,
 * because the caller is a queued job with nobody waiting on it — an exception here would
 * turn a scanner outage into a growing pile of failed jobs and leave the artifact with no
 * explanation attached to it at all.
 */
final class ScanRunner
{
    public function __construct(
        private readonly VulnerabilityScanner $scanner,
        private readonly ScanReportWriter $writer,
    ) {}

    public function run(OciManifest $manifest): ?OciScanReport
    {
        if (! config('kontorfix.scanner.enabled', false)) {
            return null;
        }

        $manifest->loadMissing('package.organization');

        $group = $this->registryFor($manifest);

        if ($group === null) {
            return $this->writer->recordFailure(
                $manifest,
                $this->scannerName($manifest),
                'Das Repository ist keiner Registry zugeordnet — es gibt keine Adresse, unter der der Scanner es abholen könnte.',
            );
        }

        $token = null;

        try {
            [$token, $plain] = $this->mintToken($group, $manifest);

            $metadata = $this->scanner->metadata();

            // The real scanner name is known from here on — a scan in flight is visible as
            // `pending` rather than indistinguishable from "never scanned", and firstOrCreate
            // means this can never clobber a verdict that already exists.
            $this->writer->recordPending($manifest, $metadata->name);

            $scanId = $this->scanner->requestScan(
                new ScanTarget($this->repositoryPath($group, $manifest), $manifest->digest),
                new RegistryCredential($this->registryUrl(), $plain),
            );

            $report = $this->poll($scanId);

            return $this->writer->recordSuccess($manifest, $metadata, $report);
        } catch (Throwable $e) {
            // ScannerException carries its own German, operator-facing message, and every
            // other Throwable is caught by the very same clause and recorded the same way —
            // there is nothing case-specific left to do once we're just persisting a message.
            return $this->writer->recordFailure($manifest, $this->scannerName($manifest), $e->getMessage());
        } finally {
            // In a finally, not after the happy path: a scan that dies mid-poll must not
            // leave a live registry credential in the hands of an external process.
            $token?->forceFill(['revoked_at' => now()])->saveQuietly();
        }
    }

    /**
     * Blocks until the adapter answers or the budget runs out.
     *
     * Bounded on WALL CLOCK via microtime(), not on now(): the test suite freezes and
     * travels time, and a deadline built from now() would either never expire or expire
     * instantly depending on which way a test moved the clock.
     */
    private function poll(string $scanId): AdapterScanReport
    {
        $budget = (int) config('kontorfix.scanner.timeout', 600);
        $interval = (int) config('kontorfix.scanner.poll_interval', 5);
        $started = microtime(true);

        do {
            $report = $this->scanner->fetchReport($scanId);

            if ($report !== null) {
                return $report;
            }

            if ($interval > 0) {
                sleep($interval);
            }
        } while (microtime(true) - $started < $budget);

        throw ScannerException::rejected("/api/v1/scan/{$scanId}/report", 408);
    }

    /**
     * The registry the scan is performed through.
     *
     * A Docker repository can be assigned to several registries and every one of them is a
     * working pull address, so this is a choice between equals: the oldest assignment, which
     * is deterministic and stable across runs. It decides only which credential is minted
     * and which URL the adapter is handed — the VERDICT belongs to the manifest and applies
     * in every registry that carries it.
     */
    private function registryFor(OciManifest $manifest): ?Group
    {
        return $manifest->package?->groups()
            ->orderBy('groups.created_at')
            ->orderBy('groups.id')
            ->with('organization')
            ->first();
    }

    /**
     * @return array{0: RegistryToken, 1: string}
     */
    private function mintToken(Group $group, OciManifest $manifest): array
    {
        [$token, $plain] = RegistryToken::issue(
            $group->organization,
            'scanner:'.substr($manifest->digest, 7, 12),
            $group,
            TokenAbility::Read,
            // The poll budget plus a margin. Short by construction: this credential exists
            // for one scan of one artifact and is revoked in the finally above, so the
            // expiry is the backstop for a process that never reaches it.
            now()->addSeconds((int) config('kontorfix.scanner.timeout', 600) + 120),
        );

        // Set after issue() rather than through it: `for_scanner` is a fact about why THIS
        // caller minted the token, not a concept RegistryToken should carry into every other
        // issuing path. Nothing has the plaintext yet, so the gap is not observable.
        $token->forceFill(['for_scanner' => true])->save();

        return [$token, $plain];
    }

    /**
     * The repository name in the adapter's terms — always path-addressed.
     *
     * Deliberately NOT RegistryUrl::dockerRepositoryPrefix(), which returns an empty prefix
     * for a registry that has a custom domain. That is correct for a customer pulling over
     * the domain and wrong here: the adapter reaches us on the in-network address, where the
     * domain does not apply and only `/v2/{org}/{registry}/{repo}` resolves.
     */
    private function repositoryPath(Group $group, OciManifest $manifest): string
    {
        return $group->organization->slug.'/'.$group->slug.'/'.$manifest->package->name;
    }

    private function registryUrl(): string
    {
        return rtrim((string) (config('kontorfix.scanner.registry_url') ?: config('app.url')), '/');
    }

    /**
     * A failure often happens BEFORE the scanner could say who it is, and the report row is
     * keyed on that name. A hardcoded label would not do: this manifest may already carry a
     * successful report from THIS adapter under its real name, and landing the failure on a
     * different key would create a second, unrelated row — a Failed one — leaving the
     * original Ok verdict untouched but no longer the one `run()` returns, which is exactly
     * the "failure discards a good verdict" outcome the whole write path exists to prevent.
     *
     * So: ask the scanner first. If it cannot answer, reuse whichever scanner most recently
     * reported on THIS manifest — the identity a retry against the same adapter will land
     * back on once it recovers. Only a manifest that has never produced a single report
     * falls through to `ScanReportWriter::UNIDENTIFIED_SCANNER`, a reserved placeholder
     * identity — never a real scanner's name — that `ScanReportWriter` deletes on this
     * manifest's behalf the moment a real name becomes known, so it cannot outlive its
     * purpose and shadow a later, genuine verdict.
     */
    private function scannerName(OciManifest $manifest): string
    {
        try {
            return $this->scanner->metadata()->name;
        } catch (Throwable) {
            return $manifest->scanReports()->latest('updated_at')->value('scanner_name')
                ?? ScanReportWriter::UNIDENTIFIED_SCANNER;
        }
    }

    /** Whether a manifest is even scannable — Docker only, per the spec's v1 scope. */
    public static function scannable(OciManifest $manifest): bool
    {
        return $manifest->package?->type === PackageType::Docker;
    }
}
