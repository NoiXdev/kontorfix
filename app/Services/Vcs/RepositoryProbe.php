<?php

namespace App\Services\Vcs;

use App\Enums\GitProvider;
use App\Enums\ManifestReadStatus;
use App\Enums\PackageType;
use Illuminate\Support\Facades\Log;
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
    /** The one answer every "we could not get there" outcome collapses to. */
    private const UNREACHABLE = 'Repository nicht erreichbar.';

    /**
     * @return array{ok: bool, error?: string, name?: string|null, description?: string|null, default_branch?: string|null, versions: list<string>, manifest?: string, manifest_file?: string}
     */
    public function probe(PackageType $type, string $url, ?string $token = null, ?GitProvider $provider = null, ?string $username = null): array
    {
        // Address/transport policy first: nothing may reach git before this. The probe
        // reports auth-failed / not-found / unreachable distinguishably and returns real
        // repository metadata, so an unguarded probe is an internal-network oracle.
        $rejection = GitUrlSafety::reject($url);
        if ($rejection !== null) {
            return ['ok' => false, 'error' => $rejection, 'versions' => []];
        }

        $env = GitAuth::env($url, $token, $provider, $username);

        // ls-remote confirms reachability + auth without a full clone and lists refs.
        // No positional ref pattern: a trailing "HEAD" would restrict the output to HEAD
        // and suppress every tag (so `versions` was always empty) and the symref line
        // (so the default branch was never detected). `--symref` still prints the HEAD
        // symref among the full ref list.
        //
        // The timeout raises rather than returning a failed result, and nothing caught it:
        // `https://host:81/x.git` (a public host on a filtered port) held a worker for the
        // full 30 s and then produced a 500 with a stack trace, on an unauthenticated-log,
        // caller-chosen target. A refused probe is an ordinary answer, not an exception, so
        // it is reported the way every other unreachable target is reported.
        try {
            $ls = Process::env($env)->timeout(30)->run([
                'git', 'ls-remote', '--symref', '--end-of-options', $url,
            ]);
        } catch (Throwable $e) {
            Log::warning('Repository probe could not run git.', [
                'reason' => class_basename($e),
                'host' => (string) parse_url($url, PHP_URL_HOST),
            ]);

            return ['ok' => false, 'error' => self::UNREACHABLE, 'versions' => []];
        }

        if (! $ls->successful()) {
            return ['ok' => false, 'error' => $this->readableGitError(GitAuth::scrub($ls->errorOutput())), 'versions' => []];
        }

        $versions = $this->parseTags($ls->output());
        $defaultBranch = $this->parseDefaultBranch($ls->output());

        // Read the manifest from the default branch via a blobless shallow clone — small
        // and quick, and enough to extract name/description.
        $manifest = $this->readManifest($type, $url, $defaultBranch, $env);

        return [
            'ok' => true,
            'name' => $manifest['name'],
            'description' => $manifest['description'],
            'default_branch' => $defaultBranch,
            'versions' => $versions,
            // Reachability and manifest-read are two separate jobs, and this probe used to
            // report only the first. A repository with no manifest and a repository whose
            // manifest could not be fetched both arrived as `ok: true` with a null name, so
            // the create form showed „Repository erreichbar" and an empty name field either
            // way — and the operator had no way to tell "type it yourself" from "fix
            // access". The status says which, and never carries git's own words: the reason
            // goes to the log, for the same reason readableGitError() keeps it there.
            'manifest' => $manifest['status']->value,
            'manifest_file' => $type->manifestFile(),
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
     * @param  array<string, string>  $env
     * @return array{name: string|null, description: string|null, status: ManifestReadStatus}
     */
    private function readManifest(PackageType $type, string $url, ?string $branch, array $env = []): array
    {
        // Manifest filename comes from the type enum (the single source of truth).
        $manifest = $type->manifestFile();
        $dir = rtrim(sys_get_temp_dir(), '/').'/kfx-probe-'.Str::random(12);

        try {
            // `--filter=blob:none` earns its place and stays. The probe runs synchronously
            // inside a throttled web request, against a repository the caller names, on a
            // 60 s budget — and `--no-checkout` alone saves nothing on the wire (git still
            // packs every blob of the tip commit, it just does not write them out), so
            // without the filter the transfer is the whole tip tree of whatever repository
            // the operator points at. With it, the clone is commits and trees, and exactly
            // one blob — the manifest — is fetched below. The price is that the fetch is a
            // real second round trip to the origin, which is precisely why every command
            // here has to carry $env.
            $clone = Process::env($env)->timeout(60)->run(array_filter([
                'git', 'clone', '--depth', '1', '--filter=blob:none', '--no-checkout', '--quiet',
                $branch ? '--branch' : null, $branch ?: null,
                '--end-of-options', $url, $dir,
            ]));

            if (! $clone->successful()) {
                return $this->manifestFailure('clone', $type, $url, $clone->errorOutput());
            }

            // Trees arrive with the clone (only blobs are filtered), so this settles
            // "the repository has no such file" locally, with no network at all — and it is
            // what makes the two outcomes separable: after it, a failing `show` below is
            // always a read failure and never an absent manifest.
            $entry = Process::path($dir)->env($env)->timeout(15)->run([
                'git', 'ls-tree', '--name-only', '--end-of-options', 'HEAD', '--', $manifest,
            ]);

            if (! $entry->successful()) {
                return $this->manifestFailure('ls-tree', $type, $url, $entry->errorOutput());
            }

            if (trim($entry->output()) === '') {
                return ['name' => null, 'description' => null, 'status' => ManifestReadStatus::Missing];
            }

            // $env, not a bare Process — this was the bug. The blob is deliberately not in
            // the clone, so `git show` lazily fetches it from the promisor remote: a second
            // request to the origin. GitAuth's credential lives in GIT_CONFIG_* environment
            // variables and is never written into the clone's own config, so a Process
            // without $env sent that fetch out unauthenticated. A private repository
            // answered 401, `readManifest` gave up, and probe() still reported ok — the
            // create form said „Repository erreichbar" and left the name empty. Public
            // repositories were unaffected, because the anonymous fetch succeeds there,
            // which is what made this look type- or repository-specific.
            // GIT_TERMINAL_PROMPT=0 and http.followRedirects=false ride along for exactly
            // the reasons GitAuth documents; they applied to the clone and not to this.
            $show = Process::path($dir)->env($env)->timeout(30)->run([
                'git', 'show', '--end-of-options', 'HEAD:'.$manifest,
            ]);

            if (! $show->successful()) {
                return $this->manifestFailure('show', $type, $url, $show->errorOutput());
            }

            // Composer/npm manifests are JSON; Python's pyproject.toml is TOML.
            $parsed = $type === PackageType::Python
                ? $this->parsePyproject($show->output())
                : $this->parseJsonManifest($show->output());

            // Only the JSON reader can report "this is not a manifest at all"; the
            // pyproject reader is a key scraper, so a file it finds no name in is a read
            // that succeeded and simply had nothing to offer.
            if ($parsed === null) {
                return $this->manifestFailure('parse', $type, $url, 'manifest is not valid JSON');
            }

            return ['name' => $parsed[0], 'description' => $parsed[1], 'status' => ManifestReadStatus::Ok];
        } catch (Throwable $e) {
            return $this->manifestFailure('exception', $type, $url, class_basename($e));
        } finally {
            $this->deleteDir($dir);
        }
    }

    /**
     * Records why the manifest read failed and reports it as unreadable.
     *
     * Nothing used to be recorded at all: a failed clone, a failed read, an unparseable
     * manifest and a thrown exception all became a silent `[null, null]`, which is what let
     * a probe report success while having failed at half its job. The detail goes to the
     * log and only to the log — read readableGitError()'s note for why git's raw stderr
     * must not travel back to a console operator who named the target. Scrubbed, because an
     * `Authorization: Basic …` header can appear in it verbatim.
     *
     * @return array{name: null, description: null, status: ManifestReadStatus}
     */
    private function manifestFailure(string $step, PackageType $type, string $url, string $detail): array
    {
        Log::warning('Repository probe could not read the package manifest.', [
            'step' => $step,
            'type' => $type->value,
            'manifest' => $type->manifestFile(),
            'host' => (string) parse_url($url, PHP_URL_HOST),
            'detail' => Str::limit(GitAuth::scrub(trim($detail)), 500),
        ]);

        return ['name' => null, 'description' => null, 'status' => ManifestReadStatus::Unreadable];
    }

    /**
     * @return array{0: string|null, 1: string|null}|null [name, description], or null when
     *                                                    the payload is not JSON at all
     */
    private function parseJsonManifest(string $raw): ?array
    {
        /** @var array<string,mixed>|null $json */
        $json = json_decode($raw, true);
        if (! is_array($json)) {
            return null;
        }

        $name = isset($json['name']) && is_string($json['name']) ? $json['name'] : null;
        $description = isset($json['description']) && is_string($json['description']) ? $json['description'] : null;

        return [$name, $description];
    }

    /**
     * Lightweight pyproject.toml reader: extract `name`/`description` without a full TOML
     * parser. Matches both PEP 621 (`[project]`) and Poetry (`[tool.poetry]`) layouts, which
     * both declare a top-level `name = "..."` / `description = "..."`.
     *
     * @return array{0: string|null, 1: string|null} [name, description]
     */
    private function parsePyproject(string $raw): array
    {
        $extract = static function (string $key) use ($raw): ?string {
            if (preg_match('/(?:^|\n)[ \t]*'.$key.'[ \t]*=[ \t]*["\']([^"\']+)["\']/', $raw, $m) === 1) {
                return trim($m[1]);
            }

            return null;
        };

        return [$extract('name'), $extract('description')];
    }

    /**
     * Turns git's stderr into one of four fixed answers.
     *
     * The three named classes are product feedback and stay: they tell an operator whether
     * to add a credential, fix a typo or check the network, and they say nothing the caller
     * did not already supply. The fourth used to be the raw stderr, truncated to 200
     * characters — and for the `ssh://` class that is the resolved IP address and the
     * per-port connection state ("connect to host X port N: Connection refused" vs a
     * banner), handed to an org Maintainer, the lowest console tier, for any target they
     * name. The address policy in front of this refuses private targets; the stderr echo
     * reported on everything that got past it, which made the probe a port scanner with a
     * nicer UI. The detail the operator legitimately needs goes to the log, which is the
     * operator's channel; the caller gets the non-distinguishing answer.
     */
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
            return self::UNREACHABLE;
        }

        if ($stderr !== '') {
            Log::info('Repository probe: unrecognised git transport error.', [
                'stderr' => Str::limit($stderr, 500),
            ]);
        }

        return 'Repository konnte nicht gelesen werden.';
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
