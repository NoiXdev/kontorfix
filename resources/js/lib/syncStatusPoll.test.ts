import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { createSyncStatusReconciler, isTerminalSyncStatus, SYNC_POLL_DELAYS_MS, type SyncStatus, type SyncStatusSnapshot } from './syncStatusPoll';

// Every delay in the schedule is a real wall-clock wait, so the clock is faked and driven
// with the async variants — `read` returns a promise and the reconciler awaits it, which a
// synchronous timer advance would step straight past.
beforeEach(() => vi.useFakeTimers());
afterEach(() => vi.useRealTimers());

/**
 * A reconciler wired to a mutable status, the way the composable wires it to the ref the
 * badge renders. `broadcast()` stands in for the Echo listener writing that same ref.
 */
function harness(initial: SyncStatus, answers: SyncStatusSnapshot[], delays = [1_000, 2_000, 3_000]) {
    const displayed = { status: initial, error: null as string | null };
    const reads: number[] = [];

    const read = vi.fn(async (): Promise<SyncStatusSnapshot> => {
        reads.push(Date.now());
        const next = answers.shift();
        if (next === undefined) {
            throw new Error('the test ran out of scripted answers');
        }

        return next;
    });

    const reconciler = createSyncStatusReconciler({
        current: () => displayed.status,
        read,
        apply: (snapshot) => {
            displayed.status = snapshot.status;
            displayed.error = snapshot.error;
        },
        delays,
    });

    return {
        reconciler,
        read,
        displayed,
        broadcast(status: SyncStatus) {
            displayed.status = status;
        },
    };
}

describe('not running when there is nothing to reconcile', () => {
    it('never asks the server when the page opens already synced', async () => {
        const h = harness('synced', []);
        h.reconciler.start();

        await vi.advanceTimersByTimeAsync(60_000);

        expect(h.read, 'a terminal status is the final answer — polling it is pure waste').not.toHaveBeenCalled();
        expect(h.reconciler.running).toBe(false);
    });

    it('never asks the server when the page opens already failed', async () => {
        const h = harness('failed', []);
        h.reconciler.start();

        await vi.advanceTimersByTimeAsync(60_000);

        expect(h.read).not.toHaveBeenCalled();
    });

    it('does start when the page opens on a non-terminal status', async () => {
        const h = harness('pending', [{ status: 'synced', error: null }]);
        h.reconciler.start();

        await vi.advanceTimersByTimeAsync(1_000);

        expect(h.read).toHaveBeenCalledTimes(1);
    });
});

describe('reaching a terminal status', () => {
    // The defect itself: the job finished before the browser subscribed, so no broadcast
    // is coming and only this poll can correct the badge.
    it('applies the answer and stops once the server reports synced', async () => {
        const h = harness('pending', [
            { status: 'syncing', error: null },
            { status: 'synced', error: null },
        ]);
        h.reconciler.start();

        await vi.advanceTimersByTimeAsync(1_000);
        expect(h.displayed.status).toBe('syncing');
        expect(h.reconciler.running, 'syncing is not terminal — keep going').toBe(true);

        await vi.advanceTimersByTimeAsync(2_000);
        expect(h.displayed.status).toBe('synced');
        expect(h.reconciler.running).toBe(false);

        // Nothing more, however long the page stays open.
        await vi.advanceTimersByTimeAsync(120_000);
        expect(h.read).toHaveBeenCalledTimes(2);
    });

    it('carries the error text over with a failed status', async () => {
        const h = harness('syncing', [{ status: 'failed', error: 'Repository nicht erreichbar.' }]);
        h.reconciler.start();

        await vi.advanceTimersByTimeAsync(1_000);

        expect(h.displayed).toEqual({ status: 'failed', error: 'Repository nicht erreichbar.' });
        expect(h.reconciler.running).toBe(false);
    });
});

describe('backing off and giving up', () => {
    it('waits longer between each attempt instead of hammering the server', async () => {
        const h = harness(
            'pending',
            [
                { status: 'pending', error: null },
                { status: 'pending', error: null },
                { status: 'pending', error: null },
            ],
            [1_000, 2_000, 3_000],
        );
        h.reconciler.start();

        await vi.advanceTimersByTimeAsync(999);
        expect(h.read).toHaveBeenCalledTimes(0);
        await vi.advanceTimersByTimeAsync(1);
        expect(h.read).toHaveBeenCalledTimes(1);

        await vi.advanceTimersByTimeAsync(1_999);
        expect(h.read, 'the second wait must be longer than the first').toHaveBeenCalledTimes(1);
        await vi.advanceTimersByTimeAsync(1);
        expect(h.read).toHaveBeenCalledTimes(2);

        await vi.advanceTimersByTimeAsync(2_999);
        expect(h.read).toHaveBeenCalledTimes(2);
        await vi.advanceTimersByTimeAsync(1);
        expect(h.read).toHaveBeenCalledTimes(3);
    });

    // A job wedged in `syncing` must not turn every open tab into a permanent request loop.
    it('gives up after the budgeted attempts on a stuck job', async () => {
        const h = harness(
            'syncing',
            [
                { status: 'syncing', error: null },
                { status: 'syncing', error: null },
                { status: 'syncing', error: null },
            ],
            [1_000, 2_000, 3_000],
        );
        h.reconciler.start();

        await vi.advanceTimersByTimeAsync(600_000);

        expect(h.read).toHaveBeenCalledTimes(3);
        expect(h.reconciler.running).toBe(false);
    });

    it('spends an attempt on a failed request but keeps trying', async () => {
        const displayed = { status: 'pending' as SyncStatus };
        const read = vi
            .fn<() => Promise<SyncStatusSnapshot>>()
            .mockRejectedValueOnce(new Error('offline'))
            .mockResolvedValueOnce({ status: 'synced', error: null });

        const reconciler = createSyncStatusReconciler({
            current: () => displayed.status,
            read,
            apply: (s) => (displayed.status = s.status),
            delays: [1_000, 2_000, 3_000],
        });
        reconciler.start();

        await vi.advanceTimersByTimeAsync(1_000);
        expect(displayed.status, 'a rejected read must not be mistaken for an answer').toBe('pending');
        expect(reconciler.running).toBe(true);

        await vi.advanceTimersByTimeAsync(2_000);
        expect(displayed.status).toBe('synced');
    });

    it('budgets a couple of minutes across a handful of attempts', () => {
        const total = SYNC_POLL_DELAYS_MS.reduce((sum, d) => sum + d, 0);

        expect(SYNC_POLL_DELAYS_MS.length).toBeLessThanOrEqual(20);
        expect(total, 'long enough to outlast a slow clone').toBeGreaterThan(60_000);
        expect(total, 'short enough that a stuck job is abandoned, not polled forever').toBeLessThanOrEqual(300_000);
        expect(SYNC_POLL_DELAYS_MS[0], 'the first check has to be quick — that is the race being lost').toBeLessThanOrEqual(2_000);
        for (let i = 1; i < SYNC_POLL_DELAYS_MS.length; i++) {
            expect(SYNC_POLL_DELAYS_MS[i], `delay ${i} must not shrink`).toBeGreaterThanOrEqual(SYNC_POLL_DELAYS_MS[i - 1]);
        }
    });
});

describe('living alongside the broadcast listener', () => {
    it('stands down when a broadcast settles the status first', async () => {
        const h = harness('pending', [{ status: 'synced', error: null }]);
        h.reconciler.start();

        // The WebSocket got there before the first scheduled check.
        h.broadcast('synced');
        await vi.advanceTimersByTimeAsync(120_000);

        expect(h.read, 'the broadcast already answered the question').not.toHaveBeenCalled();
        expect(h.reconciler.running).toBe(false);
    });

    it('drops a stale response when a broadcast lands while the request is in flight', async () => {
        const displayed = { status: 'pending' as SyncStatus, error: null as string | null };
        let release: ((s: SyncStatusSnapshot) => void) | undefined;

        const reconciler = createSyncStatusReconciler({
            current: () => displayed.status,
            read: () => new Promise<SyncStatusSnapshot>((resolve) => (release = resolve)),
            apply: (s) => {
                displayed.status = s.status;
                displayed.error = s.error;
            },
            delays: [1_000, 2_000],
        });
        reconciler.start();

        await vi.advanceTimersByTimeAsync(1_000);

        // The broadcast arrives first; the server's answer was read before the job finished.
        displayed.status = 'synced';
        release!({ status: 'pending', error: null });
        await vi.advanceTimersByTimeAsync(0);

        expect(displayed.status, 'an older read must never walk the badge backwards').toBe('synced');
        expect(reconciler.running).toBe(false);
    });
});

describe('lifecycle', () => {
    it('asks for nothing more once stopped', async () => {
        const h = harness('pending', [{ status: 'pending', error: null }]);
        h.reconciler.start();
        h.reconciler.stop();

        await vi.advanceTimersByTimeAsync(600_000);

        expect(h.read).not.toHaveBeenCalled();
        expect(h.reconciler.running).toBe(false);
    });

    it('cannot be restarted after being stopped', async () => {
        const h = harness('pending', [{ status: 'pending', error: null }]);
        h.reconciler.stop();
        h.reconciler.start();

        await vi.advanceTimersByTimeAsync(600_000);

        expect(h.read).not.toHaveBeenCalled();
    });

    it('does not double-schedule when started twice', async () => {
        const h = harness(
            'pending',
            [
                { status: 'pending', error: null },
                { status: 'pending', error: null },
                { status: 'pending', error: null },
            ],
            [1_000, 2_000, 3_000],
        );
        h.reconciler.start();
        h.reconciler.start();

        await vi.advanceTimersByTimeAsync(1_000);
        expect(h.read).toHaveBeenCalledTimes(1);

        // A second start() that armed its own timer would fire it here, at its own first
        // delay, on top of the run already under way — the next legitimate check is at
        // 3_000, not 2_000.
        await vi.advanceTimersByTimeAsync(1_000);
        expect(h.read, 'a second start() must not arm an overlapping timer').toHaveBeenCalledTimes(1);
    });
});

describe('isTerminalSyncStatus', () => {
    it('treats only synced and failed as final', () => {
        expect(isTerminalSyncStatus('synced')).toBe(true);
        expect(isTerminalSyncStatus('failed')).toBe(true);
        expect(isTerminalSyncStatus('pending')).toBe(false);
        expect(isTerminalSyncStatus('syncing')).toBe(false);
    });
});
