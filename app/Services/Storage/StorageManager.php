<?php

namespace App\Services\Storage;

use App\Models\StorageSetting;
use Illuminate\Support\Facades\Storage;
use Throwable;

class StorageManager
{
    public function current(): StorageSetting
    {
        return StorageSetting::current();
    }

    /** @return array<string,mixed> */
    public function diskConfig(): array
    {
        return $this->diskConfigFor($this->current());
    }

    /**
     * Builds the Flysystem config array from a (possibly unsaved) setting —
     * this allows testing a planned configuration before it is persisted.
     *
     * @return array<string,mixed>
     */
    public function diskConfigFor(StorageSetting $s): array
    {
        if ($s->driver === 's3') {
            return [
                'driver' => 's3',
                'key' => $s->key,
                'secret' => $s->secret,
                'region' => $s->region,
                'bucket' => $s->bucket,
                'endpoint' => $s->endpoint ?: null,
                'url' => $s->url ?: null,
                'use_path_style_endpoint' => $s->use_path_style,
                'throw' => true,
            ];
        }

        return [
            'driver' => 'local',
            'root' => storage_path('app/artifacts'),
            'throw' => true,
        ];
    }

    /** @return array{ok:bool,message:string} */
    public function testConnection(): array
    {
        return $this->testConfig($this->diskConfig());
    }

    /**
     * Writes/reads/deletes a probe file on an arbitrary disk config.
     *
     * @param  array<string,mixed>  $config
     * @return array{ok:bool,message:string}
     */
    public function testConfig(array $config): array
    {
        try {
            config(['filesystems.disks.artifacts' => $config]);
            Storage::forgetDisk('artifacts');
            $disk = Storage::disk('artifacts');
            $probe = '.kontorfix-storage-check-'.bin2hex(random_bytes(4));

            try {
                $disk->put($probe, 'ok');
                $ok = $disk->get($probe) === 'ok';

                return ['ok' => $ok, 'message' => $ok ? 'Verbindung erfolgreich.' : 'Schreib-/Leseprobe fehlgeschlagen.'];
            } finally {
                // Remove the probe file even on partial failure, no orphans.
                try {
                    $disk->delete($probe);
                } catch (Throwable) {
                    // ignore
                }
            }
        } catch (Throwable $e) {
            return ['ok' => false, 'message' => $e->getMessage()];
        } finally {
            // Restore the regular (persisted) config after the test.
            config(['filesystems.disks.artifacts' => $this->diskConfig()]);
            Storage::forgetDisk('artifacts');
        }
    }
}
