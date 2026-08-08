<?php

namespace App\Services\Health;

use App\Models\Upstream;
use App\Services\Broadcasting\ReverbConfigGuard;
use App\Services\Storage\StorageManager;
use App\Services\Upstream\UrlSafety;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Throwable;

class HealthService
{
    public function __construct(private StorageManager $storage) {}

    /** @return list<array{key:string,label:string,ok:bool,detail:string}> */
    public function checks(): array
    {
        return [
            $this->database(),
            $this->cache(),
            $this->queue(),
            $this->storageCheck(),
            ...$this->broadcasting(),
            ...$this->upstreams(),
        ];
    }

    /**
     * The operator-visible half of the Reverb secret guard. `reverb:start` refuses to
     * come up on a published secret (see ReverbConfigGuard), but a crash-looping
     * container is easy to miss and the app itself keeps serving — so the reason shows
     * up here too. Emitted only when broadcasting actually runs over Reverb; on the
     * `null` driver there is no websocket server and nothing to report.
     *
     * @return list<array{key:string,label:string,ok:bool,detail:string}>
     */
    private function broadcasting(): array
    {
        if (config('broadcasting.default') !== 'reverb' || ReverbConfigGuard::exempt()) {
            return [];
        }

        $problem = ReverbConfigGuard::problem();

        if ($problem === null) {
            return [];
        }

        return [[
            'key' => 'broadcasting',
            'label' => 'Broadcasting (Reverb)',
            'ok' => false,
            'detail' => $problem,
        ]];
    }

    /** @return array{key:string,label:string,ok:bool,detail:string} */
    private function database(): array
    {
        try {
            DB::select('select 1');

            return ['key' => 'database', 'label' => 'Datenbank', 'ok' => true, 'detail' => 'Verbunden.'];
        } catch (Throwable $e) {
            return ['key' => 'database', 'label' => 'Datenbank', 'ok' => false, 'detail' => $e->getMessage()];
        }
    }

    /** @return array{key:string,label:string,ok:bool,detail:string} */
    private function cache(): array
    {
        try {
            $probe = 'health:'.Str::random(8);
            Cache::put($probe, 'ok', 5);
            $ok = Cache::get($probe) === 'ok';
            Cache::forget($probe);

            return ['key' => 'cache', 'label' => 'Cache / Redis', 'ok' => $ok, 'detail' => $ok ? 'Schreib-/Leseprobe ok.' : 'Probe fehlgeschlagen.'];
        } catch (Throwable $e) {
            return ['key' => 'cache', 'label' => 'Cache / Redis', 'ok' => false, 'detail' => $e->getMessage()];
        }
    }

    /** @return array{key:string,label:string,ok:bool,detail:string} */
    private function queue(): array
    {
        $failed = 0;
        try {
            $failed = DB::table('failed_jobs')->count();
        } catch (Throwable) {
            // Table might be missing — then 0.
        }

        try {
            $size = Queue::size();
            $detail = "Wartend: {$size}, fehlgeschlagen: {$failed}.";

            return ['key' => 'queue', 'label' => 'Queue', 'ok' => $failed === 0, 'detail' => $detail];
        } catch (Throwable $e) {
            return ['key' => 'queue', 'label' => 'Queue', 'ok' => $failed === 0, 'detail' => "Fehlgeschlagen: {$failed}. ({$e->getMessage()})"];
        }
    }

    /** @return array{key:string,label:string,ok:bool,detail:string} */
    private function storageCheck(): array
    {
        $result = $this->storage->testConnection();

        return ['key' => 'storage', 'label' => 'Storage', 'ok' => $result['ok'], 'detail' => $result['message']];
    }

    /** @return list<array{key:string,label:string,ok:bool,detail:string}> */
    private function upstreams(): array
    {
        return Upstream::query()->get()->map(function (Upstream $u): array {
            $label = 'Upstream: '.$u->url;

            if (! UrlSafety::isSafeResolving($u->url)) {
                return ['key' => 'upstream:'.$u->id, 'label' => $label, 'ok' => false, 'detail' => 'Unsichere/nicht auflösbare URL.'];
            }

            try {
                $response = Http::timeout(5)->withoutRedirecting()->get($u->url);

                return ['key' => 'upstream:'.$u->id, 'label' => $label, 'ok' => $response->status() < 500, 'detail' => 'HTTP '.$response->status()];
            } catch (Throwable $e) {
                return ['key' => 'upstream:'.$u->id, 'label' => $label, 'ok' => false, 'detail' => $e->getMessage()];
            }
        })->all();
    }
}
