import { describe, expect, it } from 'vitest';
import { describeProbeFailure, PROBE_RETRY_MESSAGE, type ProbeResponseLike } from './packageForm';

/** A stand-in for the part of `fetch`'s Response the helper reads. */
function response(status: number, body?: unknown): ProbeResponseLike {
    return {
        status,
        json: () => (body === undefined ? Promise.reject(new SyntaxError('Unexpected end of JSON input')) : Promise.resolve(body)),
    };
}

describe('describeProbeFailure', () => {
    // The reported bug: POST /admin/packages/probe answers a rejected repository URL with a
    // 422 whose body names the reason, the create mask threw the body away, and „Anlegen"
    // stays disabled until a probe succeeds — so the operator was told to retry something
    // that could never work.
    it('surfaces the field message from a 422 body', async () => {
        const failure = await describeProbeFailure(
            response(422, {
                message: 'The given data was invalid.',
                errors: { repository_url: ['Die Repository-URL muss mit https:// oder ssh:// beginnen.', 'Zweite Meldung.'] },
            }),
        );

        expect(failure.errors).toEqual({ repository_url: 'Die Repository-URL muss mit https:// oder ssh:// beginnen.' });
        expect(failure.message).toBe('Die Repository-URL muss mit https:// oder ssh:// beginnen.');
        expect(failure.message).not.toBe(PROBE_RETRY_MESSAGE);
    });

    it('maps every field of a 422 body to its first message', async () => {
        const failure = await describeProbeFailure(response(422, { errors: { repository_url: ['URL ungültig.'], type: ['Typ ungültig.'] } }));

        expect(failure.errors).toEqual({ repository_url: 'URL ungültig.', type: 'Typ ungültig.' });
    });

    // A permanent failure reported as a transient one is the bug itself, so each of these
    // has to be its own message and none of them may invite another attempt.
    it('reports a 403 as a missing permission, without suggesting a retry', async () => {
        const failure = await describeProbeFailure(response(403, { message: 'This action is unauthorized.' }));

        expect(failure.message).toContain('Keine Berechtigung');
        expect(failure.message).not.toBe(PROBE_RETRY_MESSAGE);
        expect(failure.message).not.toContain('erneut versuchen');
        expect(failure.errors).toEqual({});
    });

    it('reports a 419 as an expired session that needs a reload, without suggesting a retry', async () => {
        const failure = await describeProbeFailure(response(419, { message: 'CSRF token mismatch.' }));

        expect(failure.message).toContain('Sitzung ist abgelaufen');
        expect(failure.message).toContain('neu laden');
        expect(failure.message).not.toBe(PROBE_RETRY_MESSAGE);
        expect(failure.message).not.toContain('erneut versuchen');
    });

    it('gives 403 and 419 distinct messages', async () => {
        const forbidden = await describeProbeFailure(response(403, {}));
        const expired = await describeProbeFailure(response(419, {}));

        expect(forbidden.message).not.toBe(expired.message);
    });

    // The two cases another attempt can actually fix.
    it('suggests a retry for a server error and for a thrown fetch', async () => {
        expect((await describeProbeFailure(response(500, {}))).message).toBe(PROBE_RETRY_MESSAGE);
        expect((await describeProbeFailure(null)).message).toBe(PROBE_RETRY_MESSAGE);
    });

    it('tells a rate-limited caller to wait rather than to retry at once', async () => {
        const failure = await describeProbeFailure(response(429, {}));

        expect(failure.message).toContain('warten');
        expect(failure.message).not.toBe(PROBE_RETRY_MESSAGE);
    });

    it('names an unexpected status instead of suggesting a retry', async () => {
        const failure = await describeProbeFailure(response(404, {}));

        expect(failure.message).toContain('404');
        expect(failure.message).not.toBe(PROBE_RETRY_MESSAGE);
    });

    // A proxy answering with an HTML error page must not turn a readable failure into an
    // unhandled rejection.
    it('survives a body that is not json', async () => {
        const failure = await describeProbeFailure(response(422));

        expect(failure.errors).toEqual({});
        expect(failure.message).toContain('abgelehnt');
    });
});
