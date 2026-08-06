# Design plan: OCI / Docker container registry support

Status: **planning only** (no code yet). This is the pre-plan requested after shipping the
Python (PyPI) registry. It captures why Docker is a bigger lift than the package registries,
the data model, the endpoints, the auth handshake, and a phased rollout.

## Why Docker is different

Composer, npm and PyPI all serve small text metadata plus modest archive files, fit our
"one HTTP GET per artifact" model, and authenticate with a simple Basic/Bearer token. The
OCI Distribution Spec is a different shape:

- **Content-addressed blob store.** Layers and configs are stored and referenced by their
  `sha256:<digest>`. Blobs are deduplicated across tags and repositories and are often
  large (100s of MB per layer). They must be **streamed** to storage, never buffered in
  memory like the npm/Python uploads.
- **Chunked, resumable uploads.** `docker push` opens an upload session, may send the blob
  in chunks (`PATCH`), and finalizes with `PUT ...?digest=`. We must track upload sessions
  and verify the digest on finalize.
- **Manifests reference blobs by digest.** A manifest (image or multi-arch index) is itself
  a blob addressed by digest and also reachable by tag. Pushing an image = push blobs, then
  push the manifest.
- **A token handshake for `docker login`.** The Docker CLI expects the
  `WWW-Authenticate: Bearer realm="…",service="…",scope="…"` challenge and a separate token
  endpoint that returns a JWT. This is materially more than our current `registry.auth`.

## Data model

New tables (all UUID PKs, org/group-scoped like the rest):

- `oci_repositories` — `id, group_id, name` (e.g. `library/app`), timestamps.
  Unique `(group_id, name)`. This is the Docker analogue of a Package.
- `oci_blobs` — `id, group_id, digest (sha256:…), size, path, timestamps`.
  Unique `(group_id, digest)`. Content-addressed; shared across repositories in a group.
  `download_count`/`last_pulled_at` for stats.
- `oci_manifests` — `id, oci_repository_id, digest, media_type, content (json/text),
  timestamps`. Unique `(oci_repository_id, digest)`.
- `oci_tags` — `id, oci_repository_id, name, manifest_digest, timestamps`.
  Unique `(oci_repository_id, name)`. A tag points at a manifest digest.
- `oci_blob_uploads` — `id (the upload UUID), group_id, oci_repository_id, path (staging),
  bytes_received, created_at`. Ephemeral upload sessions; pruned on finalize/expiry.

Blobs live on the `artifacts` disk under `oci/{group_id}/blobs/sha256/{digest}`. **S3 backend
strongly recommended** for Docker because image volume dwarfs package registries.

## Endpoints (OCI Distribution Spec v2)

All under `/v2/` at the registry root (slug + custom-domain, like the existing registry
routes). `{name}` is the repository name and may contain slashes (`library/app`).

| Method | Path | Purpose |
|---|---|---|
| GET | `/v2/` | API version check → `200` + `Docker-Distribution-API-Version: registry/2.0` |
| HEAD/GET | `/v2/{name}/blobs/{digest}` | Blob exists / download (stream) |
| POST | `/v2/{name}/blobs/uploads/` | Start blob upload → `202` + `Location` with upload UUID |
| PATCH | `/v2/{name}/blobs/uploads/{uuid}` | Append a chunk |
| PUT | `/v2/{name}/blobs/uploads/{uuid}?digest=` | Finalize: verify digest, move into blob store |
| HEAD/GET | `/v2/{name}/manifests/{reference}` | Manifest by tag or digest |
| PUT | `/v2/{name}/manifests/{reference}` | Push a manifest (tag or digest) |
| DELETE | `/v2/{name}/manifests/{reference}` | Delete tag/manifest |
| GET | `/v2/{name}/tags/list` | List tags |
| GET | `/v2/_catalog` | List repositories (scoped to the group) |

Errors use the OCI error envelope (`{"errors":[{"code":"BLOB_UNKNOWN",…}]}`).

## Auth

Two phases:

1. **Basic auth (Phase 1).** Reuse the existing registry tokens as Basic credentials.
   `docker login` with a token works against many registries via Basic; simplest to ship.
   Read pulls / push publishes map onto the existing `canAccessGroup` / `canPublishToGroup`.
2. **Bearer token service (Phase 2).** Implement the challenge + a `/token` endpoint issuing a
   short-lived JWT with `scope=repository:{name}:pull,push`. This is what a stock `docker
   login` expects in production and enables per-repository scoping. Needs a signing key and
   scope parsing.

Per-org scoping mirrors the rest of the app: a repository belongs to a group → org; reads by
group access, writes by administered org. Fits the existing `ScopesApiToUser` philosophy.

## Pull-through proxy (Phase 3, optional)

Proxy/cache Docker Hub, GHCR, etc.: on a local miss, fetch the manifest/blobs from the
upstream (which itself needs the upstream's Bearer handshake), stream to the client, and
cache blobs by digest. Higher effort; defer until push/pull is solid.

## Effort & risks

- **Effort: large** — the biggest of the registry types. Chunked/streamed blob I/O, digest
  verification, upload-session lifecycle, and the token handshake are each non-trivial.
- **Storage volume** — images are big. Make the S3 driver a prerequisite; add blob **garbage
  collection** for unreferenced blobs (a scheduled command) from day one.
- **Streaming** — must never `file_get_contents` a layer. Use `readStream`/`writeStream` and
  hash incrementally while streaming to disk.
- **Integrity** — verify `sha256` on every blob finalize and on manifest push; reject on
  mismatch (like the PyPI `sha256_digest` check, but mandatory).

## Suggested phasing

1. **Phase 1 — push/pull, Basic auth, single registry.** Models + `/v2/` endpoints +
   streamed blob store + manifest/tag storage + `_catalog`/`tags/list`, scoped to the group.
   `docker pull`/`push` work with Basic credentials. Blob GC command.
2. **Phase 2 — `docker login` token service.** Bearer challenge + `/token` JWT + per-repo
   scopes; admin UI to create OCI repositories and view tags/sizes.
3. **Phase 3 — pull-through cache** for public upstreams.

Recommendation: build Phase 1 behind the same group/token model, S3-only, with GC, and treat
Phases 2–3 as follow-ups once real `docker push/pull` round-trips are proven.
