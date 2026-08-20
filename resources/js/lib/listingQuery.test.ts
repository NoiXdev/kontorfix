import { describe, expect, it } from 'vitest';
import { mergeQuery } from './listingQuery';

describe('mergeQuery', () => {
    it('keeps query keys the caller says nothing about', () => {
        // The activity page's scoping (subject_type/subject_id/causer) is set by a link
        // from a detail page and owned by no control on the page. Changing the page size
        // must not silently widen the log back to everything.
        const query = mergeQuery({ per_page: '100' }, '?subject_type=Package&subject_id=abc&log=package');

        expect(query).toEqual({
            subject_type: 'Package',
            subject_id: 'abc',
            log: 'package',
            per_page: '100',
        });
    });

    it('overwrites a key that is already in the URL', () => {
        expect(mergeQuery({ direction: 'asc' }, '?sort=created_at&direction=desc')).toEqual({
            sort: 'created_at',
            direction: 'asc',
        });
    });

    it('drops the page number, because the old page no longer holds the same rows', () => {
        const query = mergeQuery({ per_page: '25' }, '?page=7&log=user');

        expect(query).not.toHaveProperty('page');
        expect(query.log).toBe('user');
    });

    it('removes a key set to undefined', () => {
        expect(mergeQuery({ subject_id: undefined }, '?subject_id=abc&log=user')).toEqual({ log: 'user' });
    });

    it('removes a key set to the empty string rather than emitting a bare "?log="', () => {
        // This is the "Alle Bereiche" option of the log filter: its value is '', and it
        // means no filter, not a filter on the empty name.
        expect(mergeQuery({ log: '' }, '?log=user&per_page=25')).toEqual({ per_page: '25' });
    });

    it('starts from nothing when the URL carries no query at all', () => {
        expect(mergeQuery({ sort: 'created_at', direction: 'desc' }, '')).toEqual({
            sort: 'created_at',
            direction: 'desc',
        });
    });
});
