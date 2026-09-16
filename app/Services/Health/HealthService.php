<?php

namespace App\Services\Health;

use App\Enums\PackageType;
use App\Models\OciScanReport;
use App\Models\Upstream;
use App\Services\Broadcasting\ReverbConfigGuard;
use App\Services\Http\AppUrl;
use App\Services\Scanner\VulnerabilityScanner;
use App\Services\Storage\StorageManager;
use App\Services\Upstream\UrlSafety;
use App\Services\Users\EmailUniquenessIndex;
use App\Support\CredentialUrl;
use App\Support\TrustedProxies;
use Illuminate\Support\Carbon;
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
            $this->trustedProxies(),
            $this->database(),
            $this->cache(),
            $this->queue(),
            $this->storageCheck(),
            $this->emailUniqueness(),
            ...$this->broadcasting(),
            ...$this->upstreams(),
            ...$this->scanner(),
        ];
    }

    /**
     * The scanner, if there is one.
     *
     * TWO checks, not one, because they fail independently and only one of them is obvious.
     * Reachability answers "does the scanner answer"; freshness answers "is what it says
     * still worth anything". A scanner whose vulnerability database stopped updating three
     * weeks ago answers every request and reports reassuring zeros — it is more dangerous
     * than no scanner at all, and reachability alone is green for exactly that case.
     *
     * Empty — not green, not red — when the feature is switched off. An instance that
     * deliberately does not scan must not grow a permanently failing check, because a check
     * an operator learns to ignore is worse than no check.
     *
     * @return list<array{key:string,label:string,ok:bool,detail:string}>
     */
    private function scanner(): array
    {
        if (! config('kontorfix.scanner.enabled', false)) {
            return [];
        }

        return [$this->scannerReachability(), $this->scannerFreshness()];
    }

    /**
     * @return array{key:string,label:string,ok:bool,detail:string}
     */
    private function scannerReachability(): array
    {
        $url = (string) config('kontorfix.scanner.url', '');

        try {
            // Bounded by kontorfix.scanner.request_timeout (30s default) plus a 5s connect
            // timeout, the same budget every other adapter call uses (HarborAdapterScanner)
            // — this route is polled by monitoring, so a hanging scanner must not pile up
            // requests here any more than it should on a scan in flight.
            $metadata = app(VulnerabilityScanner::class)->metadata();

            return [
                'key' => 'scanner',
                'label' => 'Schwachstellen-Scanner',
                'ok' => true,
                'detail' => trim($metadata->name.' '.(string) $metadata->version).' unter '.$url.' erreichbar.',
            ];
        } catch (Throwable $e) {
            return [
                'key' => 'scanner',
                'label' => 'Schwachstellen-Scanner',
                'ok' => false,
                // The message, not a generic sentence: ScannerException's messages already
                // name the thing to go and look at, including the allowlist entry a refused
                // address needs.
                'detail' => $e->getMessage(),
            ];
        }
    }

    /**
     * @return array{key:string,label:string,ok:bool,detail:string}
     */
    private function scannerFreshness(): array
    {
        $newest = OciScanReport::whereNotNull('scanned_at')->max('scanned_at');

        // No verdict at all is not a failure: an instance with no images yet, or one that
        // has only just switched this on, is in a normal state and a red check there would
        // train the operator to ignore this row.
        if ($newest === null) {
            return [
                'key' => 'scanner-freshness',
                'label' => 'Aktualität der Prüfungen',
                'ok' => true,
                'detail' => 'Es liegt noch kein Prüfergebnis vor.',
            ];
        }

        $age = Carbon::parse($newest)->diffInDays(now());

        // The daily rescan means the newest verdict on the instance should never be more
        // than a day or two old. A week is generous slack for a quiet instance; beyond it,
        // something has stopped running and the numbers on every image page are fiction.
        $ok = $age <= 7;

        return [
            'key' => 'scanner-freshness',
            'label' => 'Aktualität der Prüfungen',
            'ok' => $ok,
            'detail' => $ok
                ? 'Die neueste Prüfung ist vom '.Carbon::parse($newest)->toDateString().'.'
                : 'Die neueste erfolgreiche Prüfung ist '.(int) $age.' Tage alt — läuft "oci:scan" noch, und aktualisiert der Scanner seine Datenbank?',
        ];
    }

    /**
     * Whether one address really is one account.
     *
     * `OidcUserResolver` matches on `lower(email)` while the column's own unique constraint
     * is case-sensitive, so a case-variant pair is an identity-confusion state the resolver
     * has to pick between. The migration installs a unique functional index where it can and
     * falls back to a non-unique one where collisions already exist, rather than failing the
     * deploy — which is only defensible if the fallback is visible. This is where it is.
     *
     * @return array{key:string,label:string,ok:bool,detail:string}
     */
    private function emailUniqueness(): array
    {
        $index = app(EmailUniquenessIndex::class);

        if ($index->isEnforced()) {
            return [
                'key' => 'email-uniqueness',
                'label' => 'E-Mail-Eindeutigkeit',
                'ok' => true,
                'detail' => 'Eindeutiger Index auf lower(users.email) ist aktiv.',
            ];
        }

        $collisions = $index->collisions();

        return [
            'key' => 'email-uniqueness',
            'label' => 'E-Mail-Eindeutigkeit',
            'ok' => false,
            'detail' => $collisions === []
                ? 'Der eindeutige Index auf lower(users.email) fehlt — "php artisan users:enforce-email-uniqueness" installiert ihn.'
                : count($collisions).' Adresse(n) gehören zu mehreren Konten ('.implode(', ', array_keys($collisions))
                    .'). Konten zusammenführen, dann "php artisan users:enforce-email-uniqueness" ausführen.',
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

    /**
     * Whether the forwarded-header trust is pinned to the proxy or left wide open.
     *
     * TRUSTED_PROXIES decides who may set `X-Forwarded-For`, and with it what every
     * IP-keyed limiter counts and what the audit log records as the origin of an action.
     * It ships covering every private range, because the application cannot know an
     * operator's proxy address. docs/development.md has always said to pin it — but an
     * instruction nothing checks is advice, not a control, so the state belongs on screen.
     *
     * Not a failure: the shipped breadth is harmless against an internet attacker, since
     * the trusted-proxy walk stops at the first untrusted address, which behind Traefik is
     * the real client. It matters for something already inside the private network.
     *
     * @return array{key:string,label:string,ok:bool,detail:string}
     */
    private function trustedProxies(): array
    {
        $value = trim((string) config('kontorfix.trusted_proxies'));
        $broad = TrustedProxies::isBroad($value);

        return [
            'key' => 'trusted-proxies',
            'label' => 'TRUSTED_PROXIES',
            'ok' => ! $broad,
            'detail' => match (true) {
                $value === '' => 'Nicht gesetzt — es wird keinem Proxy vertraut, weitergereichtes '
                    .'Schema und Host werden ignoriert und erzeugte Links zeigen auf den internen Host.',
                $value === '*' => 'Auf "*" gesetzt: jede Gegenstelle darf X-Forwarded-For bestimmen. '
                    .'Damit sind IP-basierte Limits umgehbar und die im Audit-Log vermerkte Adresse frei wählbar.',
                $broad => "Umfasst mehr als die konkreten Proxy-Adressen ({$value}). Alles, was die "
                    .'Anwendung aus diesem Netz erreicht, darf damit X-Forwarded-For setzen — IP-basierte '
                    .'Limits sind umgehbar und die Adresse im Audit-Log ist fälschbar. Auf die IP(s) des '
                    .'vorgelagerten Proxys eingrenzen.',
                default => $value,
            },
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
