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

## Troubleshooting

- **"Zugriff verweigert — Repository privat?"** on *Prüfen*: the token is missing,
  wrong, expired, or lacks *Contents: Read* on that repo.
- **SSH URL**: tokens are ignored for `ssh://` remotes — use a deploy key on the host.
- **Sync shows "failed"** after previously working: the token likely expired or was
  revoked — rotate it (step above).
