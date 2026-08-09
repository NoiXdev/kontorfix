<?php

namespace App\Services\Health;

use App\Enums\PackageType;
use App\Models\Upstream;
use App\Services\Broadcasting\ReverbConfigGuard;
use App\Services\Http\AppUrl;
use App\Services\Storage\StorageManager;
use App\Services\Upstream\UrlSafety;
use App\Support\CredentialUrl;
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
            $this->appUrl(),
            $this->database(),
            $this->cache(),
            $this->queue(),
            $this->storageCheck(),
            ...$this->broadcasting(),
            ...$this->upstreams(),
        ];
    }

    /**
     * The operator-visible half of the Reverb guard. `reverb:start` refuses to come up on
     * a published secret or on an instance that does not broadcast over Reverb at all
     * (see ReverbConfigGuard) — and a container that refuses under a restart policy is
     * easy to miss, because the app itself keeps serving.
     *
     * Two sources, in order:
     *
     *  1. A refusal the websocket container actually recorded. This is reported whatever
     *     `broadcasting.default` says, because the previous version keyed the whole check
     *     off that setting — and a container refusing *because* the setting is `null` was
     *     exactly the state the check then declared uninteresting.
     *  2. The configuration this instance would start on, when it does broadcast over
     *     Reverb. Reported green as well as red: silence was previously the only "all
     *     good" signal, which is indistinguishable from a check that never ran.
     *
     * An instance with no websocket container and no Reverb driver still reports nothing
     * — there is no server, so there is no condition.
     *
     * @return list<array{key:string,label:string,ok:bool,detail:string}>
     */
    private function broadcasting(): array
    {
        if (ReverbConfigGuard::exempt()) {
            return [];
        }

        $label = 'Broadcasting (Reverb)';
        $refusal = ReverbConfigGuard::recordedRefusal();

        if ($refusal !== null) {
            return [[
                'key' => 'broadcasting',
                'label' => $label,
                'ok' => false,
                'detail' => 'Der WebSocket-Container startet nicht: '.$refusal,
            ]];
        }

        if (! ReverbConfigGuard::broadcastsOverReverb()) {
            return [];
        }

        $problem = ReverbConfigGuard::problem();

        return [[
            'key' => 'broadcasting',
            'label' => $label,
            'ok' => $problem === null,
            'detail' => $problem ?? 'Konfiguration ok.',
        ]];
    }

    /**
     * The only remaining state in which BOTH host controls stand down.
     *
     * `TrustedHosts` returns an empty allowlist — which Symfony reads as "trust every
     * `Host`" — and `PinUrlRoot` has no root to pin a generated URL to, so a fronting
     * proxy that forwards an arbitrary `Host` puts an attacker's domain into the
     * password-reset link. Both fail open deliberately (an unset variable must not lock an
     * instance out of itself), which is only defensible while the state is visible.
     *
     * A value written without a scheme is NOT this state: AppUrl normalises it.
     *
     * @return array{key:string,label:string,ok:bool,detail:string}
     */
    private function appUrl(): array
    {
        $root = AppUrl::root();

        return [
            'key' => 'app-url',
            'label' => 'APP_URL',
            'ok' => $root !== null,
            'detail' => $root
                ?? 'APP_URL nennt keinen Host. Die Host-Allowlist und die Verankerung erzeugter '
                    .'Links sind dadurch beide abgeschaltet — ein vorgelagerter Proxy kann den '
                    .'Host in Passwort-Reset-Links frei wählen.',
        ];
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
            // Same silence pattern as the broadcasting check had: an unreachable queue
            // backend used to report `ok` as long as no job had *already* failed — i.e.
            // green precisely while nothing could run at all.
            return ['key' => 'queue', 'label' => 'Queue', 'ok' => false, 'detail' => "Queue nicht erreichbar. Fehlgeschlagen: {$failed}. ({$e->getMessage()})"];
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
            // Redacted even here. This surface is super-admin only, but it is still a
            // read surface, and every other one in the application withholds userinfo.
            $label = 'Upstream: '.CredentialUrl::redact($u->url);

            // A credential in the URL disables the PyPI simple-index fallthrough
            // (PypiController::simpleProject): that path answers with a redirect, and a
            // redirect would hand the mirror's password to the client. Say so, rather
            // than letting upstream resolution stop working for no visible reason.
            if ($u->type === PackageType::Python && CredentialUrl::carries($u->url)) {
                return ['key' => 'upstream:'.$u->id, 'label' => $label, 'ok' => false, 'detail' => 'Die URL enthält ein Credential. Der PyPI-Simple-Index-Fallthrough ist für diesen '
                    .'Upstream deaktiviert, weil er per Redirect antwortet und das Credential damit an '
                    .'den Client ausliefern würde.'];
            }

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
