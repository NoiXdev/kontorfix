import { describe, expect, it } from 'vitest';
import { effectiveWindow } from './lizenz';

describe('effectiveWindow', () => {
    it('returns the row untouched without a licence', () => {
        expect(effectiveWindow({ min: '1.0.0', max: '5.0.0' }, null)).toEqual({
            min: '1.0.0',
            max: '5.0.0',
            narrowedByLicence: false,
            empty: false,
        });
    });

    it('reports the narrowed side', () => {
        expect(effectiveWindow({ min: '1.0.0', max: '5.0.0' }, { min: null, max: '2.9.9', expired: false })).toEqual({
            min: '1.0.0',
            max: '2.9.9',
            narrowedByLicence: true,
            empty: false,
        });
    });

    it('marks a row the licence reduces to nothing', () => {
        const result = effectiveWindow({ min: '3.0.0', max: '5.0.0' }, { min: '1.0.0', max: '2.9.9', expired: false });
        expect(result.empty).toBe(true);
    });

    it('marks everything empty once the licence has expired', () => {
        const result = effectiveWindow({ min: '1.0.0', max: null }, { min: null, max: null, expired: true });
        expect(result.empty).toBe(true);
    });
});
