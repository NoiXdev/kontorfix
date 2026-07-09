# Reverb Betrieb (WebSocket-Server)

Live-Updates im Operator-Portal laufen über Laravel Reverb. Dieses Dokument
beschreibt den Betrieb des `reverb`-Containers und die nötige Konfiguration.

## Container

Der `reverb`-Service (`CONTAINER_ROLE=reverb`) fährt beim Start
`php artisan reverb:start --host=0.0.0.0 --port=8080`. Der WebSocket-Server
lauscht damit containerintern auf Port `8080`. In `docker/compose.yaml` ist der
Service analog zu `worker` aufgebaut (gleiches Image, `env_file: .env`,
`depends_on: redis (service_healthy)`, `restart: unless-stopped`) und mappt den
internen Port nach `8081` am Host (`ports: ['8081:8080']`).

## Öffentlicher Endpunkt (Prod)

In Produktion wird der WebSocket-Endpunkt **nicht** direkt exponiert, sondern
über den Reverse-Proxy (Traefik/Portainer) mit TLS terminiert. Der Proxy leitet
die öffentliche Domain auf den `reverb`-Container (intern Port `8080`) weiter.

Daraus folgt für die Env:

- `REVERB_HOST` = öffentliche Domain (z.B. `ws.kontorfix.example`)
- `REVERB_SCHEME=https`
- `VITE_REVERB_SCHEME=https` → der Browser-Client verbindet über `wss://`
- `REVERB_PORT=8080` (interner Port, den der Server bedient)

## Nötige Environment-Variablen

| Variable | Zweck |
| --- | --- |
| `BROADCAST_CONNECTION=reverb` | Broadcasting läuft über Reverb |
| `REVERB_APP_ID` | App-Identität des Reverb-Servers |
| `REVERB_APP_KEY` | Public Key (auch im Client) |
| `REVERB_APP_SECRET` | Secret des Reverb-Servers |
| `REVERB_HOST` | Host, an den der Client verbindet (öffentl. Domain in Prod) |
| `REVERB_PORT` | Port (intern `8080`) |
| `REVERB_SCHEME` | `https` in Prod, `http` in Dev |
| `VITE_REVERB_APP_KEY` | Client-Spiegel von `REVERB_APP_KEY` |
| `VITE_REVERB_HOST` | Client-Spiegel von `REVERB_HOST` |
| `VITE_REVERB_PORT` | Client-Spiegel von `REVERB_PORT` |
| `VITE_REVERB_SCHEME` | Client-Spiegel von `REVERB_SCHEME` |

**Wichtig:** Die `VITE_REVERB_*`-Variablen werden von Vite zur **Build-Zeit** in
das Frontend-Bundle eingebacken. Sie müssen daher bereits beim
`npm run build` korrekt gesetzt sein — ein nachträgliches Ändern zur Laufzeit
wirkt nicht.

## Sicherheit

Es wird ausschließlich der private `operator`-Channel genutzt. Die Channel-Auth
erlaubt nur Nutzer der Operator-Organisation (`is_operator`). Kunden können sich
damit nicht auf den Channel abonnieren und erhalten keine fremden Sync-Events.

## Broadcast-Events

| Event-Klasse | Broadcast-Name |
| --- | --- |
| `PackageSynced` | `package.synced` |
| `PackageSyncFailed` | `package.sync_failed` |

## Lokale Entwicklung (DDEV)

Für Live-Updates in der lokalen Umgebung den Reverb-Server in einem separaten
Terminal starten:

```sh
ddev exec php artisan reverb:start
```

In der lokalen `.env` gilt `REVERB_HOST=localhost` und `REVERB_SCHEME=http`
(der Client verbindet dann über `ws://`). Ohne laufenden Reverb-Server bleiben
die Live-Updates aus, die Anwendung funktioniert ansonsten normal.
