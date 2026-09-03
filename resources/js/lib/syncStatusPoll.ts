/**
 * Reconciles a package's sync status with the server when the broadcast cannot.
 *
 * The detail page seeds its badge from the server-rendered prop and otherwise only ever
 * changes it when a `PackageSynced` / `PackageSyncFailed` event arrives on the `operator`
 * channel. Creating a package dispatches `SyncPackage` and redirects to that page
 * immediately, so the browser still has to load the page, open the WebSocket, authenticate
 * at `/broadcasting/auth` and subscribe. A small repository syncs faster than that, the
 * event is broadcast to a channel this browser has not joined, Reverb does not replay it,
 * and the badge reads "Wartet" until somebody reloads by hand.
 *
 * Realtime may also be off entirely: `@/echo` only constructs `window.Echo` when a Reverb
 * key was present at asset-build time, and `useOperatorChannel` subscribes only for
 * super-admins. In both cases this poll is the *only* thing that ever corrects the badge,
 * which is why it does not assume a broadcast will eventually arrive.
 *
 * The scheduling lives here, apart from Vue and from `fetch`, because it is the part with
 * rules worth testing: stop on a terminal status, never start on one, back off, give up.
 */

export type SyncStatus = 'pending' | 'syncing' | 'synced' | 'failed';

export interface SyncStatusSnapshot {
    status: SyncStatus;
    error: string | null;
}

/**
 * The backoff. Ten attempts spanning 143 seconds: dense at the start because that is where
 * the missed broadcast lives (the job usually finishes within a second or two of the
 * redirect), then stretching out so a genuinely slow — or stuck — sync costs the server a
 * request every 30s rather than a hot loop. After the last one the page gives up: a sync
 * still running after two and a half minutes is not something polling will resolve, and
 * the operator has a "Erneut synchronisieren" button and a reload.
 */
export const SYNC_POLL_DELAYS_MS: readonly number[] = [1_000, 2_000, 3_000, 5_000, 8_000, 13_000, 21_000, 30_000, 30_000, 30_000];

/** A status the job will not move away from — nothing left to reconcile. */
export function isTerminalSyncStatus(status: string): boolean {
    return status === 'synced' || status === 'failed';
}

export interface SyncStatusReconcilerOptions {
    /**
     * The status the page is displaying right now. Read afresh before every request and
     * again after each response, so a broadcast that lands mid-flight wins and the poll
     * stands down instead of overwriting it with an older read.
     */
    current: () => SyncStatus;
    /** Asks the server. A rejection means "unknown" and costs one attempt, not the run. */
    read: () => Promise<SyncStatusSnapshot>;
    /** Applies a snapshot. Never called with an answer the display has already overtaken. */
    apply: (snapshot: SyncStatusSnapshot) => void;
    /** Overridable for tests. */
    delays?: readonly number[];
}

export interface SyncStatusReconciler {
    /** No-op when the page opened on a terminal status, and when already started. */
    start(): void;
    /** Idempotent, and final — a stopped reconciler cannot be restarted. */
    stop(): void;
    /** Whether another request is still scheduled. */
    readonly running: boolean;
}

export function createSyncStatusReconciler(options: SyncStatusReconcilerOptions): SyncStatusReconciler {
    const delays = options.delays ?? SYNC_POLL_DELAYS_MS;

    let attempt = 0;
    let timer: ReturnType<typeof setTimeout> | undefined;
    let started = false;
    let stopped = false;

    function stop(): void {
        stopped = true;
        if (timer !== undefined) {
            clearTimeout(timer);
            timer = undefined;
        }
    }

    function schedule(): void {
        if (stopped) {
            return;
        }

        // Out of budget, or a broadcast settled it while we were waiting.
        if (attempt >= delays.length || isTerminalSyncStatus(options.current())) {
            stop();

            return;
        }

        timer = setTimeout(tick, delays[attempt++]);
    }

    async function tick(): Promise<void> {
        timer = undefined;

        if (stopped || isTerminalSyncStatus(options.current())) {
            stop();

            return;
        }

        try {
            const snapshot = await options.read();

            // Unmounted, or a broadcast beat the response home. The broadcast is the newer
            // fact either way, so the answer is dropped rather than regressing the badge.
            if (stopped || isTerminalSyncStatus(options.current())) {
                stop();

                return;
            }

            options.apply(snapshot);
        } catch {
            // A hiccup (offline, 500, an expired session) is not a reason to stop looking;
            // the attempt is spent, so a persistent failure still runs out of budget.
        }

        // No explicit "was that terminal?" branch: `schedule()` re-reads the displayed
        // status, which by now includes the snapshot just applied, and stands down there.
        schedule();
    }

    return {
        start(): void {
            if (started || stopped) {
                return;
            }
            started = true;

            // No terminal check here either: `schedule()` stands down without arming a
            // timer when the status is already terminal, so a page rendered with the final
            // answer never sends a request at all.
            schedule();
        },
        stop,
        get running(): boolean {
            return started && !stopped;
        },
    };
}
