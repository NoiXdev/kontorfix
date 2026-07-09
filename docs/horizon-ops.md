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

## Dashboard-Assets

Falls die Horizon-Dashboard-Assets fehlen, mit `php artisan horizon:publish`
publizieren (bzw. als Schritt in den Image-Build aufnehmen).
