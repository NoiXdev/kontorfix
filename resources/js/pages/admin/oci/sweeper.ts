/**
 * The German copy for the storage sweeper view (plate 6). A tested `.ts` module per the
 * house rule — and one figure on this page carries a claim the sentence has to make true:
 * the grace-held count is the visible evidence the safety mechanism works, and a bare
 * number with no caption reads as a warning instead.
 */

/** What the sweeper does — and the one thing it never does. */
export const SWEEPER_EXPLANATION =
    'Die Speicherbereinigung entfernt, was kein Tag mehr erreicht: verwaiste Manifeste und Blobs, abgelaufene ' +
    'Upload-Sessions und leere, beim Push angelegte Repositories. Regeln kennt sie nicht — welche Tags leben, ' +
    'entscheiden allein die Aufbewahrungsrichtlinien.';

/**
 * The caption for the grace-held figure. It has to say that a non-zero number is the
 * mechanism WORKING: a push lädt erst alle Schichten hoch und schreibt das Manifest
 * zuletzt — dazwischen sähe jede frische Schicht wie Müll aus.
 */
export const GRACE_HELD_CAPTION =
    'Von der Schonfrist gehalten: unreferenzierte Blobs, die dafür noch zu jung sind. Eine Zahl größer null ist ' +
    'kein Fehler, sondern der Beleg, dass laufende Pushes geschützt sind — ihre Schichten sind genau solche Blobs, ' +
    'bis das Manifest geschrieben ist.';

/** The confirmation dialog's body. */
export const SWEEP_CONFIRMATION =
    'Die Bereinigung läuft im Hintergrund und entfernt nur, was unerreichbar und älter als die Schonfrist ist. ' +
    'Ein erreichbares Image ist davon nie betroffen.';
