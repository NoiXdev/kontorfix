# Kontorfix

**Kontorfix ist eine selbst gehostete Registry für private Softwarepakete — mit Zugriffskontrolle, Mehrmandantenfähigkeit und Weboberfläche.**

![License: MIT](https://img.shields.io/badge/license-MIT-blue.svg)

## Was ist das?

Kontorfix bündelt private Pakete an einem Ort und stellt sie kontrolliert bereit —
gruppiert in **Registries** mit eigener Adresse, abgesichert über widerrufbare
Zugriffstokens. Teams veröffentlichen zentral, weisen Pakete einzelnen Kunden zu und
geben jedem Kunden seine eigene, abgeschottete Sicht. Alles ist über eine Weboberfläche
und eine vollständige Programmierschnittstelle steuerbar.

## Funktionen

- **Registries mit eigener Adresse** — jede Registry unter eigenem Pfad oder eigener Domain.
- **Feingranulare Zugriffskontrolle** — widerrufbare Tokens und persönliche Schlüssel,
  getrennt nach Lesen und Veröffentlichen.
- **Mehrmandantenfähigkeit** — Kunden erhalten eigene Registries und ein abgeschottetes Portal.
- **Mehrere Anmeldeoptionen** — Passwort mit Zwei-Faktor, Passkeys und Single-Sign-On.
- **Maschinenzugänge** — dedizierte Dienstkonten für Automatisierung und Integrationen.
- **Proxy & Zwischenspeicher** — öffentliche Pakete gespiegelt und lokal vorgehalten.
- **Programmierschnittstelle** — vollständige REST-Schnittstelle mit interaktiver Dokumentation.
- **Benachrichtigungen** — ausgehende Ereignis-Hooks für angebundene Systeme.
- **Live-Statusanzeige** — Betriebszustand und Aktivität auf einen Blick.
- **Flexibler Speicher** — lokal oder objektbasiert, frei konfigurierbar.

## Screenshots

> _Screenshots folgen._

## Selbst hosten

Kontorfix ist für den Eigenbetrieb gebaut und wird als Container ausgeliefert. Die
technische Einrichtung, die lokale Entwicklung und Hinweise zum sicheren Betrieb stehen
in der [Entwickler- und Betriebsdokumentation](docs/development.md).

## Sicherheit

Eine Schwachstelle gefunden? Bitte melde sie vertraulich — siehe [SECURITY.md](SECURITY.md).

## Mitwirken

Beiträge sind willkommen. Der Einstieg steht in [CONTRIBUTING.md](CONTRIBUTING.md).

## Lizenz

Kontorfix steht unter der MIT-Lizenz — siehe [LICENSE](LICENSE).
