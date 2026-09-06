<?php

namespace Tests\Support;

use League\Flysystem\Config;
use League\Flysystem\FileAttributes;
use League\Flysystem\FilesystemAdapter;
use League\Flysystem\UnableToReadFile;

/**
 * A minimal, in-process Flysystem adapter standing in for a remote object store (S3) in
 * tests — deliberately NOT `League\Flysystem\Local\LocalFilesystemAdapter`, which is
 * exactly the property `App\Services\Oci\BlobStore::isLocalDisk()` checks for. There is no
 * real S3 credential available to this test suite, so this is how BlobStore's
 * "disk cannot append in place" fallback path gets exercised by something other than
 * reading the code.
 *
 * Also records every write, so a test can assert HOW MANY bytes moved per call — the whole
 * point of fixing BlobStore's chunked append being that each call moves only its own
 * chunk, never the accumulated upload.
 */
final class InMemoryFilesystemAdapter implements FilesystemAdapter
{
    /** @var array<string, string> */
    private array $files = [];

    /** @var list<int> bytes written per writeStream()/write() call, in call order. */
    public array $writeSizes = [];

    public function fileExists(string $path): bool
    {
        return array_key_exists($path, $this->files);
    }

    public function directoryExists(string $path): bool
    {
        $prefix = rtrim($path, '/').'/';

        foreach (array_keys($this->files) as $file) {
            if (str_starts_with($file, $prefix)) {
                return true;
            }
        }

        return false;
    }

    public function write(string $path, string $contents, Config $config): void
    {
        $this->files[$path] = $contents;
        $this->writeSizes[] = strlen($contents);
    }

    public function writeStream(string $path, $contents, Config $config): void
    {
        $contents = stream_get_contents($contents);
        $this->files[$path] = $contents;
        $this->writeSizes[] = strlen($contents);
    }

    public function read(string $path): string
    {
        if (! $this->fileExists($path)) {
            throw UnableToReadFile::fromLocation($path);
        }

        return $this->files[$path];
    }

    /** @return resource */
    public function readStream(string $path)
    {
        $stream = fopen('php://temp', 'w+b');
        fwrite($stream, $this->read($path));
        rewind($stream);

        return $stream;
    }

    public function delete(string $path): void
    {
        unset($this->files[$path]);
    }

    public function deleteDirectory(string $path): void
    {
        $prefix = rtrim($path, '/').'/';

        foreach (array_keys($this->files) as $file) {
            if (str_starts_with($file, $prefix)) {
                unset($this->files[$file]);
            }
        }
    }

    public function createDirectory(string $path, Config $config): void
    {
        // No-op: this in-memory model has no directories of its own, only path prefixes.
    }

    public function setVisibility(string $path, string $visibility): void
    {
        // No-op: visibility is not modelled.
    }

    public function visibility(string $path): FileAttributes
    {
        return new FileAttributes($path);
    }

    public function mimeType(string $path): FileAttributes
    {
        return new FileAttributes($path, null, null, null, 'application/octet-stream');
    }

    public function lastModified(string $path): FileAttributes
    {
        return new FileAttributes($path, null, null, time());
    }

    public function fileSize(string $path): FileAttributes
    {
        return new FileAttributes($path, strlen($this->read($path)));
    }

    /** @return iterable<FileAttributes> */
    public function listContents(string $path, bool $deep): iterable
    {
        $prefix = $path === '' ? '' : rtrim($path, '/').'/';

        foreach ($this->files as $file => $contents) {
            if ($prefix === '' || str_starts_with($file, $prefix)) {
                yield new FileAttributes($file, strlen($contents));
            }
        }
    }

    public function move(string $source, string $destination, Config $config): void
    {
        $this->files[$destination] = $this->files[$source] ?? '';
        unset($this->files[$source]);
    }

    public function copy(string $source, string $destination, Config $config): void
    {
        $this->files[$destination] = $this->files[$source] ?? '';
    }
}
