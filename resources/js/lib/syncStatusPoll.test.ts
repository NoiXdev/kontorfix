import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import {
    createSyncStatusReconciler,
    isTerminalSyncStatus,
    parseSyncStatusResponse,
    SYNC_POLL_BUDGET_MS,
    SYNC_POLL_DELAYS_MS,
    type SyncStatus,
    type SyncStatusSnapshot,
} from './syncStatusPoll';

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

    // The numbers are pinned against the job this waits for, not against taste.
    // SyncPackage::TIMEOUT is 900s — the alarm on a single run — so a budget shorter than
    // that abandons the case worth the most: a job that fails near its timeout, whose
    // sync_error text is exactly what the operator opened the page to read.
    it('keeps looking for at least as long as one run of the job may take', () => {
        const total = SYNC_POLL_DELAYS_MS.reduce((sum, d) => sum + d, 0);

        expect(SYNC_POLL_BUDGET_MS, "SyncPackage::TIMEOUT, the job's own single-run alarm").toBe(900_000);
        expect(total, 'a shorter budget gives up before the job itself does').toBeGreaterThanOrEqual(SYNC_POLL_BUDGET_MS);
    });

    it('spends that budget on few enough requests to be cheap', () => {
        // SyncPackage::retryUntil() is ~3960s. Covering *that* would mean polling for over
        // an hour from every open tab, which is why the budget stops at one run's timeout
        // and the give-up is announced instead.
        expect(SYNC_POLL_DELAYS_MS.length).toBeLessThanOrEqual(40);
    });

    it('ramps up quickly and never shrinks', () => {
        expect(SYNC_POLL_DELAYS_MS[0], 'the first check has to be quick — that is the race being lost').toBeLessThanOrEqual(2_000);
        for (let i = 1; i < SYNC_POLL_DELAYS_MS.length; i++) {
            expect(SYNC_POLL_DELAYS_MS[i], `delay ${i} must not shrink`).toBeGreaterThanOrEqual(SYNC_POLL_DELAYS_MS[i - 1]);
        }
        expect(Math.max(...SYNC_POLL_DELAYS_MS), 'the steady interval must stay bounded').toBeLessThanOrEqual(60_000);
    });

    it('reports giving up instead of freezing silently', async () => {
        const displayed = { status: 'syncing' as SyncStatus };
        const onGiveUp = vi.fn();
        const reconciler = createSyncStatusReconciler({
            current: () => displayed.status,
            read: async () => ({ status: 'syncing', error: null }),
            apply: (s) => (displayed.status = s.status),
            onGiveUp,
            delays: [1_000, 2_000],
        });
        reconciler.start();

        await vi.advanceTimersByTimeAsync(600_000);

        expect(onGiveUp, 'a badge that stopped updating must say so').toHaveBeenCalledTimes(1);
        expect(reconciler.gaveUp).toBe(true);
    });

    it('does not report giving up when it stopped because it got an answer', async () => {
        const h = harness('pending', [{ status: 'synced', error: null }]);
        const onGiveUp = vi.fn();
        const reconciler = createSyncStatusReconciler({
            current: () => h.displayed.status,
            read: h.read,
            apply: (s) => (h.displayed.status = s.status),
            onGiveUp,
            delays: [1_000, 2_000],
        });
        reconciler.start();

        await vi.advanceTimersByTimeAsync(600_000);

        expect(onGiveUp).not.toHaveBeenCalled();
        expect(reconciler.gaveUp).toBe(false);
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

// Inertia preserves component state across post/put/patch/delete, so the detail page is
// re-rendered with a new status under a reconciler that has already run to completion.
// `restart()` is what stops the badge freezing on the old terminal value after a resync.
describe('restarting for a fresh sync', () => {
    it('polls again after having stopped on a terminal status', async () => {
        const h = harness('pending', [
            { status: 'synced', error: null },
            { status: 'failed', error: 'Repository nicht erreichbar.' },
        ]);
        h.reconciler.start();

        await vi.advanceTimersByTimeAsync(1_000);
        expect(h.displayed.status).toBe('synced');
        expect(h.reconciler.running).toBe(false);

        // The operator clicks "Erneut synchronisieren": the server re-renders the page with
        // the status back at pending, and the page re-seeds and re-arms.
        h.displayed.status = 'pending';
        h.reconciler.restart();

        await vi.advanceTimersByTimeAsync(1_000);
        expect(h.displayed, 'a resynced package must be followed to its new answer').toEqual({
            status: 'failed',
            error: 'Repository nicht erreichbar.',
        });
    });

    it('sends nothing when it is re-armed on a status that is already terminal', async () => {
        const h = harness('synced', []);
        h.reconciler.start();
        h.reconciler.restart();

        await vi.advanceTimersByTimeAsync(600_000);

        expect(h.read).not.toHaveBeenCalled();
    });

    it('gives the new run a full budget and clears the give-up flag', async () => {
        const displayed = { status: 'syncing' as SyncStatus };
        const onGiveUp = vi.fn();
        const reconciler = createSyncStatusReconciler({
            current: () => displayed.status,
            read: async () => ({ status: 'syncing', error: null }),
            apply: (s) => (displayed.status = s.status),
            onGiveUp,
            delays: [1_000, 2_000],
        });
        reconciler.start();
        await vi.advanceTimersByTimeAsync(600_000);
        expect(reconciler.gaveUp).toBe(true);

        reconciler.restart();
        expect(reconciler.gaveUp, 'a fresh run has not given up on anything yet').toBe(false);
        expect(reconciler.running).toBe(true);

        await vi.advanceTimersByTimeAsync(600_000);
        expect(onGiveUp, 'the second run has its own budget and its own notice').toHaveBeenCalledTimes(2);
    });

    it('drops a response still in flight from the run it replaced', async () => {
        const displayed = { status: 'pending' as SyncStatus, error: null as string | null };
        let release: ((s: SyncStatusSnapshot) => void) | undefined;
        let calls = 0;

        const reconciler = createSyncStatusReconciler({
            current: () => displayed.status,
            read: () =>
                new Promise<SyncStatusSnapshot>((resolve) => {
                    calls++;
                    release = resolve;
                }),
            apply: (s) => {
                displayed.status = s.status;
                displayed.error = s.error;
            },
            delays: [1_000, 2_000, 3_000],
        });
        reconciler.start();
        await vi.advanceTimersByTimeAsync(1_000);
        expect(calls).toBe(1);

        // Resync: the page re-seeds to pending and re-arms while the old request is open.
        reconciler.restart();
        release!({ status: 'synced', error: null });
        await vi.advanceTimersByTimeAsync(0);

        expect(displayed.status, 'the answer belongs to the sync that was replaced').toBe('pending');
        expect(reconciler.running).toBe(true);
    });
});

describe('parseSyncStatusResponse', () => {
    it('accepts a well-formed body', () => {
        expect(parseSyncStatusResponse({ status: 'failed', error: 'kaputt' })).toEqual({ status: 'failed', error: 'kaputt' });
    });

    it('normalises a missing error to null', () => {
        expect(parseSyncStatusResponse({ status: 'synced' })).toEqual({ status: 'synced', error: null });
    });

    // A blind cast set `status` to undefined here, which renders an empty pill and — never
    // being terminal — polls out the whole budget. Throwing routes it through the "unknown,
    // try again" path instead, and ends in the give-up notice if it persists.
    it.each([
        ['a body that is not an object', 'nope'],
        ['null', null],
        ['a missing status', { error: null }],
        ['a status outside the enum', { status: 'unknown', error: null }],
        ['a non-string error', { status: 'failed', error: { message: 'kaputt' } }],
    ])('rejects %s', (_label, body) => {
        expect(() => parseSyncStatusResponse(body)).toThrow(/sync-status/);
    });
});
