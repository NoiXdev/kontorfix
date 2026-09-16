import { onBeforeUnmount } from 'vue';

/**
 * The debounce/abort state machine every preview panel on this frontend needs, generalised
 * over the result type.
 *
 * Extracted out of `useRetentionPreview.ts`'s `createRetentionPreview()` when the scan-blocking
 * preview became the second caller: that function's OWN shape (`summary`/`tags` refs) is
 * retention-specific and stays there, unchanged, so its 11-test suite keeps passing unmodified
 * — but the debounce timer, the abort-the-previous-request guarantee, and the "cancel always
 * resets loading, even for a request that never gets to run its own `finally`" rule are not.
 * This module holds exactly that generic core.
 *
 * Callback-based rather than Ref-based: a caller supplies `onResult`/`onError`/
 * `onLoadingChange` and owns whatever Refs it wants to update from them. That keeps
 * `RetentionPreviewCore`'s public shape (`summary`, `tags`, …) completely untouched — a Ref-
 * returning generic core would have forced `createRetentionPreview()` to either change its
 * own return type or bridge through a `watch()`, and a `watch()` callback runs on the next
 * tick rather than synchronously with the state change it reacts to, which is exactly the
 * kind of timing this module must not introduce into a test suite that asserts on `.value`
 * immediately after `await`.
 */

/** A request in flight, parameterised by the AbortSignal that cancels it. */
export type PreviewRequest<TResult> = (signal: AbortSignal) => Promise<TResult>;

export interface PreviewEngineHandlers<TResult> {
    /** A genuine, non-superseded, non-aborted result. */
    onResult: (result: TResult) => void;
    /** A genuine failure's message — never called for an abort. */
    onError: (message: string) => void;
    /** The in-flight state of the request that currently owns it. */
    onLoadingChange: (loading: boolean) => void;
}

export interface PreviewEngine<TResult> {
    /**
     * Debounced: waits out `debounceMs` of silence before actually asking the server, and
     * aborts whichever request from an earlier call is still in flight — a keystroke never
     * has to wait for a stale response it no longer cares about, and a stale response can
     * never overwrite a newer one that raced ahead of it.
     */
    schedule: (request: PreviewRequest<TResult>) => void;
    /** Same abort-the-previous guarantee as `schedule`, but skips the debounce wait. */
    runNow: (request: PreviewRequest<TResult>) => Promise<void>;
    /** Drops any pending debounce timer and aborts an in-flight request without starting another. */
    cancel: () => void;
}

/**
 * The lifecycle-free core, so it can be unit-tested — see `createRetentionPreview()`'s own
 * docblock for why: this repo's vitest runs in a `node` environment with no
 * component-rendering harness.
 */
export function createPreviewEngine<TResult>(
    handlers: PreviewEngineHandlers<TResult>,
    options: { debounceMs?: number; fallbackMessage?: string } = {},
): PreviewEngine<TResult> {
    const debounceMs = options.debounceMs ?? 600;
    const fallbackMessage = options.fallbackMessage ?? 'Die Anfrage ist fehlgeschlagen.';

    let timer: ReturnType<typeof setTimeout> | undefined;
    let controller: AbortController | undefined;

    async function run(request: PreviewRequest<TResult>): Promise<void> {
        // Aborting the PREVIOUS controller, not this call's own: two rapid schedule() calls
        // must not race, and the second one's response is the only one allowed to land.
        controller?.abort();
        const own = new AbortController();
        controller = own;
        handlers.onLoadingChange(true);

        try {
            const result = await request(own.signal);

            // A response can arrive after its own controller was aborted (the abort raced
            // the fetch's own resolution) — discarded either way, since a newer request has
            // already taken over `controller`.
            if (own.signal.aborted) {
                return;
            }

            handlers.onResult(result);
        } catch (err) {
            if (own.signal.aborted || (err instanceof DOMException && err.name === 'AbortError')) {
                return;
            }

            handlers.onError(err instanceof Error ? err.message : fallbackMessage);
        } finally {
            if (!own.signal.aborted) {
                handlers.onLoadingChange(false);
            }
        }
    }

    function schedule(request: PreviewRequest<TResult>): void {
        if (timer !== undefined) {
            clearTimeout(timer);
        }

        timer = setTimeout(() => {
            timer = undefined;
            void run(request);
        }, debounceMs);
    }

    async function runNow(request: PreviewRequest<TResult>): Promise<void> {
        if (timer !== undefined) {
            clearTimeout(timer);
            timer = undefined;
        }

        await run(request);
    }

    function cancel(): void {
        if (timer !== undefined) {
            clearTimeout(timer);
            timer = undefined;
        }

        controller?.abort();

        // Unconditional, unlike the `finally` above: a caller cancelling because there is
        // nothing left to preview (e.g. the severity was cleared) must not be left with
        // `loading` stuck true just because the request it aborted never got to run its own
        // `finally` (aborted requests skip it, by design, so a stale response can't flip
        // loading back on after a NEWER request already took over).
        handlers.onLoadingChange(false);
    }

    return { schedule, runNow, cancel };
}

/** The Vue-lifecycle wrapper: the core above, plus aborting whatever is in flight on unmount. */
export function usePreviewEngine<TResult>(
    handlers: PreviewEngineHandlers<TResult>,
    options: { debounceMs?: number; fallbackMessage?: string } = {},
): PreviewEngine<TResult> {
    const engine = createPreviewEngine(handlers, options);

    onBeforeUnmount(engine.cancel);

    return engine;
}
