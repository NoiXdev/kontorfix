# Contributing

Thanks for your interest in Kontorfix! This short guide helps you get started.

## Setup

The local environment is based on DDEV. The full setup is described in the
[developer and operations documentation](docs/development.md):

```bash
ddev start
ddev composer install
ddev exec npm install
ddev exec php artisan key:generate
ddev exec php artisan migrate --seed
```

## Before a pull request

All checks must pass:

```bash
ddev exec vendor/bin/pest              # Tests
ddev exec vendor/bin/pint --test       # Code style
ddev exec vendor/bin/phpstan analyse   # Static analysis
ddev exec npm run lint                 # ESLint
ddev exec npm run build                # Frontend build
```

New logic comes with tests (Pest). Bug fixes get a regression test.

## Conventions

- **Commits:** [Conventional Commits](https://www.conventionalcommits.org/)
  (`feat`, `fix`, `docs`, `refactor`, `perf`, `test`, `build`, `ci`, `chore`).
  This is enforced by CI.
- **Branches:** feature branches, no direct work on `main`.
- **Pull requests:** small and focused; describe the motivation and the impact.
  Please flag security-relevant changes in the PR.

## Security

Please do **not** report vulnerabilities through public issues — report them privately
instead, see [SECURITY.md](SECURITY.md).

## Conduct

Be kind and respectful. We assume good intent and keep communication factual and
constructive.
