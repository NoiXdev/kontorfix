# REST API & automation

Kontorfix ships a versioned, JSON REST API under `/api/v1` for automating everything
the operator GUI can do: managing packages, registries (groups), domains, upstreams,
registry tokens, webhooks, organizations, users and robots.

## OpenAPI schema

The API is documented with an OpenAPI 3 schema, generated from the code by
[Scramble](https://scramble.dedoc.co):

| Resource | URL | Notes |
| --- | --- | --- |
| Interactive docs | `GET /docs/api` | Browsable reference (rendered in the browser). |
| Raw OpenAPI JSON | `GET /docs/api.json` | Feed this to a client generator or Postman/Insomnia. |

Access to the docs routes is restricted to **admins of the operator organization** (the
same people who may use the management API). In `local` the docs are open for
convenience.

Generate a typed client straight from the schema, e.g.:

```bash
# OpenAPI Generator (any language)
openapi-generator-cli generate \
  -i https://registry.example.com/docs/api.json \
  -g python \
  -o ./kontorfix-client
```

## Authentication

All `/api/v1` endpoints are **stateless** and authenticated with a personal **API key**
sent as a Bearer token:

```
Authorization: Bearer kfxapi_xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx
```

- API keys are issued to a user (typically a **robot** account — see the Robots admin
  page) and belong to that user's organization and role. The key inherits exactly the
  permissions of its owner, so the same operator/role gates as the GUI apply.
- Keys carry a **permission**: `read` keys may only call safe methods (`GET`/`HEAD`);
  `write` keys may also `POST`/`PUT`/`DELETE`. A write attempt with a read key returns
  `403`.
- Keys are shown **once** at creation. Store them securely; a lost key is regenerated,
  not recovered.

> Do not confuse API keys (`kfxapi_…`, for this management API) with **registry tokens**
> (`kfx_…`), which authenticate Composer/npm clients against the package registry
> itself. The API can create and revoke registry tokens, but you authenticate to the API
> with an API key.

### Issuing a key

- **In the GUI:** *Verwaltung → Robots* → create a robot → *Key ausstellen*.
- **Via the API** (operator-admin only), for any user:
  `POST /api/v1/users/{user}/api-keys`.
- **For yourself:** `POST /api/v1/me/api-keys`.

## Rate limits

Two tiers protect the API:

- **Per IP:** 240 requests/minute across the unauthenticated surface (before key lookup).
- **Per key:** 120 requests/minute. Exceeding it returns `429` with a `Retry-After`
  header.

## Conventions

- Send `Accept: application/json`. Validation errors come back as `422` with an
  `errors` object keyed by field — identical to the GUI's form validation.
- Bodies are JSON (`Content-Type: application/json`).
- IDs are UUIDs.

## Endpoint overview

Everything below is under `https://<host>/api/v1`.

| Area | Endpoints |
| --- | --- |
| Identity | `GET me`, `GET/POST/DELETE me/api-keys` |
| Packages | `GET/POST packages`, `GET/DELETE packages/{package}`, `POST packages/{package}/resync` |
| Registries | `GET/POST groups`, `GET/PUT/DELETE groups/{group}` |
| Registry sub-resources | `…/domains`, `…/upstreams`, `PUT …/packages` (assignment) |
| Registry tokens | `GET/POST registry-tokens`, `DELETE registry-tokens/{token}` |
| Webhooks | `GET/POST webhooks`, `DELETE webhooks/{webhook}` |
| Status | `GET status` |
| Organizations¹ | `GET/POST organizations`, `GET/DELETE organizations/{organization}` |
| Users & robots¹ | `GET/POST users`, `PUT/DELETE users/{user}`, `POST users/{user}/api-keys` |

¹ Organization/user management requires an **operator admin** key (not maintainer).
Package/registry/webhook management requires an operator **admin or maintainer** key.

## Automation examples

Set your key once:

```bash
export KFX="https://registry.example.com/api/v1"
export KEY="kfxapi_xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx"
auth=(-H "Authorization: Bearer $KEY" -H "Accept: application/json")
```

**List packages**

```bash
curl -s "${auth[@]}" "$KFX/packages"
```

**Add a package and trigger its first sync** (write key)

```bash
curl -s "${auth[@]}" -H "Content-Type: application/json" \
  -X POST "$KFX/packages" \
  -d '{"type":"composer","name":"acme/tools","repository_url":"https://github.com/acme/tools.git"}'
```

Both `https://` and `ssh://` repository URLs are accepted; SSH remotes require a deploy
key configured on the server.

**Re-sync a package after a push** (e.g. from CI)

```bash
curl -s "${auth[@]}" -X POST "$KFX/packages/$PACKAGE_ID/resync"
```

**Create a registry (group) and assign packages**

```bash
group=$(curl -s "${auth[@]}" -H "Content-Type: application/json" \
  -X POST "$KFX/groups" \
  -d '{"name":"Internal","slug":"internal","public":false}')
gid=$(echo "$group" | jq -r '.id // .data.id')

curl -s "${auth[@]}" -H "Content-Type: application/json" \
  -X PUT "$KFX/groups/$gid/packages" \
  -d "{\"package_ids\":[\"$PACKAGE_ID\"]}"
```

**Issue a registry (pull) token for a Composer client**

```bash
curl -s "${auth[@]}" -H "Content-Type: application/json" \
  -X POST "$KFX/registry-tokens" \
  -d "{\"name\":\"ci\",\"group_id\":\"$gid\",\"ability\":\"read\"}"
```

**Check instance health for monitoring**

```bash
curl -s "${auth[@]}" "$KFX/status"
```

## Triggering syncs from your git host

Beyond the API, pushes can trigger a package re-sync automatically via **incoming
webhooks**. Create one per git host/repository under *Webhooks → Eingehend*; each gets
its own URL and secret to configure as a webhook on GitHub, GitLab, Gitea or Bitbucket.
Deliveries (including failures, with payloads) are visible under *Webhooks → Audit* for
debugging.
