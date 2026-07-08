<?php

namespace App\Services\Vcs;

use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Support\Facades\Process;
use RuntimeException;

class GitRepository
{
    private string $mirrorPath;

    public function __construct(private readonly string $url, string $storageKey)
    {
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
        return trim($this->run(['git', 'rev-list', '-n', '1', $ref])->output());
    }

    public function fileAtRef(string $ref, string $path): string
    {
        return $this->run(['git', 'show', "{$ref}:{$path}"])->output();
    }

    public function archiveZip(string $ref): string
    {
        $tmp = tempnam(sys_get_temp_dir(), 'kfx-dist-').'.zip';
        $this->run(['git', 'archive', '--format=zip', '-o', $tmp, $ref]);

        return $tmp;
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
