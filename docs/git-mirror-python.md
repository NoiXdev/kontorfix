# Git-Mirror-Modus für Python

Python-Pakete können auf zwei Arten befüllt werden:

- **Publish (Push)** – der Standard. Versionen entstehen beim Upload
  (`twine upload`). Der Paketname ist nur ein reservierter Bezeichner; es wird
  kein Repository benötigt.
- **Git-Mirror** – die Versionen werden aus den **Tags** eines Git-Repositories
  gespiegelt, genau wie es Composer schon immer tut. Beim Anlegen (oder im Tab
  „Quelle“ eines Pakets) wird eine Repository-URL hinterlegt; jeder Sync
  importiert die Tags als Versionen.

Composer ist immer git-basiert und kennt diese Umschaltung nicht. npm ist immer
publish-basiert und kennt sie ebenfalls nicht mehr — siehe unten, warum.

## Anlegen

Unter **Pakete → Paket hinzufügen** erscheint für Python das Feld **Quelle**:

1. **Typ** auf **Python** stellen.
2. **Quelle** auf **Git-Mirror** stellen.
3. **Repository-URL** eintragen und über **Prüfen** die Erreichbarkeit testen
   (der Name wird aus dem Manifest übernommen).
4. Für **private Repositories** den Schalter „Privates Repository“ aktivieren
   und entweder einen gespeicherten Git-Token wählen oder einen Einmal-Token
   einfügen (siehe [private-github-repos.md](private-github-repos.md)).

Ein Git-Mirror-Paket akzeptiert **keine** Uploads mehr: `twine upload` wird
mit `409 Conflict` abgelehnt, weil der nächste Sync es überschreiben würde.

## Wie die Artefakte gebaut werden

Der Sync klont das Repository (Mirror) und erzeugt pro Version-Tag ein
Quell-Archiv mit `git archive` — dieselbe Technik wie beim Composer-Dist:

Das Artefakt ist ein sdist (`<name>-<version>.tar.gz`) mit einer `sha256`-Prüfsumme.

Python-sdists werden **beim Sync** gebaut, damit der PEP-503-Index sofort
korrekte Prüfsummen anbietet. Ein erneuter Sync baut ein Artefakt nur neu,
wenn der Tag auf einen anderen Commit verschoben wurde (Force-Push).

## Einschränkungen

- **Keine Build-/Prepare-Skripte.** Es wird der reine Quellbaum eines Tags
  archiviert. Pakete, die vor der Veröffentlichung kompiliert werden müssen
  (z. B. ein Wheel-Build), sind über **Publish** zu veröffentlichen.
- **Tags müssen als Version interpretierbar sein.** Tags, die nicht als
  Semver/Versionsnummer erkannt werden (z. B. `nightly`), werden übersprungen.
  Ein optionales führendes `v` wird entfernt (`v1.2.3` → `1.2.3`).
- **Python-Version** ist der Tag ohne führendes `v`.

## Warum npm den Git-Mirror-Modus nicht mehr hat

Ein Composer-Paket **ist** sein Quellbaum — deshalb funktioniert das Spiegeln von Tags
dort. Ein npm-Paket ist dagegen üblicherweise ein **gebautes Artefakt**: Was
`npm publish` hochlädt, ist nicht der Inhalt des Repositories. `files`, `.npmignore`,
ein `prepublishOnly`-Skript und ein `main`, das nach `dist/` zeigt, führen alle dazu,
dass das veröffentlichte Tarball eine abgeleitete Teilmenge ist.

Ein Spiegel des Repository-Baums liefert `npm install` daher in aller Regel etwas mit
dem richtigen Namen, der richtigen Version und dem falschen Inhalt. Das Paket wird ohne
Fehler synchronisiert und lässt sich anschließend nicht benutzen.

Es gibt Gegenbeispiele: Ein Paket ohne Build-Schritt und ohne `files`-Filterung
veröffentlicht praktisch seinen Quellbaum, und für das funktioniert ein Spiegel. Nur
sieht man einem Repository von außen nicht an, ob es dazugehört — und ein Modus, der
je nach Paket funktioniert oder stillschweigend Unbrauchbares ausliefert, ist schlimmer
als keiner. Deshalb ist npm hier publish-only: eine bewusste Entscheidung, keine
technische Unmöglichkeit.

Damit es funktionierte, müsste die Registry den Build des Pakets selbst ausführen —
`npm ci && npm run build && npm pack` — mit allen Fragen zu Sandboxing, Toolchain und
Cache, die daran hängen. Das ist ein eigenes Vorhaben, keine Fehlerbehebung.

Für Python bleibt der Modus erhalten: `pip` baut beim Installieren aus einer
Quelldistribution, ein Repository mit `pyproject.toml` ist also eine legitime Quelle.
