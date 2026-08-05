<?php

namespace App\Services\Vcs;

use App\Enums\PackageType;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;
use Throwable;

/**
 * Cheap, read-only look at a git remote used by the "add package" preview: confirm the
 * repository is reachable, list its version tags, and read the package manifest to show
 * the discovered name/description before anything is persisted.
 */
class RepositoryProbe
{
    /**
     * @return array{ok: bool, error?: string, name?: string|null, description?: string|null, default_branch?: string|null, versions: list<string>}
     */
    public function probe(PackageType $type, string $url): array
    {
        // ls-remote confirms reachability + auth without a full clone and lists refs.
        $ls = Process::timeout(30)->run([
            'git', 'ls-remote', '--tags', '--symref', '--end-of-options', $url, 'HEAD',
        ]);

        if (! $ls->successful()) {
            return ['ok' => false, 'error' => $this->readableGitError($ls->errorOutput()), 'versions' => []];
        }

        $versions = $this->parseTags($ls->output());
        $defaultBranch = $this->parseDefaultBranch($ls->output());

        // Read the manifest from the default branch via a blobless shallow clone — small
        // and quick, and enough to extract name/description.
        [$name, $description] = $this->readManifest($type, $url, $defaultBranch);

        return [
            'ok' => true,
            'name' => $name,
            'description' => $description,
            'default_branch' => $defaultBranch,
            'versions' => $versions,
        ];
    }

    /** @return list<string> */
    private function parseTags(string $output): array
    {
        $tags = [];
        foreach (explode("\n", $output) as $line) {
            if (preg_match('#refs/tags/(.+?)(\^\{\})?$#', trim($line), $m)) {
                $tags[$m[1]] = true; // de-dupe the peeled ^{} entries
            }
        }

        // Newest-looking first is nicer for a preview; a natural sort is good enough.
        $list = array_keys($tags);
        natsort($list);

        return array_values(array_reverse($list));
    }

    private function parseDefaultBranch(string $output): ?string
    {
        // `--symref` prints e.g. "ref: refs/heads/main HEAD".
        if (preg_match('#ref:\s+refs/heads/(\S+)\s+HEAD#', $output, $m)) {
            return $m[1];
        }

        return null;
    }

    /**
     * @return array{0: string|null, 1: string|null} [name, description]
     */
    private function readManifest(PackageType $type, string $url, ?string $branch): array
    {
        $manifest = $type === PackageType::Npm ? 'package.json' : 'composer.json';
        $dir = rtrim(sys_get_temp_dir(), '/').'/kfx-probe-'.Str::random(12);

        try {
            $clone = Process::timeout(60)->run(array_filter([
                'git', 'clone', '--depth', '1', '--filter=blob:none', '--no-checkout', '--quiet',
                $branch ? '--branch' : null, $branch ?: null,
                '--end-of-options', $url, $dir,
            ]));

            if (! $clone->successful()) {
                return [null, null];
            }

            $show = Process::path($dir)->timeout(30)->run(['git', 'show', '--end-of-options', 'HEAD:'.$manifest]);
            if (! $show->successful()) {
                return [null, null];
            }

            /** @var array<string,mixed>|null $json */
            $json = json_decode($show->output(), true);
            if (! is_array($json)) {
                return [null, null];
            }

            $name = isset($json['name']) && is_string($json['name']) ? $json['name'] : null;
            $description = isset($json['description']) && is_string($json['description']) ? $json['description'] : null;

            return [$name, $description];
        } catch (Throwable) {
            return [null, null];
        } finally {
            $this->deleteDir($dir);
        }
    }

    private function readableGitError(string $stderr): string
    {
        $stderr = trim($stderr);

        if (Str::contains($stderr, ['Authentication failed', 'could not read Username', 'Permission denied'])) {
            return 'Zugriff verweigert — Repository privat? Für SSH einen Deploy-Key hinterlegen.';
        }
        if (Str::contains($stderr, ['not found', 'does not exist', 'Repository not found'])) {
            return 'Repository nicht gefunden.';
        }
        if (Str::contains($stderr, ['Could not resolve host', 'unable to access', 'timed out'])) {
            return 'Repository nicht erreichbar.';
        }

        return $stderr !== '' ? Str::limit($stderr, 200) : 'Repository konnte nicht gelesen werden.';
    }

    private function deleteDir(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($items as $item) {
            $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
        }
        @rmdir($dir);
    }
}
