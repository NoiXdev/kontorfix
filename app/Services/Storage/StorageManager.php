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
        $s = $this->current();

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
        try {
            config(['filesystems.disks.artifacts' => $this->diskConfig()]);
            Storage::forgetDisk('artifacts');
            $disk = Storage::disk('artifacts');
            $probe = '.kontorfix-storage-check-'.bin2hex(random_bytes(4));
            $disk->put($probe, 'ok');
            $ok = $disk->get($probe) === 'ok';
            $disk->delete($probe);

            return ['ok' => $ok, 'message' => $ok ? 'Verbindung erfolgreich.' : 'Schreib-/Leseprobe fehlgeschlagen.'];
        } catch (Throwable $e) {
            return ['ok' => false, 'message' => $e->getMessage()];
        }
    }
}
