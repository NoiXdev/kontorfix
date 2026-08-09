# Syncing private Git repositories (GitHub & co.)

Composer packages are synced by cloning their Git repository. For a **private**
repository that clone needs credentials. Kontorfix supports this with a per-package
**access token** that is injected as an HTTPS auth header at sync time.

## 1. Create a token

### GitHub (recommended: fine-grained PAT)

1. GitHub → **Settings → Developer settings → Personal access tokens → Fine-grained tokens → Generate new token**.
2. **Resource owner**: the user/org that owns the repo.
3. **Repository access**: *Only select repositories* → pick the repo(s) you want to sync.
4. **Permissions → Repository permissions → Contents**: **Read-only**.
5. Generate and copy the token (`github_pat_…`). It is shown only once.

A classic PAT with the `repo` scope also works, but grants far more than needed —
prefer the fine-grained token scoped to *Contents: Read-only*.

### GitLab / Bitbucket / other hosts

Any host that accepts HTTP Basic auth with the token as the password works:
- **GitLab**: a project *Deploy token* or a PAT with `read_repository`.
- **Bitbucket**: an *App password* with *Repositories: Read*.

## 2. Store the token — inline or as a reusable credential

There are two ways to provide the token:

- **Reusable credential (recommended for more than one repo)**: admin console →
  **Git-Tokens** → *Token hinterlegen*. Pick the **Provider** (GitHub / GitLab /
  Bitbucket / generic — this sets the correct auth username), optionally a username,
  and paste the token. Use **Testen** with a repository URL to confirm it works. The
  credential is organization-scoped and can be assigned to many packages. When adding
  a package you then just pick it under *Gespeicherter Token*.
- **Inline (quick, one-off)**: paste the token directly into the package's
  *Zugriffs-Token* field (treated as a GitHub token).

An assigned credential always takes precedence over an inline token.

## 3. Add the package with its token

In the admin console → **Pakete → Paket hinzufügen** (or the quick-add on a registry's
*Pakete* tab):

1. **Typ**: `composer` (or `npm`).
2. **Repository-URL**: the **HTTPS** clone URL, e.g. `https://github.com/acme/private.git`.
   (Token auth only applies to HTTPS. For SSH URLs use a deploy key on the server instead.)
3. **Gespeicherter Token**: pick a reusable credential, **or** paste a one-off token
   into *Zugriffs-Token*.
4. Click **Prüfen** — Kontorfix uses the token to reach the repo and preview its
   name and versions. A green "Repository erreichbar" confirms the token works.
5. **Anlegen** — the package is created and synced with the token.

## 4. How it is stored and used

- The token is **encrypted at rest** and never returned to the browser or API
  (it is write-only from the UI's perspective).
- At sync time it is sent as an `Authorization: Basic …` header via git's
  environment config — it is **not** written into the repository URL, the mirror's
  `.git/config`, or the process arguments, and it is scrubbed from any error output.
- To rotate: edit the package (re-enter a new token) or delete and recreate it.
  Revoke the old token at the provider.

- A credential is **bound to one host** and its token is never sent anywhere else. For
  GitHub/GitLab/Bitbucket that host defaults to the provider's own; a self-hosted GitLab
  or GitHub Enterprise credential must name its host explicitly. Changing the host
  requires re-entering the token — only someone who already holds the secret may decide
  where it is sent.
- The binding is the whole **authority, port included**. `gitlab.corp` matches
  `https://gitlab.corp/…` and `https://gitlab.corp:443/…` but not `https://gitlab.corp:9999/…`,
  because the `Authorization` header git receives is scoped to `scheme://host:port` and a
  different port on the same machine is a different service. A git server on a non-default
  port is named with it: `gitlab.corp:8443`.

> **Upgrading:** if a package's repository URL carries an explicit non-default port, edit
> the credential to name that port and re-enter the token, or its syncs will refuse with
> *"Dieses Git-Token ist an … gebunden"*.

### Upgrading an instance that predates the host binding

The migration binds every existing credential to its provider's canonical host. It does
**not** derive a host from a package's repository URL: that URL was operator-supplied and
would let a maintainer nominate the host their organization's token gets sent to. Any
credential whose assigned packages point somewhere other than the canonical host — a
self-hosted GitLab or GHE, typically — is therefore left with an empty `host` column and
logged with a `Git credential left without a host binding` warning.

Such a credential is **unusable** until an operator names its host. `allowedHost()` returns
null for an empty column and `permits()` refuses everything, whatever the provider — it used
to fall back to the provider's canonical host, which quietly bound a self-hosted PAT to
`github.com` / `gitlab.com` / `bitbucket.org` and transmitted it there, where it is useless
but disclosed. Nothing entered through the console is affected: the form writes the provider
default into the column when the operator names no host, so an empty column is exactly the
set of rows the migration refused to decide for.

The fix is the same as before: edit the credential, name the real host in the *Host* field
(with a port if the server uses a non-default one, e.g. `gitlab.example:8443`), and re-enter
the token — the retarget guard asks for it because only someone who holds the secret may
decide where it is sent.

## Troubleshooting

- **"Zugriff verweigert — Repository privat?"** on *Prüfen*: the token is missing,
  wrong, expired, or lacks *Contents: Read* on that repo.
- **SSH URL**: tokens are ignored for `ssh://` remotes — use a deploy key on the host.
- **Sync shows "failed"** after previously working: the token likely expired or was
  revoked — rotate it (step above).
