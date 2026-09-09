<?php

namespace App\Services\Mirror;

use App\Enums\PackageType;
use App\Exceptions\MirrorSyncFailed;
use App\Exceptions\UpstreamException;
use App\Models\MirrorSource;
use LogicException;

/**
 * Cheap, read-only look at a mirror source used by the "add package" preview: confirm the
 * named package exists there, and show the discovered name/description/versions before
 * anything is persisted — the mirror counterpart of App\Services\Vcs\RepositoryProbe, which
 * does the same job for a git-sourced package.
 *
 * Reuses each importer's fetchMetadata() rather than re-implementing per-type HTTP fetch and
 * parsing here: the protocol (Composer-v2, npm packument, PEP 691) lives exactly once, in
 * App\Services\Mirror\{Composer,Npm,Python}MirrorImport, shared with the real import path.
 * This never touches an artifact — no dist/tarball/wheel is downloaded, only metadata.
 */
class MirrorProbe
{
    public function __construct(
        private readonly ComposerMirrorImport $composer,
        private readonly NpmMirrorImport $npm,
        private readonly PythonMirrorImport $python,
    ) {}

    /**
     * @return array{ok: bool, error?: string, name?: string, description?: string|null, versions: list<string>, warning?: string}
     */
    public function probe(MirrorSource $source, string $mirrorName): array
    {
        try {
            $metadata = match ($source->type) {
                PackageType::Composer => $this->composer->fetchMetadata($source, $mirrorName),
                PackageType::Npm => $this->npm->fetchMetadata($source, $mirrorName),
                PackageType::Python => $this->python->fetchMetadata($source, $mirrorName),
                // A Docker repository is never mirror-sourced (PackageSourceMode::allowedFor()
                // excludes Mirror for it, and MirrorSourceController never offers Docker as a
                // source type) — this arm exists only to keep the match exhaustive and fail
                // loudly if that invariant is ever broken upstream.
                PackageType::Docker => throw new LogicException('Docker mirror sources do not exist; the caller must guard this before calling probe().'),
            };
        } catch (MirrorSyncFailed $e) {
            // Already a German, operator-facing message — see MirrorSyncFailed's docblock.
            return ['ok' => false, 'error' => $e->getMessage(), 'versions' => []];
        } catch (UpstreamException $e) {
            // Only reachable if a caller ever bypasses the importers' own try/catch (they
            // all translate this into MirrorSyncFailed already) — kept as a second line of
            // defence so a raw, English UpstreamException can never reach the response.
            $suffix = $e->status() !== null ? " (HTTP {$e->status()})" : '';

            return ['ok' => false, 'error' => "Quelle nicht erreichbar{$suffix}.", 'versions' => []];
        }

        $result = [
            'ok' => true,
            'name' => $metadata['name'],
            'description' => $metadata['description'],
            'versions' => $metadata['versions'],
        ];

        // The client already withholds the token on a plain-http source (see
        // UpstreamClient::isEncrypted()) rather than sending a bearer credential in the
        // clear — correct, but silent. A source configured with both is reachable (public
        // metadata needs no auth) and reports ok without ever hinting that the token itself
        // is being dropped, until whichever package actually needs it fails to sync. Made
        // visible here rather than only in that eventual sync_error, at the one moment an
        // operator is looking at this specific source/package pairing.
        if ($this->tokenWillBeWithheld($source)) {
            $result['warning'] = 'Quelle unverschlüsselt — Token wird nicht gesendet.';
        }

        return $result;
    }

    private function tokenWillBeWithheld(MirrorSource $source): bool
    {
        $scheme = strtolower((string) parse_url($source->url, PHP_URL_SCHEME));

        return $scheme !== 'https' && $source->auth_token !== null;
    }
}
