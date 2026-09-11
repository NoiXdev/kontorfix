import { describe, expect, it } from 'vitest';
import {
    admitsForPreview,
    availableUntilLabel,
    boundsLabel,
    boundsPreview,
    detectMajorLine,
    effectiveBounds,
    formatDay,
    groupByOrganization,
    highestAdmittedForPreview,
    majorLineBounds,
    statusLabel,
    type AssignmentRow,
} from './freigaben';

function row(overrides: Partial<AssignmentRow> = {}): AssignmentRow {
    return {
        organization_id: 'org-1',
        organization_name: 'Kunde A',
        group_id: 'group-1',
        group_name: 'Registry A',
        version_min: null,
        version_max: null,
        available_until: null,
        available_until_iso: null,
        in_force: true,
        can_edit: true,
        ...overrides,
    };
}

describe('formatDay', () => {
    it('renders the German day order', () => {
        expect(formatDay('2026-12-31')).toBe('31.12.2026');
    });

    it('does not shift the day across a timezone', () => {
        expect(formatDay('2026-01-01')).toBe('01.01.2026');
    });
});

describe('availableUntilLabel', () => {
    it('says unbegrenzt for an open-ended assignment', () => {
        expect(availableUntilLabel(null)).toBe('unbegrenzt');
    });

    it('names the day otherwise', () => {
        expect(availableUntilLabel('2026-12-31')).toBe('bis 31.12.2026');
    });
});

describe('statusLabel', () => {
    it('takes the answer from the server, not from a date comparison', () => {
        expect(statusLabel(true)).toBe('aktiv');
        expect(statusLabel(false)).toBe('abgelaufen');
    });
});

describe('boundsLabel', () => {
    it('names both sides of a two-sided window', () => {
        expect(boundsLabel('2.0', '3.0')).toBe('ab 2.0 < 3.0');
    });

    it('names one side alone', () => {
        expect(boundsLabel('2.0', null)).toBe('ab 2.0');
        expect(boundsLabel(null, '3.0')).toBe('< 3.0');
    });

    it('calls no bounds at all "alle"', () => {
        expect(boundsLabel(null, null)).toBe('alle');
    });
});

describe('groupByOrganization', () => {
    it('groups rows under their organization, preserving arrival order', () => {
        const rows = [
            row({ organization_id: 'a', organization_name: 'Kunde A', group_id: 'g1' }),
            row({ organization_id: 'b', organization_name: 'Kunde B', group_id: 'g2' }),
            row({ organization_id: 'a', organization_name: 'Kunde A', group_id: 'g3' }),
        ];

        const grouped = groupByOrganization(rows);

        expect(grouped).toHaveLength(2);
        expect(grouped[0].organization_id).toBe('a');
        expect(grouped[0].rows.map((r) => r.group_id)).toEqual(['g1', 'g3']);
        expect(grouped[1].organization_id).toBe('b');
        expect(grouped[1].rows.map((r) => r.group_id)).toEqual(['g2']);
    });

    it('returns nothing for an empty list', () => {
        expect(groupByOrganization([])).toEqual([]);
    });
});

describe('majorLineBounds', () => {
    it('writes min = X.0 and max = (X+1).0', () => {
        expect(majorLineBounds('2')).toEqual({ min: '2.0', max: '3.0' });
        expect(majorLineBounds('9')).toEqual({ min: '9.0', max: '10.0' });
    });
});

describe('effectiveBounds', () => {
    it('is unlimited for "Alle Versionen"', () => {
        expect(effectiveBounds('all', '', '', '')).toEqual({ min: null, max: null });
    });

    it('derives min/max from the selected major line', () => {
        expect(effectiveBounds('major', '2', '', '')).toEqual({ min: '2.0', max: '3.0' });
    });

    it('falls back to unlimited when no major line is selected yet', () => {
        expect(effectiveBounds('major', '', '', '')).toEqual({ min: null, max: null });
    });

    it('reads the two custom fields directly, trimmed', () => {
        expect(effectiveBounds('custom', '', ' 1.5.0 ', ' 2.0.0 ')).toEqual({ min: '1.5.0', max: '2.0.0' });
    });

    it('treats a blank custom field as no bound on that side', () => {
        expect(effectiveBounds('custom', '', '', '2.0.0')).toEqual({ min: null, max: '2.0.0' });
        expect(effectiveBounds('custom', '', '1.0.0', '')).toEqual({ min: '1.0.0', max: null });
    });
});

describe('detectMajorLine', () => {
    it('recognises exactly what majorLineBounds writes', () => {
        expect(detectMajorLine('2.0', '3.0')).toBe('2');
        expect(detectMajorLine('9.0', '10.0')).toBe('9');
    });

    it('does not guess at a hand-typed pair that merely admits the same versions', () => {
        expect(detectMajorLine('2.0.0', '3.0.0')).toBeNull();
    });

    it('returns null for unlimited or one-sided bounds', () => {
        expect(detectMajorLine(null, null)).toBeNull();
        expect(detectMajorLine('2.0', null)).toBeNull();
        expect(detectMajorLine(null, '3.0')).toBeNull();
    });

    it('refuses a mismatched pair', () => {
        expect(detectMajorLine('2.0', '5.0')).toBeNull();
    });
});

describe('admitsForPreview', () => {
    it('admits everything under unlimited bounds', () => {
        expect(admitsForPreview('5.0.0', null, null)).toBe(true);
    });

    it('is inclusive at the lower bound and exclusive at the upper one', () => {
        expect(admitsForPreview('2.0.0', '2.0.0', '3.0.0')).toBe(true);
        expect(admitsForPreview('3.0.0', '2.0.0', '3.0.0')).toBe(false);
    });

    it('refuses a version below the lower bound', () => {
        expect(admitsForPreview('1.9.0', '2.0.0', null)).toBe(false);
    });

    it('refuses a version at or above the upper bound', () => {
        expect(admitsForPreview('3.5.0', null, '3.0.0')).toBe(false);
    });
});

describe('highestAdmittedForPreview', () => {
    it('picks the highest admitted version off a newest-first list', () => {
        expect(highestAdmittedForPreview(['3.0.0', '2.5.0', '2.0.0', '1.0.0'], '2.0.0', '3.0.0')).toBe('2.5.0');
    });

    it('is correct regardless of input order — Python dists arrive sorted by upload time, not version', () => {
        expect(highestAdmittedForPreview(['1.0.0', '2.0.0', '2.5.0', '3.0.0'], '2.0.0', '3.0.0')).toBe('2.5.0');
        expect(highestAdmittedForPreview(['2.0.0', '3.0.0', '1.0.0', '2.5.0'], '2.0.0', '3.0.0')).toBe('2.5.0');
    });

    it('returns null when the bounds admit none of the versions', () => {
        expect(highestAdmittedForPreview(['1.0.0', '1.5.0'], '2.0.0', '3.0.0')).toBeNull();
    });
});

describe('boundsPreview', () => {
    it('names the highest admitted version', () => {
        expect(boundsPreview(['3.0.0', '2.5.0', '2.0.0'], '2.0.0', '3.0.0')).toBe('Höchste zulässige Version: 2.5.0');
    });

    it('warns when the bounds admit nothing — the case that must never save silently', () => {
        expect(boundsPreview(['1.0.0'], '5.0.0', null)).toBe('Diese Eingrenzung schließt alle Versionen aus.');
    });

    it('says so when the package has no versions to preview against at all', () => {
        expect(boundsPreview([], null, null)).toBe('Für dieses Paket liegen keine Versionen vor.');
    });
});
