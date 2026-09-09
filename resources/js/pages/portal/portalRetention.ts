/**
 * The German copy for the portal's retention section — what a customer is told about why
 * tags disappear from their repository. A tested `.ts` module per the house rule.
 *
 * The audience differs from the admin's: the customer cannot change the rules, so the copy
 * has to answer "why did my tag vanish" and "what do I do about a tag I still need" —
 * anything else is the operator's surface.
 */

/** The section's framing: who decides, and where the rules come from. */
export const PORTAL_RETENTION_EXPLANATION =
    'Für dieses Repository gilt eine Aufbewahrungsrichtlinie. Ältere Tags werden nach den folgenden Regeln ' +
    'automatisch entfernt; die Regeln legt der Betreiber der Registry fest.';

/** The empty state — rendered, never omitted: an absent section reads as a failed load. */
export const PORTAL_NO_RETENTION =
    'Für dieses Repository gilt keine Aufbewahrungsrichtlinie — es wird nichts automatisch entfernt.';

/**
 * The one sentence that makes the preview worth showing rather than merely honest: what to
 * do about a tag on the list that is still needed.
 */
export const PORTAL_RETENTION_ADVICE =
    'Wird ein aufgeführter Tag noch benötigt, ziehen Sie das Image vor dem nächsten Lauf oder vergeben Sie einen ' +
    'neuen Tag — ein frisch gepushter Tag fällt nicht unter die Entfernung.';

/** Above the removals table. */
export const PORTAL_UPCOMING_REMOVALS = 'Beim nächsten Lauf werden voraussichtlich entfernt:';

/** The happy empty state under an active policy. */
export const PORTAL_NO_REMOVALS = 'Der nächste Lauf entfernt nach aktuellem Stand nichts.';
