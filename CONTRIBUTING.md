# Mitwirken

Danke für dein Interesse an Kontorfix! Diese Kurzanleitung hilft beim Einstieg.

## Setup

Die lokale Umgebung basiert auf DDEV. Die vollständige Einrichtung steht in der
[Entwickler- und Betriebsdokumentation](docs/development.md):

```bash
ddev start
ddev composer install
ddev exec npm install
ddev exec php artisan key:generate
ddev exec php artisan migrate --seed
```

## Vor einem Pull Request

Alle Prüfungen müssen grün sein:

```bash
ddev exec vendor/bin/pest              # Tests
ddev exec vendor/bin/pint --test       # Code-Style
ddev exec vendor/bin/phpstan analyse   # Static Analysis
ddev exec npm run lint                 # ESLint
ddev exec npm run build                # Frontend-Build
```

Neue Logik kommt mit Tests (Pest). Bugfixes bekommen einen Regressionstest.

## Konventionen

- **Commits:** [Conventional Commits](https://www.conventionalcommits.org/)
  (`feat`, `fix`, `docs`, `refactor`, `perf`, `test`, `build`, `ci`, `chore`).
  Die CI erzwingt das.
- **Branches:** Feature-Branches, kein direktes Arbeiten auf `main`.
- **Pull Requests:** klein und fokussiert; beschreibe Motivation und Auswirkung.
  Sicherheitsrelevante Änderungen bitte im PR kennzeichnen.

## Sicherheit

Schwachstellen bitte **nicht** über öffentliche Issues melden, sondern vertraulich —
siehe [SECURITY.md](SECURITY.md).

## Verhalten

Sei freundlich und respektvoll. Wir gehen von guten Absichten aus und halten die
Kommunikation sachlich und konstruktiv.
