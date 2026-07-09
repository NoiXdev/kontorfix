# Passkeys – manueller Smoke-Test

Die WebAuthn-Zeremonie (Registrierung/Anmeldung) lässt sich nicht ohne echten bzw.
virtuellen Authenticator automatisiert testen — die Krypto verantwortet `laravel/passkeys`
(+ `web-auth/webauthn-lib`) mit deren Upstream-Tests. Die automatisierten Pest-Tests decken
Verdrahtung, Autorisierung (besitzer-scopes Löschen, Passwort-Bestätigung) und UI-Props ab.

Der vollständige End-to-End-Flow wird manuell verifiziert:

## Voraussetzungen
- Aufruf über **HTTPS** oder **localhost** — WebAuthn verweigert unsichere Origins.
  Bei DDEV: die `https://<projekt>.ddev.site`-URL nutzen.
- `config/passkeys.php`: `relying_party_id` und `allowed_origins` müssen zur aufgerufenen
  Domain passen (Default = Host bzw. Wert aus `app.url`). Bei abweichender Test-Domain
  entsprechend setzen.
- Ein Authenticator: Touch ID / Windows Hello / ein Sicherheitsschlüssel, oder der
  **virtuelle Authenticator** der Chrome DevTools (More Tools → WebAuthn → Enable virtual
  authenticator environment).

## Ablauf
1. Mit E-Mail/Passwort einloggen.
2. Settings → **Passkeys**. Ggf. wird zunächst die Passwort-Bestätigung verlangt.
3. Namen eingeben, **Passkey hinzufügen** → Authenticator bestätigen. Der Passkey erscheint
   in der Liste (Authenticator-Label, Erstellungsdatum).
4. Ausloggen.
5. Auf der Login-Seite **Mit Passkey anmelden** → Authenticator bestätigen → man ist
   eingeloggt und wird auf das Ziel (Dashboard bzw. Kunden-Portal) weitergeleitet.
6. Zurück in Settings → Passkeys den Passkey **entfernen** und prüfen, dass die
   passwortlose Anmeldung damit nicht mehr möglich ist.

## Erwartetes Verhalten
- Registrierung/Löschen nur nach Passwort-Bestätigung (management_middleware).
- Ein Passkey ist nur vom besitzenden Nutzer löschbar (403 sonst).
- Passkeys gelten für den App-Login, nicht für die Composer/npm-Registry-Endpunkte.

## Sicherheitsentscheidung: Passkey vs. erzwungene 2FA (TOTP)
Passkeys verlangen hier zwingend **User-Verification** (Biometrie/PIN;
`USER_VERIFICATION_REQUIREMENT_REQUIRED` in der Lib) — ein Passkey ist damit selbst ein
phishing-resistenter **Mehr-Faktor**-Nachweis (Besitz des Authenticators + Verifikation).

Bewusste Entscheidung: **Ein Passkey-Login ersetzt den TOTP-Schritt und ist auch dann
erlaubt, wenn der Nutzer 2FA aktiviert hat** (FIDO2-Standard). Das ist absichtlich als
explizite Policy verdrahtet — `Passkeys::authorizeLoginUsing(...)` in
`app/Providers/AppServiceProvider.php` — und per Test festgeschrieben
(`tests/Feature/Auth/PasskeyTwoFactorPolicyTest.php`), damit der Verzicht auf den TOTP-Schritt
keine stille Default-Annahme ist.

Um 2FA stattdessen zu erzwingen (Passkey bei aktiver 2FA blockieren), im Callback
`return ! $user->hasConfirmedTwoFactor();` — dann muss der Nutzer den regulären
Passwort-+-TOTP-Flow gehen.
