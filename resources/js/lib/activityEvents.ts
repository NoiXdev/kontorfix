/**
 * Presentation of a Spatie activity `event`.
 *
 * Spatie stores the event in English (`created`, `updated`, `deleted`). Both the timeline
 * and the detail dialog show it, so the wording and the colour live here rather than in
 * either component — otherwise the two drift apart the first time a label is reworded.
 *
 * An event nobody has mapped yet keeps its raw name instead of disappearing: a blank
 * marker hides that something happened, which is worse than an untranslated one.
 */

const EVENT_LABELS: Record<string, string> = {
    created: 'Erstellt',
    updated: 'Aktualisiert',
    deleted: 'Gelöscht',
};

const EVENT_CLASSES: Record<string, string> = {
    created: 'border-emerald-500/30 bg-emerald-500/15 text-emerald-600 dark:text-emerald-400',
    deleted: 'border-destructive/30 bg-destructive/15 text-destructive',
};

const DEFAULT_EVENT_CLASS = 'border-copper/30 bg-copper/15 text-copper-hi';

/**
 * German label for an activity event.
 *
 * `fallback` is used only when the entry carries no event at all — the caller passes the
 * activity's own description there, which is the one thing that always says something.
 */
export function eventLabel(event: string | null | undefined, fallback: string): string {
    if (!event) {
        return fallback;
    }

    return EVENT_LABELS[event] ?? event;
}

/** Badge classes for an activity event. Unmapped events share the neutral copper marker. */
export function eventClass(event: string | null | undefined): string {
    if (!event) {
        return DEFAULT_EVENT_CLASS;
    }

    return EVENT_CLASSES[event] ?? DEFAULT_EVENT_CLASS;
}
