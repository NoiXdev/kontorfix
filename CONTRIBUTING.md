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
git config core.hooksPath .githooks     # enable the local commit-message check
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
  Enforced by CI, and locally by a `commit-msg` hook once `core.hooksPath` is set
  (see [Setup](#setup)). The hook reports an invalid message while it can still be
  edited; once a message has reached `main` it cannot be corrected without rewriting
  public history.
- **Branches:** feature branches, no direct work on `main`.
- **Pull requests:** small and focused; describe the motivation and the impact.
  Please flag security-relevant changes in the PR.

## Security

Please do **not** report vulnerabilities through public issues — report them privately
instead, see [SECURITY.md](SECURITY.md).

## Conduct

Be kind and respectful. We assume good intent and keep communication factual and
constructive.
