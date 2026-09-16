import { describe, expect, it } from 'vitest';
import { SEVERITY_OPTIONS, severityClass, severityLabel } from './severity';

describe('severity', () => {
    it('lists the five severities from least to most severe', () => {
        // The same ladder VulnerabilitySeverity::rank() defines. A select that offered them
        // in a different order would make an operator pick the wrong threshold.
        expect(SEVERITY_OPTIONS.map((o) => o.value)).toEqual(['unknown', 'low', 'medium', 'high', 'critical']);
    });

    it('labels every severity in German', () => {
        expect(severityLabel('critical')).toBe('Kritisch');
        expect(severityLabel('low')).toBe('Niedrig');
    });

    it('falls back rather than rendering a raw value', () => {
        // A scanner that invents a severity must not leak its wire word into the UI.
        expect(severityLabel('catastrophic')).toBe('Unbekannt');
        expect(severityLabel(null)).toBe('Unbekannt');
        expect(severityClass(undefined)).toContain('muted');
    });
});
