import { describe, expect, it } from 'vitest';

import { eventClass, eventLabel, extraDescription } from './activityEvents';

describe('eventLabel', () => {
    it('translates the three events Spatie writes', () => {
        expect(eventLabel('created', 'egal')).toBe('Erstellt');
        expect(eventLabel('updated', 'egal')).toBe('Aktualisiert');
        expect(eventLabel('deleted', 'egal')).toBe('Gelöscht');
    });

    it('keeps an unmapped event visible under its raw name', () => {
        expect(eventLabel('restored', 'egal')).toBe('restored');
    });

    it('falls back to the description only when there is no event', () => {
        expect(eventLabel(null, 'Paket entfernt')).toBe('Paket entfernt');
        expect(eventLabel(undefined, 'Paket entfernt')).toBe('Paket entfernt');
        expect(eventLabel('', 'Paket entfernt')).toBe('Paket entfernt');
    });
});

describe('eventClass', () => {
    it('gives created and deleted their own colours', () => {
        expect(eventClass('created')).toContain('emerald');
        expect(eventClass('deleted')).toContain('destructive');
    });

    it('does not share a colour between created and deleted', () => {
        expect(eventClass('created')).not.toBe(eventClass('deleted'));
    });

    it('puts updated, unmapped and missing events on the neutral marker', () => {
        const neutral = eventClass('updated');

        expect(neutral).toContain('copper');
        expect(eventClass('restored')).toBe(neutral);
        expect(eventClass(null)).toBe(neutral);
        expect(eventClass(undefined)).toBe(neutral);
    });
});

describe('extraDescription', () => {
    it('suppresses the description when it only repeats the event', () => {
        // Every model logged here uses Spatie's default, which writes the event name into
        // the description — so this is the common case, not the edge case.
        expect(extraDescription('updated', 'updated')).toBeNull();
    });

    it('shows a description that says something the label does not', () => {
        expect(extraDescription('updated', 'Paket aus Registry entfernt')).toBe('Paket aus Registry entfernt');
    });

    it('suppresses it when there is no event, because the label is already the description', () => {
        expect(extraDescription(null, 'Paket entfernt')).toBeNull();
        expect(extraDescription(undefined, 'Paket entfernt')).toBeNull();
        expect(extraDescription('', 'Paket entfernt')).toBeNull();
    });

    it('returns null rather than an empty string for an empty description', () => {
        expect(extraDescription('updated', '')).toBeNull();
        expect(extraDescription('updated', null)).toBeNull();
    });
});
