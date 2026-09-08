import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { createRetentionPreview, type RetentionPreviewRequest, type RetentionPreviewResult } from './useRetentionPreview';

beforeEach(() => vi.useFakeTimers());
afterEach(() => vi.useRealTimers());

/** A request whose resolution the test controls, and which records whether it was aborted. */
function deferredRequest(): { request: RetentionPreviewRequest; resolve: (result: RetentionPreviewResult) => void; reject: (err: unknown) => void; aborted: () => boolean } {
    let resolve!: (result: RetentionPreviewResult) => void;
    let reject!: (err: unknown) => void;
    let signal: AbortSignal | undefined;

    const promise = new Promise<RetentionPreviewResult>((res, rej) => {
        resolve = res;
        reject = rej;
    });

    const request: RetentionPreviewRequest = (s) => {
        signal = s;
        return promise;
    };

    return { request, resolve, reject, aborted: () => signal?.aborted ?? false };
}

describe('schedule (debounce)', () => {
    it('waits out the debounce window before requesting', async () => {
        const core = createRetentionPreview({ debounceMs: 600 });
        const spy = vi.fn(async (): Promise<RetentionPreviewResult> => ({ summary: ['Letzte 1 behalten'], tags: null }));

        core.schedule(spy);
        await vi.advanceTimersByTimeAsync(599);
        expect(spy).not.toHaveBeenCalled();

        await vi.advanceTimersByTimeAsync(1);
        expect(spy).toHaveBeenCalledTimes(1);
    });

    it('collapses rapid successive calls into one request, using only the latest', async () => {
        const core = createRetentionPreview({ debounceMs: 600 });
        const first = vi.fn(async (): Promise<RetentionPreviewResult> => ({ summary: ['first'], tags: null }));
        const second = vi.fn(async (): Promise<RetentionPreviewResult> => ({ summary: ['second'], tags: null }));

        core.schedule(first);
        await vi.advanceTimersByTimeAsync(300);
        core.schedule(second);
        await vi.advanceTimersByTimeAsync(600);

        expect(first).not.toHaveBeenCalled();
        expect(second).toHaveBeenCalledTimes(1);
        expect(core.summary.value).toEqual(['second']);
    });

    it('applies the result and clears a previous error on success', async () => {
        const core = createRetentionPreview({ debounceMs: 0 });
        core.error.value = 'stale error';

        core.schedule(async () => ({ summary: ['Letzte 5 behalten'], tags: [{ name: 'v1', pushed_at: null, keep: true, reason: 'Letzte 5 behalten' }] }));
        await vi.advanceTimersByTimeAsync(0);

        expect(core.summary.value).toEqual(['Letzte 5 behalten']);
        expect(core.tags.value).toEqual([{ name: 'v1', pushed_at: null, keep: true, reason: 'Letzte 5 behalten' }]);
        expect(core.error.value).toBeNull();
        expect(core.loading.value).toBe(false);
    });
});

describe('abort semantics', () => {
    it('aborts a request still in flight when another is scheduled', async () => {
        const core = createRetentionPreview({ debounceMs: 0 });
        const stale = deferredRequest();
        const fresh = deferredRequest();

        core.schedule(stale.request);
        await vi.advanceTimersByTimeAsync(0);
        expect(stale.aborted()).toBe(false);

        core.schedule(fresh.request);
        await vi.advanceTimersByTimeAsync(0);

        expect(stale.aborted(), 'the superseded request must be told to stop').toBe(true);

        // The stale request resolving late must not overwrite the fresh one's result.
        stale.resolve({ summary: ['stale'], tags: null });
        await Promise.resolve();
        fresh.resolve({ summary: ['fresh'], tags: null });
        await Promise.resolve();

        expect(core.summary.value).toEqual(['fresh']);
    });

    it('does not surface an AbortError as a user-facing error', async () => {
        const core = createRetentionPreview({ debounceMs: 0 });
        const stale = deferredRequest();

        core.schedule(stale.request);
        await vi.advanceTimersByTimeAsync(0);

        core.schedule(async () => ({ summary: ['fresh'], tags: null }));
        await vi.advanceTimersByTimeAsync(0);

        stale.reject(new DOMException('aborted', 'AbortError'));
        await Promise.resolve();

        expect(core.error.value).toBeNull();
        expect(core.summary.value).toEqual(['fresh']);
    });

    it('sets a readable error on a genuine failure (e.g. a 422)', async () => {
        const core = createRetentionPreview({ debounceMs: 0 });

        core.schedule(async () => {
            throw new Error('Mindestens eine Behalte-Regel ist nötig — „Nie löschen“ allein entfernt nichts.');
        });
        await vi.advanceTimersByTimeAsync(0);

        expect(core.error.value).toBe('Mindestens eine Behalte-Regel ist nötig — „Nie löschen“ allein entfernt nichts.');
        expect(core.loading.value).toBe(false);
    });
});

describe('runNow', () => {
    it('skips the debounce wait and requests immediately', async () => {
        const core = createRetentionPreview({ debounceMs: 600 });
        const spy = vi.fn(async (): Promise<RetentionPreviewResult> => ({ summary: ['now'], tags: null }));

        await core.runNow(spy);

        expect(spy).toHaveBeenCalledTimes(1);
        expect(core.summary.value).toEqual(['now']);
    });

    it('cancels a pending debounced request rather than racing it', async () => {
        const core = createRetentionPreview({ debounceMs: 600 });
        const debounced = vi.fn(async (): Promise<RetentionPreviewResult> => ({ summary: ['debounced'], tags: null }));
        const immediate = vi.fn(async (): Promise<RetentionPreviewResult> => ({ summary: ['immediate'], tags: null }));

        core.schedule(debounced);
        await core.runNow(immediate);
        await vi.advanceTimersByTimeAsync(600);

        expect(debounced, 'the debounce timer must have been cleared, not merely outrun').not.toHaveBeenCalled();
        expect(core.summary.value).toEqual(['immediate']);
    });
});

describe('cancel', () => {
    it('drops a pending debounce timer without requesting', async () => {
        const core = createRetentionPreview({ debounceMs: 600 });
        const spy = vi.fn(async (): Promise<RetentionPreviewResult> => ({ summary: [], tags: null }));

        core.schedule(spy);
        core.cancel();
        await vi.advanceTimersByTimeAsync(600);

        expect(spy).not.toHaveBeenCalled();
    });

    it('aborts a request already in flight', async () => {
        const core = createRetentionPreview({ debounceMs: 0 });
        const inFlight = deferredRequest();

        core.schedule(inFlight.request);
        await vi.advanceTimersByTimeAsync(0);

        core.cancel();

        expect(inFlight.aborted()).toBe(true);
    });
});
