/**
 * The German copy for the retention policy surfaces — the editor (plate 4), its preview
 * panel, and the dry-run report (plate 5).
 *
 * A `.ts` module with a test, per the rule `dockerSetup.ts` and `ociAutoCreate.ts` record:
 * this frontend has no component runner, so a sentence left inside a `.vue` file is a
 * sentence nothing verifies. These particular sentences carry the feature's two safety
 * claims (OR-combination, the shield as a veto), which the evaluator enforces mechanically —
 * copy that described them wrongly would teach the operator a model the code refuses.
 */

/** Why the keep-rules are OR'd, stated where they are added. */
export const KEEP_RULES_OR =
    'Diese Regeln sind mit ODER verknüpft: Ein Tag bleibt erhalten, wenn mindestens eine Regel ihn behält. ' +
    'Eine zusätzliche Regel entfernt daher nie mehr, sondern höchstens weniger.';

/**
 * Why "Nie löschen" sits apart from the keep-rules, and what it does NOT do. Mirrors
 * RetentionEvaluator's two pinned properties: a shield-only policy is inert, and a shield
 * never consumes a "Letzte N behalten"-slot.
 */
export const SHIELD_EXPLANATION =
    'Ein Schutz ist keine Behalte-Regel, sondern ein Veto: Passende Tags werden von keiner Regel entfernt ' +
    'und zählen auch nicht gegen „Letzte N behalten“. Ohne mindestens eine Behalte-Regel entfernt diese ' +
    'Richtlinie nichts.';

/** The one wildcard the pattern fields promise — Str::is() on the server delivers exactly this. */
export const PATTERN_HELP = 'Muster mit * als Platzhalter, z. B. v* oder release-*.';

/** The sentence that keeps the dry run and every apply-button from being read as a lie. */
export const NO_SPACE_FREED_YET =
    'Das Anwenden entfernt Tags. Speicherplatz wird erst von der Speicherbereinigung freigegeben, ' +
    'nach Ablauf der Schonfrist.';

/** The dry run's promise: every surviving tag names the rule that saved it. */
export const DRY_RUN_EXPLANATION =
    'Der Probelauf entfernt nichts. Für jeden Tag steht dabei, welche Regel ihn behält — ' +
    'ein Bericht, der nur eine Anzahl nennt, lässt sich nicht prüfen, nur glauben.';

/**
 * States the exclusion RetentionRunner::packagesFor() already enforces (a `WHERE
 * retention_rules IS NULL`): a policy's dry run — and its real run — never lists a package
 * with its own inline rules, however that package's `retention_policy_id` reads, because
 * inline rules outrank every policy at tier 0. Without this sentence the governed count on
 * plate 5 reads as complete when it silently excludes those repositories.
 */
export const DRY_RUN_EXCLUDES_INLINE_NOTE =
    'Repositories mit eigenen Regeln gehören nicht zum Geltungsbereich dieser Richtlinie: Eigene Regeln gehen jeder Richtlinie vor ' +
    'und werden hier — auch im echten Lauf — nie mitgezählt.';
