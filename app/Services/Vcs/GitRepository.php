<?php

namespace App\Services\Vcs;

use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Support\Facades\Process;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

class GitRepository
{
    private string $mirrorPath;

    /** @var array<string, string> */
    private array $authEnv;

    public function __construct(private readonly string $url, string $storageKey, ?string $token = null)
    {
        if (! preg_match('/^[A-Za-z0-9._-]+$/', $storageKey) || str_contains($storageKey, '..')) {
            throw new InvalidArgumentException('Invalid storage key.');
        }

        $this->mirrorPath = storage_path('app/vcs/'.$storageKey.'.git');
        $this->authEnv = GitAuth::env($url, $token);
    }

    public function sync(): void
    {
        if (is_dir($this->mirrorPath)) {
            // fetch needs the auth header too (the mirror's stored URL is token-free).
            $result = Process::path($this->mirrorPath)->env($this->authEnv)->timeout(120)
                ->run(['git', 'fetch', '--prune', '--tags', 'origin']);

            if (! $result->successful()) {
                throw new RuntimeException('git fetch failed: '.GitAuth::scrub($result->errorOutput()));
            }

            return;
        }

        if (! is_dir(dirname($this->mirrorPath))) {
            mkdir(dirname($this->mirrorPath), 0775, true);
        }

        $result = Process::env($this->authEnv)->timeout(300)->run([
            'git', 'clone', '--mirror', '-c', 'protocol.file.allow=always', $this->url, $this->mirrorPath,
        ]);

        if (! $result->successful()) {
            throw new RuntimeException('git clone failed: '.GitAuth::scrub($result->errorOutput()));
        }
    }

    /** @return list<string> */
    public function tags(): array
    {
        return array_values(array_filter(explode("\n", $this->run(['git', 'tag', '-l'])->output())));
    }

    public function commitFor(string $ref): string
    {
        return trim($this->run(['git', 'rev-list', '-n', '1', '--end-of-options', $ref])->output());
    }

    /**
     * Committer date of the ref as an ISO-8601 string — stable across re-syncs,
     * unlike now().
     */
    public function committedAt(string $ref): string
    {
        return trim($this->run(['git', 'log', '-1', '--format=%cI', '--end-of-options', $ref])->output());
    }

    public function fileAtRef(string $ref, string $path): string
    {
        // --end-of-options prevents a ref/path like "--output=..." from being
        // interpreted as a git option (option injection from a malicious upstream tag).
        return $this->run(['git', 'show', '--end-of-options', "{$ref}:{$path}"])->output();
    }

    /**
     * Builds a zip archive of the ref. The caller is responsible for deleting the
     * returned file.
     */
    public function archiveZip(string $ref): string
    {
        $stub = tempnam(sys_get_temp_dir(), 'kfx-dist-');
        $zip = $stub.'.zip';

        try {
            $this->run(['git', 'archive', '--format=zip', '-o', $zip, '--end-of-options', $ref]);
        } catch (Throwable $e) {
            @unlink($zip); // git creates the output file before the ref check — clean up on error
            throw $e;
        } finally {
            @unlink($stub); // always remove the tempnam stub
        }

        return $zip;
    }

    /**
     * @param  list<string>  $command
     */
    private function run(array $command): ProcessResult
    {
        $result = Process::path($this->mirrorPath)->timeout(120)->run($command);

        if (! $result->successful()) {
            throw new RuntimeException(implode(' ', $command).' failed: '.$result->errorOutput());
        }

        return $result;
    }
}
