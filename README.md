# Kontorfix

**Kontorfix is a self-hosted registry for private software packages — with access control, multi-tenancy and a web interface.**

![License: MIT](https://img.shields.io/badge/license-MIT-blue.svg)

## What is it?

Kontorfix gathers private packages in one place and serves them under tight control —
grouped into **registries** with their own address, secured by revocable access tokens.
Teams publish centrally, assign packages to individual customers, and give each customer
their own isolated view. Everything is manageable through a web interface and a complete
programming interface.

## Features

- **Registries with their own address** — each registry under its own path or domain.
- **Fine-grained access control** — revocable tokens and personal keys, separated into
  read and publish.
- **Multi-tenancy** — customers get their own registries and an isolated portal.
- **Multiple sign-in options** — password with two-factor, passkeys and single sign-on.
- **Machine access** — dedicated service accounts for automation and integrations.
- **Proxy & cache** — public packages mirrored and kept locally.
- **Programming interface** — a complete REST interface with interactive documentation.
- **Notifications** — outgoing event hooks for connected systems.
- **Live status view** — operational state and activity at a glance.
- **Flexible storage** — local or object-based, freely configurable.

## Screenshots

> _Screenshots coming soon._

## Self-hosting

Kontorfix is built to run on your own infrastructure and is shipped as a container. The
technical setup, local development and secure-operation notes live in the
[developer and operations documentation](docs/development.md).

## Automation & REST API

Everything the admin UI does is available through a versioned REST API (OpenAPI schema
at `/docs/api`). See the [REST API & automation guide](docs/rest-api.md) for
authentication, endpoints and copy-paste examples.

## Security

Found a vulnerability? Please report it privately — see [SECURITY.md](SECURITY.md).

## Contributing

Contributions are welcome. Get started in [CONTRIBUTING.md](CONTRIBUTING.md).

## License

Kontorfix is released under the MIT License — see [LICENSE](LICENSE).
