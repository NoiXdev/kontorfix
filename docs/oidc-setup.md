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
| `trusts_email_claim` | ja | Opt-in dafür, dass dieser Provider ein **bestehendes** Konto allein über die zugesicherte E-Mail-Adresse beanspruchen darf (siehe Sicherheitsmodell). Default `false`. |
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
- **Verknüpfung mit bestehenden Konten ist doppelt abgesichert:** Eine OIDC-Identität wird
  nur dann automatisch mit einem bestehenden Konto verknüpft, wenn (1) der IdP die E-Mail
  als verifiziert liefert **und** (2) der Provider `trusts_email_claim = true` gesetzt hat.
  Bedingung (1) allein genügt nicht: `email_verified` ist eine Zusicherung des IdP, und ein
  zweiter, weniger vertrauenswürdiger Provider könnte sie für eine fremde Adresse setzen und
  so das Konto übernehmen. Bedingung (2) entscheidet also, **welchen** IdPs man diese
  Zusicherung abnimmt. Ist der Provider nicht als vertrauenswürdig markiert und existiert
  bereits ein Konto zu der Adresse, wird der Login mit einem Hinweis abgewiesen — das Konto
  lässt sich stattdessen gezielt im angemeldeten Zustand verknüpfen.
- **Privilegierte Konten werden nie automatisch verknüpft:** Unabhängig von
  `trusts_email_claim` lehnt der Resolver die automatische Verknüpfung ab, sobald das
  Zielkonto privilegiert ist (Super-Admin-Flag, Rolle in der Heimat-Organisation o. Ä.).
- **Beim Upgrade wurden bestehende Provider auf `trusts_email_claim = true` gesetzt:** Sonst
  hätte die Migration auf reinen SSO-Instanzen alle Anmeldungen blockiert. Die Migration
  protokolliert die betroffenen Provider als Warnung. Prüfen Sie nach dem Upgrade, welche
  davon die Zusicherung wirklich verdienen, und schalten Sie den Rest in der Provider-Liste
  über die Schaltfläche neben dem Badge ab.
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
