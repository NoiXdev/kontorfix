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

    public function __construct(private readonly string $url, string $storageKey)
    {
        if (! preg_match('/^[A-Za-z0-9._-]+$/', $storageKey) || str_contains($storageKey, '..')) {
            throw new InvalidArgumentException('Invalid storage key.');
        }

        $this->mirrorPath = storage_path('app/vcs/'.$storageKey.'.git');
    }

    public function sync(): void
    {
        if (is_dir($this->mirrorPath)) {
            $this->run(['git', 'fetch', '--prune', '--tags', 'origin']);

            return;
        }

        if (! is_dir(dirname($this->mirrorPath))) {
            mkdir(dirname($this->mirrorPath), 0775, true);
        }

        $result = Process::timeout(300)->run([
            'git', 'clone', '--mirror', '-c', 'protocol.file.allow=always', $this->url, $this->mirrorPath,
        ]);

        if (! $result->successful()) {
            throw new RuntimeException('git clone failed: '.$result->errorOutput());
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
     * Committer-Datum des Refs als ISO-8601-String — stabil über Re-Syncs hinweg,
     * anders als now().
     */
    public function committedAt(string $ref): string
    {
        return trim($this->run(['git', 'log', '-1', '--format=%cI', '--end-of-options', $ref])->output());
    }

    public function fileAtRef(string $ref, string $path): string
    {
        // --end-of-options verhindert, dass ein Ref/Pfad wie "--output=..." als git-Option
        // interpretiert wird (Option-Injection aus einem bösartigen Upstream-Tag).
        return $this->run(['git', 'show', '--end-of-options', "{$ref}:{$path}"])->output();
    }

    /**
     * Baut ein Zip-Archiv des Refs. Der Aufrufer ist für das Löschen der zurückgegebenen
     * Datei verantwortlich.
     */
    public function archiveZip(string $ref): string
    {
        $stub = tempnam(sys_get_temp_dir(), 'kfx-dist-');
        $zip = $stub.'.zip';

        try {
            $this->run(['git', 'archive', '--format=zip', '-o', $zip, '--end-of-options', $ref]);
        } catch (Throwable $e) {
            @unlink($zip); // git legt die Ausgabedatei vor der Ref-Prüfung an — bei Fehler wegräumen
            throw $e;
        } finally {
            @unlink($stub); // tempnam-Stub immer entfernen
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
