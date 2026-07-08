# Kontorfix

Kontorfix ist eine selbst gehostete Registry für private Composer-Pakete.
Sie bündelt Pakete in **Gruppen** (eigene Slug-Route, z. B. `/r/acme/packages.json`),
regelt den Zugriff über revozierbare **Tokens** und bietet ein schlankes
**Admin-GUI** zur Verwaltung von Paketen, Gruppen und Tokens.

Technisch: Laravel 12 (PHP 8.4) + Inertia/Vue 3, Postgres 17, Redis, ausgeliefert
über FrankenPHP.

## Features (v0.1)

- Privater Composer-Paket-Pool mit Sync aus Git-Repositories (Tags → Versionen)
- Composer-v2-Metadaten-Endpoints (`packages.json`, `p2/*.json`) inkl. Dist-Download
- Gruppen als Sub-Registries mit eigenem Slug und Paket-Zuweisung
- Revozierbare Registry-Tokens (Basic-Auth) mit feingranularer ACL
- Admin-GUI (Inertia/Vue) für Pakete, Gruppen und Tokens

## Lokale Entwicklung

Voraussetzung: [DDEV](https://ddev.com/).

```bash
ddev start
ddev composer install
ddev exec npm ci
ddev artisan migrate
ddev exec npm run dev
```

Die Anwendung ist danach unter `https://kontorfix.ddev.site` erreichbar.

### Tests & Qualität

```bash
ddev artisan test              # Pest-Testsuite (Postgres)
ddev exec vendor/bin/pint --test    # Format-Check
ddev exec vendor/bin/phpstan        # Statische Analyse (Level 6)
ddev exec npm run lint:check        # ESLint (Check-only, kein --fix)
```

## Docker-Image & Deployment

Das produktive Image basiert auf FrankenPHP (`dunglas/frankenphp:1-php8.4`) und
wird mehrstufig gebaut (Node-Stage für die Assets, PHP-Stage für die App):

```bash
docker build -f docker/Dockerfile -t kontorfix .
```

Für einen lokalen Stack (App, Worker, Scheduler, Postgres, Redis) liegt unter
`docker/compose.yaml` ein Compose-File bereit:

```bash
cp docker/.env.example docker/.env   # anpassen: APP_KEY, DB_PASSWORD, ...
docker compose -f docker/compose.yaml --env-file docker/.env up -d
```

Deploy erfolgt über Portainer gegen die Harbor-Registry
`harbor.cloud.noidee.dev/noixdev/kontorfix`.

## CI

`.github/workflows/ci.yml` prüft bei jedem Push/PR:

- Commit-Messages gegen Conventional Commits (`commitlint`)
- Format (Pint), Lint (ESLint), statische Analyse (PHPStan)
- Asset-Build (Vite) und die Pest-Testsuite gegen Postgres/Redis

## Dokumentation

Architektur, Spec und Implementierungsplan liegen im separaten `docs`-Repo
unter `docs/superpowers/` (Plans & Specs) sowie `docs/brand/` (Markenauftritt).
