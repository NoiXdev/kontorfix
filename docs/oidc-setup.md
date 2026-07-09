# OIDC / SSO einrichten (Betreiber-Doku)

Diese Anleitung beschreibt, wie ein Betreiber einen externen OpenID-Connect-Identity-Provider
(IdP) an Kontorfix anbindet, sodass Nutzer sich über „Mit {Provider} anmelden" per
Single Sign-on einloggen können. Die Doku ist intern/technisch und benennt den Stack
(Laravel 12, Inertia v2, Vue 3) bewusst.

## Überblick

Ein OIDC-Provider wird über das Model `App\Models\OidcProvider` konfiguriert (Tabelle
`oidc_providers`). Sobald ein Provider `enabled = true` ist, rendert die Login-Seite
(`resources/js/pages/auth/Login.vue`) automatisch einen „Mit {name} anmelden"-Button.
Der Button ist ein normaler Browser-GET auf die Route `oidc.redirect`
(`GET /auth/oidc/{slug}/redirect`), die die Authorization-Ceremony beim IdP anstößt.

## Provider-Felder

| Feld | Pflicht | Beschreibung |
| --- | --- | --- |
| `name` | ja | Anzeigename, erscheint im Button („Mit {name} anmelden"). |
| `slug` | ja | URL-sicherer Bezeichner, Teil der Redirect-/Callback-URLs (z. B. `keycloak`). |
| `client_id` | ja | Vom IdP vergebene Client-ID der registrierten Anwendung. |
| `client_secret` | ja | Client-Secret des IdP. Wird verschlüsselt gespeichert (`encrypted` cast) und ist von der Serialisierung ausgeschlossen. |
| `issuer` | ja | Issuer-URL des IdP (z. B. `https://idp.example.com/realms/kontor`). Basis für Discovery und `iss`-Prüfung. |
| `authorization_endpoint` | optional* | Authorization-Endpoint. |
| `token_endpoint` | optional* | Token-Endpoint. |
| `userinfo_endpoint` | optional* | UserInfo-Endpoint. |
| `jwks_uri` | optional* | JWKS-Endpoint für die Signaturschlüssel. |
| `scopes` | optional | Angeforderte Scopes (Default enthält `openid`; üblich `openid email profile`). |
| `enabled` | ja | Nur `true` blendet den Provider auf der Login-Seite ein. |
| `allow_registration` | ja | Opt-in für Auto-Provisioning (siehe Sicherheitsmodell). |
| `default_organization_id` | bei `allow_registration` | Organisation, in der neu provisionierte Nutzer angelegt werden. |
| `default_role` | bei `allow_registration` | Rolle (`UserRole`), die neu provisionierte Nutzer erhalten. |

\* Die vier Endpunkte lassen sich manuell eintragen **oder** per Discovery aus dem
`issuer` automatisch befüllen (siehe unten). Für Produktivbetrieb ist Discovery empfohlen.

## Discovery nutzen (empfohlen)

1. Nur `issuer` eintragen (z. B. `https://idp.example.com/realms/kontor`).
2. Button **„Aus Discovery laden"** klicken. Kontorfix ruft
   `{issuer}/.well-known/openid-configuration` ab und übernimmt
   `authorization_endpoint`, `token_endpoint`, `userinfo_endpoint` und `jwks_uri`.
3. Werte prüfen und speichern.

Die Discovery-, Token- und JWKS-Requests sind SSRF-geschützt (siehe Sicherheitsmodell).

## redirect_uri beim IdP eintragen

Beim IdP muss als erlaubte Redirect-/Callback-URL exakt Folgendes hinterlegt werden:

```
{app.url}/auth/oidc/{slug}/callback
```

Beispiel — App unter `https://registry.example.com`, Provider-`slug` = `keycloak`:

```
https://registry.example.com/auth/oidc/keycloak/callback
```

`{app.url}` entspricht der Laravel-Konfiguration `config('app.url')` bzw. `APP_URL`.
Die Route ist `oidc.callback` (`GET /auth/oidc/{slug}/callback`).

## Sicherheitsmodell

- **Authorization-Code-Flow mit PKCE (S256):** Kontorfix nutzt den Authorization-Code-Flow;
  der Code-Austausch ist über PKCE mit `code_challenge_method=S256` gegen Interception
  abgesichert.
- **state (CSRF):** Ein zufälliger `state`-Parameter wird beim Redirect gesetzt und beim
  Callback verifiziert, um CSRF auf den Login-Flow zu verhindern.
- **nonce (Replay):** Ein `nonce` wird im Authorization-Request mitgeschickt und gegen den
  `nonce`-Claim des `id_token` geprüft, um Replay auszuschließen.
- **id_token-Signaturprüfung:** Das `id_token` wird per JWKS (RS256) signaturverifiziert.
  Zusätzlich werden die Claims `iss` (== `issuer`), `aud` (== `client_id`), `exp`
  (Ablauf) und `nonce` geprüft.
- **SSRF-Schutz:** Ausgehende Requests auf Discovery-, Token- und JWKS-Endpunkte sind gegen
  SSRF abgesichert (keine internen/privaten Ziele).
- **Verknüpfung nur über verifizierte E-Mail:** Eine OIDC-Identität wird nur dann mit einem
  bestehenden Konto verknüpft, wenn der IdP die E-Mail als verifiziert liefert. Damit ist
  kein Account-Takeover über nicht verifizierte E-Mail-Adressen möglich.
- **Auto-Provisioning ist opt-in pro Provider:** Nur wenn `allow_registration = true`, legt
  ein erfolgreicher Login ohne bestehendes Konto automatisch einen neuen Nutzer an — in der
  konfigurierten `default_organization_id` mit `default_role`. Ist das Flag `false`, wird
  ein unbekannter Nutzer abgewiesen statt provisioniert.
- **Föderierte MFA — OIDC ersetzt den TOTP-Schritt:** Ein erfolgreicher OIDC-Login ersetzt
  den lokalen TOTP-Zweitfaktor. Der IdP verantwortet in diesem Fall den zweiten Faktor
  (föderierte MFA), konsistent zum Verhalten von Passkey-Logins.

## Login-Seite

Der Controller `app/Http/Controllers/Auth/AuthenticatedSessionController@create` liefert die
aktivierten Provider als Prop `oidcProviders` (`{ slug, name }`) an die Vue-Seite. Bei leerer
Liste wird kein SSO-Block und kein „oder"-Trenner gerendert. Der Button navigiert per echtem
`<a href>` (nativer GET) auf `oidc.redirect`, damit der externe IdP-Redirect nicht als
Inertia-XHR gefangen wird.
