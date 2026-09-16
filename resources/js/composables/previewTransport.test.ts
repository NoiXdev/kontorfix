import { afterEach, describe, expect, it, vi } from 'vitest';
import { postPreviewJson, xsrfToken } from './previewTransport';

/**
 * The transport itself, mocked at the `fetch` boundary — this repo's vitest runs in a `node`
 * environment with no DOM, so `document.cookie` is stubbed rather than provided by a real
 * browser. `useRetentionPreview.test.ts` does NOT exercise this module: it injects a fake
 * request function and never calls `postPreviewJson()` itself, so "the retention suite passes
 * unmodified" proves the extraction didn't change retention's behaviour, not that this file
 * behaves correctly on its own.
 */

function jsonResponse(body: unknown, ok: boolean): Response {
    return {
        ok,
        json: () => Promise.resolve(body),
    } as Response;
}

afterEach(() => {
    vi.unstubAllGlobals();
});

describe('xsrfToken', () => {
    it('reads the XSRF-TOKEN cookie Laravel sets', () => {
        vi.stubGlobal('document', { cookie: 'other=1; XSRF-TOKEN=abc%2Fdef; more=2' });

        expect(xsrfToken()).toBe('abc/def');
    });

    it('answers an empty string when the cookie is absent', () => {
        vi.stubGlobal('document', { cookie: 'other=1' });

        expect(xsrfToken()).toBe('');
    });
});

describe('postPreviewJson', () => {
    it('returns the parsed body on a successful response', async () => {
        vi.stubGlobal('document', { cookie: '' });
        vi.stubGlobal(
            'fetch',
            vi.fn().mockResolvedValue(jsonResponse({ summary: ['kept 5'] }, true)),
        );

        await expect(postPreviewJson('/preview', {}, new AbortController().signal)).resolves.toEqual({ summary: ['kept 5'] });
    });

    it("throws the body's `message` as the error on a non-ok response", async () => {
        vi.stubGlobal('document', { cookie: '' });
        vi.stubGlobal(
            'fetch',
            vi.fn().mockResolvedValue(jsonResponse({ message: 'Mindestens eine Regel ist nötig.' }, false)),
        );

        await expect(postPreviewJson('/preview', {}, new AbortController().signal)).rejects.toThrow('Mindestens eine Regel ist nötig.');
    });

    it('falls back to the caller-supplied message when the body carries no `message`', async () => {
        vi.stubGlobal('document', { cookie: '' });
        vi.stubGlobal('fetch', vi.fn().mockResolvedValue(jsonResponse({}, false)));

        await expect(postPreviewJson('/preview', {}, new AbortController().signal, 'Die Anfrage ist fehlgeschlagen.')).rejects.toThrow(
            'Die Anfrage ist fehlgeschlagen.',
        );
    });

    it('falls back to its own default message when the caller supplies none', async () => {
        vi.stubGlobal('document', { cookie: '' });
        // A body that fails to parse as JSON at all — the `.catch(() => null)` path.
        vi.stubGlobal(
            'fetch',
            vi.fn().mockResolvedValue({ ok: false, json: () => Promise.reject(new Error('not json')) } as unknown as Response),
        );

        await expect(postPreviewJson('/preview', {}, new AbortController().signal)).rejects.toThrow('Die Vorschau ist fehlgeschlagen.');
    });

    it('sends the CSRF cookie as the X-XSRF-TOKEN header', async () => {
        vi.stubGlobal('document', { cookie: 'XSRF-TOKEN=secret-token' });
        const fetchMock = vi.fn().mockResolvedValue(jsonResponse({}, true));
        vi.stubGlobal('fetch', fetchMock);

        await postPreviewJson('/preview', { a: 1 }, new AbortController().signal);

        expect(fetchMock).toHaveBeenCalledWith(
            '/preview',
            expect.objectContaining({
                method: 'POST',
                headers: expect.objectContaining({ 'X-XSRF-TOKEN': 'secret-token' }),
                body: JSON.stringify({ a: 1 }),
            }),
        );
    });
});
