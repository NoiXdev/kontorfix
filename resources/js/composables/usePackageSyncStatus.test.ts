import type { SyncStatus, SyncStatusSnapshot } from '@/lib/syncStatusPoll';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { nextTick, ref } from 'vue';
import { createPackageSyncStatus } from './usePackageSyncStatus';

// Only the Vue lifecycle is stripped from `createPackageSyncStatus` — the refs, the
// re-seed watcher and the reconciler wiring below all run for real. `read` stands in for
// the fetch, and the clock is faked because every poll delay is a real wall-clock wait.
beforeEach(() => vi.useFakeTimers());
afterEach(() => vi.useRealTimers());

/**
 * Mirrors how Show.vue wires this up: a reactive stand-in for the server-rendered prop,
 * passed as a getter rather than a snapshot.
 */
function harness(initial: SyncStatusSnapshot, answers: SyncStatusSnapshot[], delays: readonly number[] = [1_000, 2_000, 3_000]) {
    const prop = ref<SyncStatusSnapshot>(initial);
    const read = vi.fn(async (): Promise<SyncStatusSnapshot> => {
        const next = answers.shift();
        if (next === undefined) {
            throw new Error('the test ran out of scripted answers');
        }

        return next;
    });

    const core = createPackageSyncStatus({ seed: () => prop.value, read, delays });

    return {
        core,
        read,
        /** Stands in for the server re-rendering the page with new props. */
        async rerenderWith(status: SyncStatus, error: string | null = null) {
            prop.value = { status, error };
            await nextTick();
        },
    };
}

describe('seeding', () => {
    it('starts from the server-rendered prop', () => {
        const h = harness({ status: 'failed', error: 'kaputt' }, []);

        expect(h.core.status.value).toBe('failed');
        expect(h.core.error.value).toBe('kaputt');
        expect(h.core.stale.value).toBe(false);
    });

    it('sends nothing for a package that is already synced', async () => {
        const h = harness({ status: 'synced', error: null }, []);
        h.core.start();

        await vi.advanceTimersByTimeAsync(600_000);

        expect(h.read).not.toHaveBeenCalled();
    });
});

// The defect this file exists for. Inertia sets `preserveState: true` for post/put/patch/
// delete, so "Erneut synchronisieren" re-renders the page *without remounting* the
// component: a one-shot seed would leave the badge on the previous terminal value forever.
describe('a resync on an already-finished package', () => {
    it('re-seeds from the new prop and follows the new sync to its answer', async () => {
        const h = harness({ status: 'synced', error: null }, [{ status: 'failed', error: 'Repository nicht erreichbar.' }]);
        h.core.start();

        await vi.advanceTimersByTimeAsync(10_000);
        expect(h.read, 'nothing to poll for while the page is on a terminal status').not.toHaveBeenCalled();

        // The operator clicks resync; the server answers with the package back at pending.
        await h.rerenderWith('pending');
        expect(h.core.status.value, 'the badge must follow the server, not the stale seed').toBe('pending');

        await vi.advanceTimersByTimeAsync(1_000);
        expect(h.core.status.value).toBe('failed');
        expect(h.core.error.value).toBe('Repository nicht erreichbar.');
    });

    it('clears a stale failure message when the new run is queued', async () => {
        const h = harness({ status: 'failed', error: 'Repository nicht erreichbar.' }, []);
        h.core.start();

        await h.rerenderWith('pending', null);

        expect(h.core.error.value, 'the previous run’s error does not belong to the new one').toBeNull();
    });

    it('re-arms without sending anything when the new prop is itself terminal', async () => {
        const h = harness({ status: 'synced', error: null }, []);
        h.core.start();

        await h.rerenderWith('failed', 'kaputt');
        await vi.advanceTimersByTimeAsync(600_000);

        expect(h.read).not.toHaveBeenCalled();
        expect(h.core.status.value).toBe('failed');
    });
});

describe('the give-up notice', () => {
    it('marks the display stale once it stops trying', async () => {
        const h = harness({ status: 'pending', error: null }, [
            { status: 'pending', error: null },
            { status: 'pending', error: null },
            { status: 'pending', error: null },
        ]);
        h.core.start();

        await vi.advanceTimersByTimeAsync(2_000);
        expect(h.core.stale.value, 'still looking — nothing to warn about yet').toBe(false);

        await vi.advanceTimersByTimeAsync(600_000);
        expect(h.core.stale.value, 'a frozen badge with no signal is the failure being avoided').toBe(true);
    });

    it('clears the notice when a broadcast finally arrives', async () => {
        const h = harness({ status: 'pending', error: null }, [
            { status: 'pending', error: null },
            { status: 'pending', error: null },
            { status: 'pending', error: null },
        ]);
        h.core.start();
        await vi.advanceTimersByTimeAsync(600_000);
        expect(h.core.stale.value).toBe(true);

        h.core.apply({ status: 'synced', error: null });

        expect(h.core.stale.value).toBe(false);
        expect(h.core.status.value).toBe('synced');
    });

    it('clears the notice when the page is re-rendered', async () => {
        const h = harness({ status: 'pending', error: null }, [
            { status: 'pending', error: null },
            { status: 'pending', error: null },
            { status: 'pending', error: null },
        ]);
        h.core.start();
        await vi.advanceTimersByTimeAsync(600_000);
        expect(h.core.stale.value).toBe(true);

        await h.rerenderWith('pending');

        expect(h.core.stale.value).toBe(false);
    });
});

describe('teardown', () => {
    it('stops polling and stops watching the prop', async () => {
        const h = harness({ status: 'pending', error: null }, [{ status: 'pending', error: null }]);
        h.core.start();
        h.core.stop();

        await vi.advanceTimersByTimeAsync(600_000);
        expect(h.read).not.toHaveBeenCalled();

        // An unmounted page must not keep reacting to props, nor revive the poll.
        await h.rerenderWith('syncing');
        await vi.advanceTimersByTimeAsync(600_000);

        expect(h.read).not.toHaveBeenCalled();
    });
});
