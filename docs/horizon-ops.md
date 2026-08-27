# Horizon – Betrieb

Laravel Horizon supervidiert die Redis-Queue und ersetzt den bisherigen
`queue:work`-Prozess. Das Dashboard liefert Live-Durchsatz, Supervisor-Status
und Zugriff auf fehlgeschlagene Jobs.

## Worker-Container

Der Worker-Container startet jetzt `php artisan horizon` (statt
`php artisan queue:work`). Horizon liest die Queue-Konfiguration aus
`config/horizon.php` und startet die dort definierten Supervisor-/Worker-Pools
gegen Redis. Der `scheduler`-Container (`schedule:work`) und der `app`-Container
bleiben unverändert.

## Dashboard

- Erreichbar unter `/horizon`.
- Zugriff ausschließlich für Operator-Admins über das `viewHorizon`-Gate.
- Zeigt Durchsatz (Jobs/min), Wait-Time, Supervisor- und Worker-Status sowie
  **fehlgeschlagene Jobs mit Retry**-Funktion.
- In der Admin-Sidebar (Footer) ist der Link „Queue (Horizon)" nur für Admins
  sichtbar und öffnet die Horizon-SPA per vollem Seiten-Reload (kein Inertia).

## Anforderungen ans Image

Horizon benötigt die PHP-Erweiterungen `ext-pcntl` und `ext-posix` (Prozess-
Signale für den graceful Restart). Das FrankenPHP-Base-Image bringt `pcntl`
in der Regel bereits mit. Falls Horizon beim Deploy mit fehlendem `pcntl`
scheitert, im Dockerfile `docker-php-ext-install pcntl` ergänzen bzw. das
Base-Image entsprechend erweitern. (Nur dokumentiert – hier nicht geändert.)

## Rolling-Deploy

Nach jedem Deploy `php artisan horizon:terminate` ausführen. Horizon beendet
seine Worker daraufhin graceful und startet mit dem neuen Code neu; der
Supervisor (Worker-Container-Prozessmanager) bringt den Prozess wieder hoch.

## Lang laufende Syncs: Timeout und Stop-Grace-Period

`SyncPackage` darf bis zu 900 Sekunden laufen (ein `git clone --mirror` eines
großen Repositories passt nicht in eine Minute) und hält währenddessen eine
Sperre auf dem Git-Mirror des Pakets. Wird der Worker in diesem Zustand hart
beendet, bleibt die Sperre bis zum Ablauf ihrer TTL bestehen — das Paket ist
dann bis zu 15 Minuten lang nicht synchronisierbar.

Damit das nicht bei jedem Deploy passiert, sind zwei Werte angehoben:

- `config/horizon.php` → `supervisor-1.timeout` steht auf 900 (aus
  `App\Jobs\SyncPackage::TIMEOUT` gelesen). Dieser Wert steuert nicht nur den
  Worker-Alarm, sondern auch, wie lange Horizon nach einem SIGTERM auf einen
  Worker wartet, bevor er hart gestoppt wird — und wie lange der
  Master-Supervisor beim Beenden wartet.
- `docker/compose.yaml` → der `worker`-Service hat `stop_grace_period: 930s`
  (Dockers Standard sind 10 Sekunden).

**Konsequenz für den Betrieb:** Ein Redeploy des Worker-Containers kann so
lange dauern, wie der gerade laufende Sync noch braucht — im Extremfall etwa
15 Minuten. Läuft kein Sync, beendet sich Horizon wie bisher in Sekunden. Wer
kurze Deploy-Zeiten wichtiger findet als eine unversehrte Mirror-Sperre, kann
`stop_grace_period` senken; der Preis ist genau der oben beschriebene.

Vollständig verhindern lässt sich der harte Abbruch nicht: `docker kill`, ein
OOM-Kill oder ein Host-Ausfall beenden den Prozess weiterhin sofort. Die
Mirror-Sperre ist deshalb so ausgelegt, dass ihr Ablauf den Schaden begrenzt,
nicht darauf, dass es nie passiert.

Jobs, die **kein** eigenes `$timeout` deklarieren, bekommen den
Supervisor-Wert — also 900 Sekunden. Neue Jobs sollten ihr Timeout deshalb
explizit setzen, so wie `DeliverWebhook` und `SendNotificationDigest` es tun.

## Dashboard-Assets

Falls die Horizon-Dashboard-Assets fehlen, mit `php artisan horizon:publish`
publizieren (bzw. als Schritt in den Image-Build aufnehmen).
