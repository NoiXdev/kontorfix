/**
 * The five severities, in the client's terms.
 *
 * The labels and the ORDER are restated here because a `<select>` needs them, and the order
 * is the same ladder `VulnerabilitySeverity::rank()` defines server-side. Nothing here
 * decides anything: every verdict comes from the server, and this is presentation only —
 * which is why a mismatch would be a cosmetic bug rather than a security one.
 */
export type Severity = 'unknown' | 'low' | 'medium' | 'high' | 'critical';

export const SEVERITY_OPTIONS: ReadonlyArray<{ value: Severity; label: string }> = [
    { value: 'unknown', label: 'Unbekannt' },
    { value: 'low', label: 'Niedrig' },
    { value: 'medium', label: 'Mittel' },
    { value: 'high', label: 'Hoch' },
    { value: 'critical', label: 'Kritisch' },
];

export function severityLabel(value: string | null | undefined): string {
    return SEVERITY_OPTIONS.find((option) => option.value === value)?.label ?? 'Unbekannt';
}

/** Tailwind classes per severity, so a badge looks the same on every surface that renders one. */
export function severityClass(value: string | null | undefined): string {
    switch (value) {
        case 'critical':
            return 'bg-red-100 text-red-800 dark:bg-red-950 dark:text-red-200';
        case 'high':
            return 'bg-orange-100 text-orange-800 dark:bg-orange-950 dark:text-orange-200';
        case 'medium':
            return 'bg-amber-100 text-amber-800 dark:bg-amber-950 dark:text-amber-200';
        case 'low':
            return 'bg-slate-100 text-slate-800 dark:bg-slate-800 dark:text-slate-200';
        default:
            return 'bg-muted text-muted-foreground';
    }
}
