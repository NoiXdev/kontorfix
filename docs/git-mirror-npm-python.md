# Git-Mirror-Modus für npm & Python

npm- und Python-Pakete können auf zwei Arten befüllt werden:

- **Publish (Push)** – der Standard. Versionen entstehen beim Upload
  (`npm publish` bzw. `twine upload`). Der Paketname ist nur ein reservierter
  Bezeichner; es wird kein Repository benötigt.
- **Git-Mirror** – die Versionen werden aus den **Tags** eines Git-Repositories
  gespiegelt, genau wie es Composer schon immer tut. Beim Anlegen (oder im Tab
  „Quelle" eines Pakets) wird eine Repository-URL hinterlegt; jeder Sync
  importiert die Tags als Versionen.

Composer ist immer git-basiert und kennt diese Umschaltung nicht.

## Anlegen

Unter **Pakete → Paket hinzufügen** erscheint für npm/Python das Feld **Quelle**:

1. **Typ** wählen (npm oder python).
2. **Quelle** auf **Git-Mirror** stellen.
3. **Repository-URL** eintragen und über **Prüfen** die Erreichbarkeit testen
   (der Name wird aus dem Manifest übernommen).
4. Für **private Repositories** den Schalter „Privates Repository" aktivieren
   und entweder einen gespeicherten Git-Token wählen oder einen Einmal-Token
   einfügen (siehe [private-github-repos.md](private-github-repos.md)).

Ein Git-Mirror-Paket akzeptiert **keine** Uploads mehr: `npm publish` bzw.
`twine upload` dagegen werden mit `409 Conflict` abgelehnt, weil der nächste
Sync sie überschreiben würde.

## Wie die Artefakte gebaut werden

Der Sync klont das Repository (Mirror) und erzeugt pro Version-Tag ein
Quell-Archiv mit `git archive` — dieselbe Technik wie beim Composer-Dist:

| Typ    | Artefakt                          | Prüfsumme                     |
| ------ | --------------------------------- | ----------------------------- |
| npm    | `…-<version>.tgz` (Root `package/`) | `shasum` (sha1) + `integrity` (sha512) |
| Python | `<name>-<version>.tar.gz` (sdist)   | `sha256`                      |

npm-Tarballs und Python-sdists werden **beim Sync** gebaut, damit Packument bzw.
PEP-503-Index sofort korrekte Prüfsummen anbieten. Ein erneuter Sync baut ein
Artefakt nur neu, wenn der Tag auf einen anderen Commit verschoben wurde
(Force-Push).

## Einschränkungen

- **Keine Build-/Prepare-Skripte.** Es wird der reine Quellbaum eines Tags
  archiviert. Pakete, die vor der Veröffentlichung kompiliert werden müssen
  (z. B. TypeScript → JavaScript, ein `prepare`-Skript oder ein Wheel-Build),
  sind über **Publish** zu veröffentlichen.
- **Tags müssen als Version interpretierbar sein.** Tags, die nicht als
  Semver/Versionsnummer erkannt werden (z. B. `nightly`), werden übersprungen.
  Ein optionales führendes `v` wird entfernt (`v1.2.3` → `1.2.3`).
- **npm-Version** stammt aus dem `version`-Feld der `package.json` des Tags
  (ersatzweise aus dem Tag). **Python-Version** ist der Tag ohne führendes `v`.
